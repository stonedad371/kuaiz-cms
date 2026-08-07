<?php
declare(strict_types=1);

/** Dependency-free local identity and session service for Kuaiz CMS. */
final class KuaizCmsAuth
{
    private const SESSION_TTL_SECONDS = 43200;
    private const SESSION_TOUCH_SECONDS = 300;
    private const LOGIN_WINDOW_SECONDS = 900;
    private const MAX_FAILED_ATTEMPTS = 8;
    private const MAX_ACTIVE_SESSIONS = 20;
    private const MINIMUM_PASSWORD_BYTES = 20;
    private const DUMMY_PASSWORD_HASH =
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public static function provisionSetupToken(PDO $pdo): string
    {
        self::transactionAvailable($pdo);
        $token = bin2hex(random_bytes(32));
        $now = time();
        $pdo->beginTransaction();
        try {
            if ((int)$pdo->query('SELECT COUNT(*) FROM cms_users')->fetchColumn() !== 0) {
                throw new RuntimeException('cms_auth_already_initialized');
            }
            $pdo->prepare(<<<'SQL'
INSERT INTO cms_meta(key,value,updated_at)
VALUES('setup_token_sha256',:value,:updated_at)
ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at
SQL)->execute([':value' => hash('sha256', $token), ':updated_at' => $now]);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }
        return $token;
    }

    public static function ensureInitialAdmin(
        PDO $pdo,
        string $username,
        string $displayName,
        string $password,
        string $setupToken
    ): array {
        $username = self::username($username);
        $displayName = self::displayName($displayName);
        self::password($password);
        self::transactionAvailable($pdo);
        $now = time();
        $pdo->beginTransaction();
        try {
            if ((int)$pdo->query('SELECT COUNT(*) FROM cms_users')->fetchColumn() !== 0) {
                throw new RuntimeException('cms_auth_already_initialized');
            }
            $tokenStatement = $pdo->prepare(
                "SELECT value FROM cms_meta WHERE key='setup_token_sha256'"
            );
            $tokenStatement->execute();
            $expectedTokenHash = $tokenStatement->fetchColumn();
            if (!is_string($expectedTokenHash)
                || !preg_match('/^[a-f0-9]{64}$/D', $setupToken)
                || !hash_equals($expectedTokenHash, hash('sha256', $setupToken))) {
                throw new RuntimeException('cms_auth_setup_token_invalid');
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($passwordHash) || $passwordHash === '') {
                throw new RuntimeException('cms_auth_password_hash_failed');
            }
            $pdo->prepare(<<<'SQL'
INSERT INTO cms_users(
  username,display_name,password_hash,role,status,created_at,updated_at,last_login_at)
VALUES(:username,:display_name,:password_hash,'admin','active',:created_at,:updated_at,NULL)
SQL)->execute([
                ':username' => $username,
                ':display_name' => $displayName,
                ':password_hash' => $passwordHash,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $userId = (int)$pdo->lastInsertId();
            $pdo->exec("DELETE FROM cms_meta WHERE key='setup_token_sha256'");
            self::audit(
                $pdo,
                'user:' . $userId,
                'auth.initial_admin_created',
                'user',
                (string)$userId,
                ['role' => 'admin', 'username' => $username],
                $now
            );
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }
        return self::publicUser([
            'id' => $userId,
            'username' => $username,
            'display_name' => $displayName,
            'role' => 'admin',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
            'last_login_at' => null,
        ]);
    }

    public static function login(
        PDO $pdo,
        string $username,
        string $password,
        string $clientKey
    ): array {
        $rawUsername = trim($username);
        $normalizedUsername = self::tryUsername($rawUsername);
        $clientHash = self::clientHash($clientKey);
        $usernameHash = hash('sha256', $normalizedUsername ?? strtolower($rawUsername));
        $now = time();
        self::prune($pdo, $now);
        if (self::failedAttempts($pdo, $usernameHash, $clientHash, $now) >= self::MAX_FAILED_ATTEMPTS) {
            self::audit(
                $pdo,
                'auth:anonymous',
                'auth.login_rate_limited',
                'authentication',
                $clientHash,
                ['username_hash' => $usernameHash],
                $now
            );
            throw new RuntimeException('cms_auth_rate_limited');
        }

        $user = null;
        if ($normalizedUsername !== null) {
            $statement = $pdo->prepare(
                'SELECT * FROM cms_users WHERE username=:username LIMIT 1'
            );
            $statement->execute([':username' => $normalizedUsername]);
            $candidate = $statement->fetch();
            if (is_array($candidate)) {
                $user = $candidate;
            }
        }
        $passwordHash = is_array($user)
            ? (string)$user['password_hash'] : self::DUMMY_PASSWORD_HASH;
        $valid = password_verify($password, $passwordHash)
            && is_array($user) && $user['status'] === 'active';
        self::recordAttempt($pdo, $usernameHash, $clientHash, $valid, $now);
        if (!$valid) {
            throw new RuntimeException('cms_auth_credentials_invalid');
        }

        $token = bin2hex(random_bytes(32));
        $csrfToken = bin2hex(random_bytes(32));
        $expiresAt = $now + self::SESSION_TTL_SECONDS;
        self::transactionAvailable($pdo);
        $pdo->beginTransaction();
        try {
            if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                if (!is_string($newHash) || $newHash === '') {
                    throw new RuntimeException('cms_auth_password_hash_failed');
                }
                $pdo->prepare(
                    'UPDATE cms_users SET password_hash=:password_hash,updated_at=:updated_at '
                    . 'WHERE id=:user_id'
                )->execute([
                    ':password_hash' => $newHash,
                    ':updated_at' => $now,
                    ':user_id' => $user['id'],
                ]);
            }
            $pdo->prepare(
                'UPDATE cms_users SET last_login_at=:last_login_at WHERE id=:user_id'
            )->execute([':last_login_at' => $now, ':user_id' => $user['id']]);
            $pdo->prepare(<<<'SQL'
INSERT INTO cms_sessions(
  token_hash,user_id,csrf_token_hash,created_at,expires_at,last_seen_at)
VALUES(:token_hash,:user_id,:csrf_token_hash,:created_at,:expires_at,:last_seen_at)
SQL)->execute([
                ':token_hash' => hash('sha256', $token),
                ':user_id' => $user['id'],
                ':csrf_token_hash' => hash('sha256', $csrfToken),
                ':created_at' => $now,
                ':expires_at' => $expiresAt,
                ':last_seen_at' => $now,
            ]);
            self::trimSessions($pdo, (int)$user['id']);
            self::audit(
                $pdo,
                'user:' . $user['id'],
                'auth.login_succeeded',
                'session',
                hash('sha256', $token),
                [],
                $now
            );
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }
        $user['last_login_at'] = $now;
        return [
            'token' => $token,
            'csrf_token' => $csrfToken,
            'expires_at' => $expiresAt,
            'user' => self::publicUser($user),
        ];
    }

    public static function session(PDO $pdo, string $token, bool $touch = true): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $now = time();
        $tokenHash = hash('sha256', $token);
        $statement = $pdo->prepare(<<<'SQL'
SELECT s.token_hash,s.csrf_token_hash,s.created_at,s.expires_at,s.last_seen_at,
       u.id,u.username,u.display_name,u.role,u.status,u.created_at AS user_created_at,
       u.updated_at,u.last_login_at
FROM cms_sessions s
JOIN cms_users u ON u.id=s.user_id AND u.status='active'
WHERE s.token_hash=:token_hash AND s.expires_at>:now
SQL);
        $statement->execute([':token_hash' => $tokenHash, ':now' => $now]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        if ($touch && (int)$row['last_seen_at'] <= $now - self::SESSION_TOUCH_SECONDS) {
            $pdo->prepare(
                'UPDATE cms_sessions SET last_seen_at=:last_seen_at '
                . 'WHERE token_hash=:token_hash AND expires_at>:now'
            )->execute([
                ':last_seen_at' => $now,
                ':token_hash' => $tokenHash,
                ':now' => $now,
            ]);
            $row['last_seen_at'] = $now;
        }
        return [
            'token_hash' => (string)$row['token_hash'],
            'csrf_token_hash' => (string)$row['csrf_token_hash'],
            'created_at' => (int)$row['created_at'],
            'expires_at' => (int)$row['expires_at'],
            'last_seen_at' => (int)$row['last_seen_at'],
            'user' => self::publicUser([
                'id' => $row['id'],
                'username' => $row['username'],
                'display_name' => $row['display_name'],
                'role' => $row['role'],
                'status' => $row['status'],
                'created_at' => $row['user_created_at'],
                'updated_at' => $row['updated_at'],
                'last_login_at' => $row['last_login_at'],
            ]),
        ];
    }

    public static function authorize(PDO $pdo, string $token, array $allowedRoles): array
    {
        $session = self::session($pdo, $token);
        if ($session === null) {
            throw new RuntimeException('cms_auth_session_invalid');
        }
        $allowedRoles = array_values(array_unique($allowedRoles));
        if ($allowedRoles === [] || array_diff($allowedRoles, ['admin', 'editor', 'viewer']) !== []) {
            throw new RuntimeException('cms_auth_roles_invalid');
        }
        if (!in_array($session['user']['role'], $allowedRoles, true)) {
            throw new RuntimeException('cms_auth_role_forbidden');
        }
        return $session;
    }

    public static function authorizeMutation(
        PDO $pdo,
        string $token,
        string $csrfToken,
        array $allowedRoles
    ): array {
        $session = self::authorize($pdo, $token, $allowedRoles);
        self::verifyCsrf($session, $csrfToken);
        return $session;
    }

    public static function verifyCsrf(array $session, string $csrfToken): void
    {
        if (!isset($session['csrf_token_hash'])
            || !preg_match('/^[a-f0-9]{64}$/D', $csrfToken)
            || !hash_equals((string)$session['csrf_token_hash'], hash('sha256', $csrfToken))) {
            throw new RuntimeException('cms_auth_csrf_invalid');
        }
    }

    public static function logout(PDO $pdo, string $token): void
    {
        $session = self::session($pdo, $token, false);
        if ($session === null) {
            return;
        }
        $pdo->prepare('DELETE FROM cms_sessions WHERE token_hash=:token_hash')->execute([
            ':token_hash' => $session['token_hash'],
        ]);
        self::audit(
            $pdo,
            'user:' . $session['user']['id'],
            'auth.logout',
            'session',
            (string)$session['token_hash'],
            [],
            time()
        );
    }

    public static function createUser(
        PDO $pdo,
        array $actorSession,
        string $username,
        string $displayName,
        string $password,
        string $role
    ): array {
        self::requireAdmin($actorSession);
        $username = self::username($username);
        $displayName = self::displayName($displayName);
        self::password($password);
        self::role($role);
        $now = time();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new RuntimeException('cms_auth_password_hash_failed');
        }
        self::transactionAvailable($pdo);
        $pdo->beginTransaction();
        try {
            $pdo->prepare(<<<'SQL'
INSERT INTO cms_users(
  username,display_name,password_hash,role,status,created_at,updated_at,last_login_at)
VALUES(:username,:display_name,:password_hash,:role,'active',:created_at,:updated_at,NULL)
SQL)->execute([
                ':username' => $username,
                ':display_name' => $displayName,
                ':password_hash' => $passwordHash,
                ':role' => $role,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $userId = (int)$pdo->lastInsertId();
            self::audit(
                $pdo,
                'user:' . $actorSession['user']['id'],
                'auth.user_created',
                'user',
                (string)$userId,
                ['role' => $role, 'username' => $username],
                $now
            );
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            if ($error instanceof PDOException && (string)$error->getCode() === '23000') {
                throw new RuntimeException('cms_auth_username_exists', 0, $error);
            }
            throw $error;
        }
        return self::publicUser([
            'id' => $userId,
            'username' => $username,
            'display_name' => $displayName,
            'role' => $role,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
            'last_login_at' => null,
        ]);
    }

    public static function updateUserAccess(
        PDO $pdo,
        array $actorSession,
        int $userId,
        string $role,
        string $status
    ): array {
        self::requireAdmin($actorSession);
        self::role($role);
        if ($userId < 1 || !in_array($status, ['active', 'disabled'], true)) {
            throw new RuntimeException('cms_auth_user_update_invalid');
        }
        if ((int)$actorSession['user']['id'] === $userId
            && ($role !== 'admin' || $status !== 'active')) {
            throw new RuntimeException('cms_auth_self_lockout_forbidden');
        }
        $statement = $pdo->prepare('SELECT * FROM cms_users WHERE id=:user_id');
        $statement->execute([':user_id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            throw new RuntimeException('cms_auth_user_not_found');
        }
        if ($user['role'] === 'admin' && $user['status'] === 'active'
            && ($role !== 'admin' || $status !== 'active')) {
            $activeAdmins = (int)$pdo->query(
                "SELECT COUNT(*) FROM cms_users WHERE role='admin' AND status='active'"
            )->fetchColumn();
            if ($activeAdmins <= 1) {
                throw new RuntimeException('cms_auth_last_admin_forbidden');
            }
        }
        $now = time();
        self::transactionAvailable($pdo);
        $pdo->beginTransaction();
        try {
            $pdo->prepare(<<<'SQL'
UPDATE cms_users SET role=:role,status=:status,updated_at=:updated_at WHERE id=:user_id
SQL)->execute([
                ':role' => $role,
                ':status' => $status,
                ':updated_at' => $now,
                ':user_id' => $userId,
            ]);
            if ($status === 'disabled' || $role !== $user['role']) {
                $pdo->prepare('DELETE FROM cms_sessions WHERE user_id=:user_id')->execute([
                    ':user_id' => $userId,
                ]);
            }
            self::audit(
                $pdo,
                'user:' . $actorSession['user']['id'],
                'auth.user_access_updated',
                'user',
                (string)$userId,
                ['role' => $role, 'status' => $status],
                $now
            );
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }
        $user['role'] = $role;
        $user['status'] = $status;
        $user['updated_at'] = $now;
        return self::publicUser($user);
    }

    public static function changePassword(
        PDO $pdo,
        array $session,
        string $currentPassword,
        string $newPassword
    ): void {
        self::password($newPassword);
        $userId = (int)($session['user']['id'] ?? 0);
        $statement = $pdo->prepare(
            "SELECT password_hash FROM cms_users WHERE id=:user_id AND status='active'"
        );
        $statement->execute([':user_id' => $userId]);
        $currentHash = $statement->fetchColumn();
        if (!is_string($currentHash) || !password_verify($currentPassword, $currentHash)) {
            throw new RuntimeException('cms_auth_current_password_invalid');
        }
        if (password_verify($newPassword, $currentHash)) {
            throw new RuntimeException('cms_auth_password_unchanged');
        }
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($newHash) || $newHash === '') {
            throw new RuntimeException('cms_auth_password_hash_failed');
        }
        $now = time();
        self::transactionAvailable($pdo);
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE cms_users SET password_hash=:password_hash,updated_at=:updated_at '
                . 'WHERE id=:user_id'
            )->execute([
                ':password_hash' => $newHash,
                ':updated_at' => $now,
                ':user_id' => $userId,
            ]);
            $pdo->prepare('DELETE FROM cms_sessions WHERE user_id=:user_id')->execute([
                ':user_id' => $userId,
            ]);
            self::audit(
                $pdo,
                'user:' . $userId,
                'auth.password_changed',
                'user',
                (string)$userId,
                ['all_sessions_revoked' => true],
                $now
            );
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }
    }

    public static function users(PDO $pdo, array $actorSession): array
    {
        self::requireAdmin($actorSession);
        $rows = $pdo->query(<<<'SQL'
SELECT id,username,display_name,role,status,created_at,updated_at,last_login_at
FROM cms_users ORDER BY id ASC
SQL)->fetchAll();
        return array_map([self::class, 'publicUser'], $rows);
    }

    private static function prune(PDO $pdo, int $now): void
    {
        $pdo->prepare('DELETE FROM cms_sessions WHERE expires_at<=:now')->execute([':now' => $now]);
        $pdo->prepare('DELETE FROM cms_login_attempts WHERE attempted_at<:cutoff')->execute([
            ':cutoff' => $now - 2592000,
        ]);
    }

    private static function failedAttempts(
        PDO $pdo,
        string $usernameHash,
        string $clientHash,
        int $now
    ): int {
        $statement = $pdo->prepare(<<<'SQL'
SELECT COUNT(*) FROM cms_login_attempts
WHERE successful=0 AND attempted_at>=:window_start
  AND (username_hash=:username_hash OR client_hash=:client_hash)
SQL);
        $statement->execute([
            ':window_start' => $now - self::LOGIN_WINDOW_SECONDS,
            ':username_hash' => $usernameHash,
            ':client_hash' => $clientHash,
        ]);
        return (int)$statement->fetchColumn();
    }

    private static function recordAttempt(
        PDO $pdo,
        string $usernameHash,
        string $clientHash,
        bool $successful,
        int $now
    ): void {
        $pdo->prepare(<<<'SQL'
INSERT INTO cms_login_attempts(username_hash,client_hash,successful,attempted_at)
VALUES(:username_hash,:client_hash,:successful,:attempted_at)
SQL)->execute([
            ':username_hash' => $usernameHash,
            ':client_hash' => $clientHash,
            ':successful' => $successful ? 1 : 0,
            ':attempted_at' => $now,
        ]);
    }

    private static function trimSessions(PDO $pdo, int $userId): void
    {
        $pdo->prepare(<<<'SQL'
DELETE FROM cms_sessions
WHERE user_id=:user_id AND token_hash NOT IN (
  SELECT token_hash FROM cms_sessions WHERE user_id=:user_id_keep
  ORDER BY created_at DESC,token_hash DESC LIMIT 20
)
SQL)->execute([':user_id' => $userId, ':user_id_keep' => $userId]);
    }

    private static function username(string $username): string
    {
        $normalized = self::tryUsername($username);
        if ($normalized === null) {
            throw new RuntimeException('cms_auth_username_invalid');
        }
        return $normalized;
    }

    private static function tryUsername(string $username): ?string
    {
        $username = strtolower(trim($username));
        if (strlen($username) < 3 || strlen($username) > 128
            || !preg_match('/^[a-z0-9][a-z0-9_.+@-]*$/D', $username)) {
            return null;
        }
        if (str_contains($username, '@') && filter_var($username, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return $username;
    }

    private static function displayName(string $displayName): string
    {
        $displayName = trim($displayName);
        if ($displayName === '' || strlen($displayName) > 320
            || !preg_match('//u', $displayName)
            || preg_match('/[\x00-\x1f\x7f]/', $displayName)) {
            throw new RuntimeException('cms_auth_display_name_invalid');
        }
        return $displayName;
    }

    private static function password(string $password): void
    {
        if (strlen($password) < self::MINIMUM_PASSWORD_BYTES || strlen($password) > 1024
            || !preg_match('//u', $password) || trim($password) === '') {
            throw new RuntimeException('cms_auth_password_invalid');
        }
    }

    private static function clientHash(string $clientKey): string
    {
        if ($clientKey === '' || strlen($clientKey) > 512
            || preg_match('/[\x00-\x1f\x7f]/', $clientKey)) {
            throw new RuntimeException('cms_auth_client_key_invalid');
        }
        return hash('sha256', $clientKey);
    }

    private static function role(string $role): void
    {
        if (!in_array($role, ['admin', 'editor', 'viewer'], true)) {
            throw new RuntimeException('cms_auth_role_invalid');
        }
    }

    private static function requireAdmin(array $session): void
    {
        if (($session['user']['role'] ?? null) !== 'admin'
            || !is_int($session['user']['id'] ?? null)) {
            throw new RuntimeException('cms_auth_admin_required');
        }
    }

    private static function publicUser(array $user): array
    {
        return [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'display_name' => (string)$user['display_name'],
            'role' => (string)$user['role'],
            'status' => (string)$user['status'],
            'created_at' => (int)$user['created_at'],
            'updated_at' => (int)$user['updated_at'],
            'last_login_at' => $user['last_login_at'] === null
                ? null : (int)$user['last_login_at'],
        ];
    }

    private static function transactionAvailable(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            throw new RuntimeException('cms_auth_nested_transaction_forbidden');
        }
    }

    private static function audit(
        PDO $pdo,
        string $actor,
        string $action,
        string $resourceType,
        string $resourceId,
        array $details,
        int $now
    ): void {
        $body = json_encode(
            $details,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $pdo->prepare(<<<'SQL'
INSERT INTO cms_audit_logs(
  actor,action,resource_type,resource_id,details_json,created_at)
VALUES(:actor,:action,:resource_type,:resource_id,:details_json,:created_at)
SQL)->execute([
            ':actor' => $actor,
            ':action' => $action,
            ':resource_type' => $resourceType,
            ':resource_id' => $resourceId,
            ':details_json' => $body,
            ':created_at' => $now,
        ]);
    }
}
