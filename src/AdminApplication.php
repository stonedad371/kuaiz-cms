<?php
declare(strict_types=1);

/** Minimal server-rendered admin application; no framework and no client-side JavaScript. */
final class KuaizCmsAdminApplication
{
    private const SESSION_COOKIE = '__Host-kuaiz_cms_session';
    private const CSRF_COOKIE = '__Host-kuaiz_cms_csrf';
    private const LOGIN_CSRF_COOKIE = '__Host-kuaiz_cms_login_csrf';
    private const MAX_FORM_BYTES = 262144;
    private const MAX_UPLOAD_REQUEST_BYTES = 13631488;
    private static bool $secureCookies = true;
    private static string $sessionCookie = self::SESSION_COOKIE;
    private static string $csrfCookie = self::CSRF_COOKIE;
    private static string $loginCsrfCookie = self::LOGIN_CSRF_COOKIE;

    public static function handle(
        PDO $pdo,
        array $server,
        array $query,
        array $post,
        array $cookies,
        array $files = [],
        ?string $storageRoot = null
    ): array {
        self::configureCookies($server);
        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        $path = parse_url((string)($server['REQUEST_URI'] ?? '/admin'), PHP_URL_PATH);
        if (!is_string($path)) {
            return self::error(400, '请求地址无效。');
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            return self::error(405, '此操作不受支持。', ['Allow' => 'GET, POST']);
        }
        $contentLength = (int)($server['CONTENT_LENGTH'] ?? 0);
        $maximumRequestBytes = $path === '/admin/media/upload'
            ? self::MAX_UPLOAD_REQUEST_BYTES : self::MAX_FORM_BYTES;
        if ($contentLength < 0 || $contentLength > $maximumRequestBytes) {
            return self::error(413, '提交内容过大，请精简后重试。');
        }

        $hasUsers = (int)$pdo->query('SELECT COUNT(*) FROM cms_users')->fetchColumn() > 0;
        if (!$hasUsers) {
            if ($method === 'POST' && $path === '/admin/setup') {
                return self::setup($pdo, $server, $post);
            }
            if ($method === 'GET' && in_array($path, ['/admin', '/admin/setup'], true)) {
                return self::setupPage();
            }
            return self::redirect('/admin');
        }

        if ($method === 'POST' && $path === '/admin/login') {
            return self::login($pdo, $server, $post, $cookies);
        }
        if ($method === 'GET' && $path === '/admin/login') {
            return self::loginPage();
        }

        $token = self::cookie($cookies, self::$sessionCookie);
        $csrfToken = self::cookie($cookies, self::$csrfCookie);
        $session = $token === null ? null : KuaizCmsAuth::session($pdo, $token);
        if ($session === null || $csrfToken === null) {
            return self::loginPage('请先登录。', 401, self::expiredCookies());
        }
        try {
            KuaizCmsAuth::verifyCsrf($session, $csrfToken);
        } catch (RuntimeException $ignored) {
            return self::loginPage('登录状态已失效，请重新登录。', 401, self::expiredCookies());
        }

        try {
            if ($method === 'POST') {
                $submittedCsrf = self::postText($post, '_csrf', 64);
                $session = KuaizCmsAuth::authorizeMutation(
                    $pdo,
                    $token,
                    $submittedCsrf,
                    self::rolesForMutation($path)
                );
            }
            if ($method === 'POST' && $path === '/admin/logout') {
                KuaizCmsAuth::logout($pdo, $token);
                return self::loginPage('已经安全退出。', 200, self::expiredCookies());
            }
            if ($method === 'POST' && $path === '/admin/content/save') {
                return self::saveContent($pdo, $post, $session, $csrfToken);
            }
            if ($method === 'POST' && $path === '/admin/content/state') {
                return self::changeContentState($pdo, $post, $session);
            }
            if ($method === 'POST' && $path === '/admin/media/upload') {
                return self::uploadMedia(
                    $pdo,
                    $post,
                    $files,
                    $session,
                    self::requiredStorageRoot($storageRoot)
                );
            }
            if ($method === 'POST' && $path === '/admin/media/update') {
                return self::updateMedia($pdo, $post, $session);
            }
            if ($method === 'POST' && $path === '/admin/media/state') {
                return self::changeMediaState($pdo, $post, $session);
            }
            if ($method === 'POST' && $path === '/admin/settings') {
                return self::saveSettings($pdo, $post, $session);
            }
            if ($method === 'POST' && $path === '/admin/themes/activate') {
                return self::activateTheme($pdo, $post, $session);
            }
            if ($method === 'GET' && $path === '/admin/content/new') {
                KuaizCmsAuth::authorize($pdo, $token, ['admin', 'editor']);
                return self::newContentPage($pdo, $query, $session, $csrfToken);
            }
            if ($method === 'GET' && $path === '/admin/content/edit') {
                KuaizCmsAuth::authorize($pdo, $token, ['admin', 'editor']);
                return self::editContentPage($pdo, $query, $session, $csrfToken);
            }
            if ($method === 'GET' && $path === '/admin/content/history') {
                KuaizCmsAuth::authorize($pdo, $token, ['admin', 'editor', 'viewer']);
                return self::historyPage($pdo, $query, $session, $csrfToken);
            }
            if ($method === 'GET' && $path === '/admin/media/file') {
                KuaizCmsAuth::authorize($pdo, $token, ['admin', 'editor', 'viewer']);
                return self::mediaFile(
                    $pdo,
                    $query,
                    self::requiredStorageRoot($storageRoot)
                );
            }
            if ($method === 'GET' && $path === '/admin/media') {
                KuaizCmsAuth::authorize($pdo, $token, ['admin', 'editor', 'viewer']);
                return self::mediaPage($pdo, $query, $session, $csrfToken);
            }
            if ($method === 'GET' && $path === '/admin/settings') {
                KuaizCmsAuth::authorize($pdo, $token, ['admin']);
                return self::settingsPage(
                    $pdo,
                    $session,
                    $csrfToken,
                    self::queryText($query, 'welcome', 1, true) === '1'
                );
            }
            if ($method === 'GET' && $path === '/admin/themes') {
                KuaizCmsAuth::authorize($pdo, $token, ['admin']);
                return self::themesPage(
                    $pdo,
                    $session,
                    $csrfToken,
                    self::queryText($query, 'welcome', 1, true) === '1'
                );
            }
            if ($method === 'GET' && $path === '/admin') {
                KuaizCmsAuth::authorize($pdo, $token, ['admin', 'editor', 'viewer']);
                return self::dashboard($pdo, $query, $session, $csrfToken);
            }
            return self::error(404, '没有找到这个后台页面。');
        } catch (RuntimeException $error) {
            return self::domainError($error, $session, $csrfToken);
        }
    }

    private static function setup(PDO $pdo, array $server, array $post): array
    {
        try {
            $username = self::postText($post, 'username', 128);
            $displayName = self::postText($post, 'display_name', 320);
            $password = self::postText($post, 'password', 1024, false);
            $confirmation = self::postText($post, 'password_confirmation', 1024, false);
            $setupToken = strtolower(self::postText($post, 'setup_token', 64));
            if (!hash_equals($password, $confirmation)) {
                throw new RuntimeException('cms_admin_password_confirmation_mismatch');
            }
            KuaizCmsAuth::ensureInitialAdmin(
                $pdo,
                $username,
                $displayName,
                $password,
                $setupToken
            );
            $login = KuaizCmsAuth::login(
                $pdo,
                $username,
                $password,
                self::clientKey($server)
            );
            $destination = KuaizCmsSiteSettings::get($pdo) === null
                ? '/admin/settings?welcome=1' : '/admin';
            return self::redirect($destination, self::loginCookies($login));
        } catch (RuntimeException $error) {
            return self::setupPage(self::message($error), 422);
        }
    }

    private static function login(
        PDO $pdo,
        array $server,
        array $post,
        array $cookies
    ): array
    {
        try {
            $cookieToken = self::cookie($cookies, self::$loginCsrfCookie);
            $submittedToken = self::postText($post, '_login_csrf', 64);
            if ($cookieToken === null || !hash_equals($cookieToken, $submittedToken)) {
                throw new RuntimeException('cms_auth_login_csrf_invalid');
            }
            $login = KuaizCmsAuth::login(
                $pdo,
                self::postText($post, 'username', 128),
                self::postText($post, 'password', 1024, false),
                self::clientKey($server)
            );
            $destination = KuaizCmsSiteSettings::get($pdo) === null
                ? '/admin/settings?welcome=1' : '/admin';
            return self::redirect($destination, self::loginCookies($login));
        } catch (RuntimeException $error) {
            $status = $error->getMessage() === 'cms_auth_rate_limited' ? 429 : 401;
            return self::loginPage(self::message($error), $status, self::expiredCookies());
        }
    }

    private static function dashboard(
        PDO $pdo,
        array $query,
        array $session,
        string $csrfToken
    ): array {
        $status = self::queryText($query, 'status', 16, true);
        if ($status === '') {
            $status = null;
        }
        $onboardingReady = self::queryText($query, 'onboarding', 8, true) === 'ready';
        $types = KuaizCmsContentRepository::contentTypes($pdo);
        $entries = KuaizCmsContentRepository::adminEntries($pdo, null, null, $status, 100, 0);
        $canEdit = in_array($session['user']['role'], ['admin', 'editor'], true);
        $typeLinks = '';
        foreach ($types as $type) {
            $typeLinks .= '<li><span>' . self::h($type['label']) . '</span>';
            if ($canEdit) {
                $typeLinks .= '<a class="button secondary" href="/admin/content/new?extension='
                    . rawurlencode($type['extension_id']) . '&amp;type=' . rawurlencode($type['type_key'])
                    . '">新建</a>';
            }
            $typeLinks .= '</li>';
        }
        if ($typeLinks === '') {
            $typeLinks = '<li class="empty">尚未安装内容扩展，后台当前没有可编辑的内容类型。</li>';
        }
        $rows = '';
        foreach ($entries as $entry) {
            $title = self::entryTitle($entry);
            $statusLabel = self::statusLabel($entry['status']);
            $changed = $entry['has_unpublished_changes'] ? '<span class="flag">有未发布修改</span>' : '';
            $actions = '<a href="/admin/content/history?id=' . $entry['id'] . '">历史</a>';
            if ($canEdit) {
                $actions = '<a href="/admin/content/edit?id=' . $entry['id'] . '">编辑</a> ' . $actions;
            }
            $rows .= '<tr><td><strong>' . self::h($title) . '</strong><small>'
                . self::h($entry['slug']) . '</small></td><td>'
                . self::h($entry['type_label']) . '</td><td><span class="status">'
                . self::h($statusLabel) . '</span>' . $changed . '</td><td>v'
                . $entry['current_version'] . '</td><td class="actions">' . $actions . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="empty">当前筛选下还没有内容。</td></tr>';
        }
        $filter = '<nav class="filters"><a href="/admin">全部</a>'
            . '<a href="/admin?status=draft">草稿</a>'
            . '<a href="/admin?status=published">已发布</a>'
            . '<a href="/admin?status=archived">已归档</a></nav>';
        $onboardingNotice = $onboardingReady
            ? '<div class="notice"><b>网站基础设置已保存。</b> 现在可以创建第一条内容；'
                . '确认页面、文字和图片全部准备好以后，再到网站设置中开启搜索引擎收录。</div>'
            : '';
        $body = '<section class="hero"><div><p class="eyebrow">内容总览</p><h1>管理网站内容</h1>'
            . '<p>草稿修改不会提前出现在网站上，发布后才会替换线上版本。</p>'
            . '<p class="hero-action"><a class="button secondary" href="/admin/media">打开素材库</a> '
            . ($session['user']['role'] === 'admin'
                ? '<a class="button secondary" href="/admin/themes">网站风格</a> '
                    . '<a class="button secondary" href="/admin/settings">网站设置</a>' : '')
            . '</p></div>'
            . self::userCard($session, $csrfToken) . '</section>' . $onboardingNotice
            . '<section class="panel"><div class="panel-head"><h2>可用内容类型</h2></div><ul class="types">'
            . $typeLinks . '</ul></section>'
            . '<section class="panel"><div class="panel-head"><h2>内容</h2>' . $filter . '</div>'
            . '<div class="table-wrap"><table><thead><tr><th>内容</th><th>类型</th><th>状态</th>'
            . '<th>版本</th><th>操作</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
        return self::page('内容管理', $body);
    }

    private static function newContentPage(
        PDO $pdo,
        array $query,
        array $session,
        string $csrfToken
    ): array {
        $extensionId = self::queryText($query, 'extension', 120);
        $typeKey = self::queryText($query, 'type', 40);
        $type = self::findContentType($pdo, $extensionId, $typeKey);
        return self::editorPage(
            $type,
            null,
            $session,
            $csrfToken,
            '',
            KuaizCmsMediaRepository::items($pdo)
        );
    }

    private static function editContentPage(
        PDO $pdo,
        array $query,
        array $session,
        string $csrfToken
    ): array {
        $entry = KuaizCmsContentRepository::adminEntry($pdo, self::queryId($query, 'id'));
        $type = [
            'extension_id' => $entry['extension_id'],
            'type_key' => $entry['type_key'],
            'label' => $entry['type_label'],
            'schema' => $entry['schema'],
        ];
        return self::editorPage(
            $type,
            $entry,
            $session,
            $csrfToken,
            '',
            KuaizCmsMediaRepository::items($pdo)
        );
    }

    private static function editorPage(
        array $type,
        ?array $entry,
        array $session,
        string $csrfToken,
        string $error = '',
        array $mediaItems = []
    ): array {
        $persisted = $entry !== null && isset($entry['id'])
            && is_int($entry['id']) && $entry['id'] > 0;
        $fields = '';
        foreach ($type['schema']['fields'] as $field) {
            $key = (string)$field['key'];
            $value = $entry['payload'][$key] ?? null;
            $fields .= self::field($field, $value, $mediaItems);
        }
        $entryId = $persisted ? (string)$entry['id'] : '';
        $slug = $entry === null ? '' : (string)$entry['slug'];
        $status = $persisted ? self::statusLabel($entry['status']) : '新内容';
        $errorBody = $error === '' ? '' : '<div class="notice error">' . self::h($error) . '</div>';
        $buttons = '<button class="button secondary" type="submit" name="intent" value="draft">保存草稿</button>'
            . '<button class="button" type="submit" name="intent" value="publish">保存并发布</button>';
        $stateActions = '';
        if ($persisted) {
            if ($entry['status'] === 'archived') {
                $operations = [['restore', '恢复为草稿']];
            } elseif ($entry['status'] === 'published') {
                $operations = [['unpublish', '下线'], ['archive', '归档']];
            } else {
                $operations = [['publish', '发布当前草稿'], ['archive', '归档']];
            }
            foreach ($operations as [$operation, $label]) {
                $stateActions .= '<form method="post" action="/admin/content/state">'
                    . self::hidden('_csrf', $csrfToken) . self::hidden('id', (string)$entry['id'])
                    . self::hidden('operation', $operation)
                    . '<button class="text-button" type="submit">' . self::h($label) . '</button></form>';
            }
        }
        $body = '<section class="hero compact"><div><p class="eyebrow">' . self::h($status)
            . '</p><h1>' . self::h($persisted ? '编辑' . $type['label'] : '新建' . $type['label'])
            . '</h1><p><a href="/admin">← 返回内容列表</a></p></div>'
            . self::userCard($session, $csrfToken) . '</section>' . $errorBody
            . '<section class="panel editor"><form method="post" action="/admin/content/save">'
            . self::hidden('_csrf', $csrfToken) . self::hidden('entry_id', $entryId)
            . self::hidden('extension_id', (string)$type['extension_id'])
            . self::hidden('type_key', (string)$type['type_key'])
            . '<label>网址路径 <span class="required">必填</span><input name="slug" value="'
            . self::h($slug) . '" maxlength="120" pattern="[a-z0-9][a-z0-9-]{0,119}" required '
            . ($persisted ? 'readonly ' : '') . '><small>只用小写字母、数字和连字符；创建后不再修改。</small></label>'
            . $fields . '<div class="form-actions">' . $buttons . '</div></form>'
            . ($stateActions === '' ? '' : '<div class="state-actions">' . $stateActions . '</div>')
            . '</section>';
        return self::page($persisted ? '编辑内容' : '新建内容', $body);
    }

    private static function saveContent(
        PDO $pdo,
        array $post,
        array $session,
        string $csrfToken
    ): array {
        $extensionId = self::postText($post, 'extension_id', 120);
        $typeKey = self::postText($post, 'type_key', 40);
        $slug = self::postText($post, 'slug', 120);
        $entryIdText = self::postText($post, 'entry_id', 20, true);
        $intent = self::postText($post, 'intent', 16);
        $type = self::findContentType($pdo, $extensionId, $typeKey);
        $payload = self::contentPayload($type, $post['fields'] ?? null);
        $entry = null;
        if ($entryIdText !== '') {
            if (!ctype_digit($entryIdText) || (int)$entryIdText < 1) {
                throw new RuntimeException('cms_admin_entry_identity_invalid');
            }
            $entry = KuaizCmsContentRepository::adminEntry($pdo, (int)$entryIdText);
            if ($entry['extension_id'] !== $extensionId || $entry['type_key'] !== $typeKey
                || $entry['slug'] !== $slug) {
                throw new RuntimeException('cms_admin_entry_identity_changed');
            }
        }
        try {
            $saved = KuaizCmsContentRepository::save(
                $pdo,
                $extensionId,
                $typeKey,
                $slug,
                $payload,
                'user:' . $session['user']['id'],
                $intent === 'publish'
            );
        } catch (RuntimeException $error) {
            $draftEntry = [
                'id' => $entry['id'] ?? null,
                'slug' => $slug,
                'status' => $entry['status'] ?? 'draft',
                'payload' => $payload,
            ];
            return self::editorPage(
                $type,
                $draftEntry,
                $session,
                $csrfToken,
                self::message($error),
                KuaizCmsMediaRepository::items($pdo)
            );
        }
        return self::redirect('/admin/content/edit?id=' . $saved['entry_id']);
    }

    private static function changeContentState(PDO $pdo, array $post, array $session): array
    {
        $entryId = self::postId($post, 'id');
        $operation = self::postText($post, 'operation', 16);
        $actor = 'user:' . $session['user']['id'];
        if ($operation === 'publish') {
            KuaizCmsContentRepository::publish($pdo, $entryId, $actor);
        } elseif ($operation === 'unpublish') {
            KuaizCmsContentRepository::unpublish($pdo, $entryId, $actor);
        } elseif ($operation === 'archive') {
            KuaizCmsContentRepository::archive($pdo, $entryId, $actor);
        } elseif ($operation === 'restore') {
            KuaizCmsContentRepository::restoreArchived($pdo, $entryId, $actor);
        } else {
            throw new RuntimeException('cms_admin_content_operation_invalid');
        }
        return self::redirect('/admin/content/edit?id=' . $entryId);
    }

    private static function historyPage(
        PDO $pdo,
        array $query,
        array $session,
        string $csrfToken
    ): array {
        $entry = KuaizCmsContentRepository::adminEntry($pdo, self::queryId($query, 'id'));
        $history = KuaizCmsContentRepository::history($pdo, $entry['id']);
        $items = '';
        foreach ($history as $revision) {
            $marks = [];
            $revision['is_current'] && $marks[] = '当前草稿';
            $revision['is_published'] && $marks[] = '线上版本';
            $pretty = json_encode(
                $revision['payload'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $items .= '<article class="revision"><header><strong>版本 ' . $revision['version']
                . '</strong><span>' . self::h(implode(' · ', $marks)) . '</span></header><small>'
                . self::h(date('Y-m-d H:i:s', $revision['created_at'])) . ' · '
                . self::h($revision['actor']) . '</small><pre>' . self::h((string)$pretty) . '</pre></article>';
        }
        $backLink = in_array($session['user']['role'], ['admin', 'editor'], true)
            ? '/admin/content/edit?id=' . $entry['id'] : '/admin';
        $body = '<section class="hero compact"><div><p class="eyebrow">不可变版本记录</p><h1>'
            . self::h(self::entryTitle($entry)) . '</h1><p><a href="' . self::h($backLink)
            . '">← 返回</a></p></div>' . self::userCard($session, $csrfToken)
            . '</section><section class="panel"><div class="timeline">' . $items . '</div></section>';
        return self::page('版本历史', $body);
    }

    private static function mediaPage(
        PDO $pdo,
        array $query,
        array $session,
        string $csrfToken
    ): array {
        $status = self::queryText($query, 'status', 16, true);
        if ($status === '') {
            $status = 'active';
        }
        $items = KuaizCmsMediaRepository::items($pdo, $status, 100, 0);
        $canEdit = in_array($session['user']['role'], ['admin', 'editor'], true);
        $cards = '';
        foreach ($items as $media) {
            $preview = '/admin/media/file?id=' . $media['id'] . '&amp;variant=thumb';
            $usage = $media['active_usage_count'] > 0
                ? '正在被 ' . $media['active_usage_count'] . ' 条内容使用'
                : '当前内容未使用';
            $forms = '';
            if ($canEdit) {
                $forms .= '<form method="post" action="/admin/media/update" class="media-meta">'
                    . self::hidden('_csrf', $csrfToken)
                    . self::hidden('id', (string)$media['id'])
                    . '<label>图片说明<input name="alt_text" maxlength="500" value="'
                    . self::h($media['alt_text']) . '"><small>说明图片表达的内容，装饰图片可以留空。</small></label>'
                    . '<label>内部备注<textarea name="caption" rows="2" maxlength="2000">'
                    . self::h($media['caption']) . '</textarea></label>'
                    . '<button class="button secondary" type="submit">保存说明</button></form>';
                $operation = $media['status'] === 'archived' ? 'restore' : 'archive';
                $label = $media['status'] === 'archived' ? '恢复素材' : '归档素材';
                $forms .= '<form method="post" action="/admin/media/state" class="media-state">'
                    . self::hidden('_csrf', $csrfToken)
                    . self::hidden('id', (string)$media['id'])
                    . self::hidden('operation', $operation)
                    . '<button class="text-button" type="submit">' . self::h($label) . '</button></form>';
            }
            $cards .= '<article class="media-card"><a href="/admin/media/file?id=' . $media['id']
                . '" target="_blank" rel="noopener"><img src="' . $preview . '" alt="'
                . self::h($media['alt_text']) . '" loading="lazy"></a><div class="media-body"><strong>'
                . self::h($media['original_name']) . '</strong><small>' . $media['width'] . ' × '
                . $media['height'] . ' · ' . self::h(self::byteSize($media['byte_size']))
                . '</small><small>' . self::h($usage) . '</small>' . $forms . '</div></article>';
        }
        if ($cards === '') {
            $cards = '<div class="empty">这个分类里还没有素材。</div>';
        }
        $upload = '';
        if ($canEdit && $status === 'active') {
            $accepted = [];
            $labels = [];
            foreach ([
                'imagecreatefromjpeg' => ['image/jpeg', 'JPG'],
                'imagecreatefrompng' => ['image/png', 'PNG'],
                'imagecreatefromwebp' => ['image/webp', 'WebP'],
            ] as $decoder => $format) {
                if (function_exists($decoder)) {
                    $accepted[] = $format[0];
                    $labels[] = $format[1];
                }
            }
            $upload = '<section class="panel upload"><div class="panel-head"><div><h2>上传图片</h2>'
                . '<small>支持 ' . self::h(implode('、', $labels))
                . '；系统会自动处理并生成缩略图。</small></div></div>'
                . '<form method="post" action="/admin/media/upload" enctype="multipart/form-data">'
                . self::hidden('_csrf', $csrfToken)
                . '<label>选择图片<input type="file" name="image" accept="'
                . self::h(implode(',', $accepted)) . '" required></label>'
                . '<label>图片说明<input name="alt_text" maxlength="500"><small>描述图片内容，有助于无障碍访问和图片搜索。</small></label>'
                . '<label>内部备注<textarea name="caption" rows="2" maxlength="2000"></textarea></label>'
                . '<button class="button" type="submit">安全处理并上传</button></form></section>';
        }
        $filters = '<nav class="filters"><a href="/admin/media">可用素材</a>'
            . '<a href="/admin/media?status=archived">已归档</a></nav>';
        $body = '<section class="hero compact"><div><p class="eyebrow">网站资产</p><h1>素材库</h1>'
            . '<p><a href="/admin">← 返回内容列表</a></p></div>'
            . self::userCard($session, $csrfToken) . '</section>' . $upload
            . '<section class="panel"><div class="panel-head"><h2>'
            . self::h($status === 'active' ? '可用图片' : '已归档图片') . '</h2>' . $filters
            . '</div><div class="media-grid">' . $cards . '</div></section>';
        return self::page('素材库', $body);
    }

    private static function uploadMedia(
        PDO $pdo,
        array $post,
        array $files,
        array $session,
        string $storageRoot
    ): array {
        $upload = $files['image'] ?? null;
        if (!is_array($upload)
            || !isset($upload['name'], $upload['tmp_name'], $upload['error'], $upload['size'])
            || !is_string($upload['name']) || !is_string($upload['tmp_name'])
            || !is_int($upload['error']) || !is_int($upload['size'])) {
            throw new RuntimeException('cms_media_upload_invalid');
        }
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('cms_media_upload_failed');
        }
        if (!is_uploaded_file($upload['tmp_name'])) {
            throw new RuntimeException('cms_media_upload_file_unsafe');
        }
        KuaizCmsMediaRepository::storeImage(
            $pdo,
            $storageRoot,
            $upload['tmp_name'],
            $upload['name'],
            self::postText($post, 'alt_text', 2000, true),
            self::postText($post, 'caption', 8000, true),
            'user:' . $session['user']['id']
        );
        return self::redirect('/admin/media');
    }

    private static function updateMedia(PDO $pdo, array $post, array $session): array
    {
        KuaizCmsMediaRepository::updateText(
            $pdo,
            self::postId($post, 'id'),
            self::postText($post, 'alt_text', 2000, true),
            self::postText($post, 'caption', 8000, true),
            'user:' . $session['user']['id']
        );
        return self::redirect('/admin/media');
    }

    private static function changeMediaState(PDO $pdo, array $post, array $session): array
    {
        $mediaId = self::postId($post, 'id');
        $operation = self::postText($post, 'operation', 16);
        $actor = 'user:' . $session['user']['id'];
        if ($operation === 'archive') {
            KuaizCmsMediaRepository::archive($pdo, $mediaId, $actor);
            return self::redirect('/admin/media');
        }
        if ($operation === 'restore') {
            KuaizCmsMediaRepository::restore($pdo, $mediaId, $actor);
            return self::redirect('/admin/media?status=archived');
        }
        throw new RuntimeException('cms_media_operation_invalid');
    }

    private static function mediaFile(PDO $pdo, array $query, string $storageRoot): array
    {
        $mediaId = self::queryId($query, 'id');
        $variant = self::queryText($query, 'variant', 16, true);
        if (!in_array($variant, ['', 'thumb'], true)) {
            throw new RuntimeException('cms_media_variant_invalid');
        }
        $file = KuaizCmsMediaRepository::readFile(
            $pdo,
            $storageRoot,
            $mediaId,
            $variant === 'thumb'
        );
        $body = file_get_contents($file['path']);
        if (!is_string($body) || strlen($body) !== $file['byte_size']) {
            throw new RuntimeException('cms_media_file_read_failed');
        }
        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => $file['mime_type'],
                'Content-Length' => (string)$file['byte_size'],
                'Cache-Control' => 'private, max-age=300',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => 'inline',
                'Vary' => 'Cookie',
            ],
            'body' => $body,
        ];
    }

    private static function settingsPage(
        PDO $pdo,
        array $session,
        string $csrfToken,
        bool $welcome = false
    ): array {
        $savedSettings = KuaizCmsSiteSettings::get($pdo);
        $settings = $savedSettings ?? [
            'site_name' => '',
            'tagline' => '',
            'description' => '',
            'language' => 'zh-CN',
            'direction' => 'ltr',
            'base_url' => '',
            'search_indexing' => false,
            'contact_title' => '',
            'contact_summary' => '',
            'cover_media_id' => null,
        ];
        $coverOptions = '<option value="">不设置首页图片</option>';
        foreach (KuaizCmsMediaRepository::items($pdo) as $media) {
            $selected = $settings['cover_media_id'] === $media['id'] ? ' selected' : '';
            $label = $media['alt_text'] !== '' ? $media['alt_text'] : $media['original_name'];
            $coverOptions .= '<option value="' . $media['id'] . '"' . $selected . '>'
                . self::h($label) . '</option>';
        }
        $checked = $settings['search_indexing'] ? ' checked' : '';
        $publicLink = $settings['base_url'] === '' ? ''
            : '<a class="button secondary" href="' . self::h($settings['base_url'])
                . '" target="_blank" rel="noopener">打开网站</a>';
        $onboarding = ($welcome || $savedSettings === null)
            ? '<div class="notice"><b>先完成网站的基础设置。</b> 填写网站名称、唯一语言和正式网址；'
                . '新站会继续禁止搜索引擎收录，等内容准备好后再由你手动开启。</div>'
            : '';
        $submitLabel = $savedSettings === null ? '保存并选择网站风格' : '保存网站设置';
        $body = '<section class="hero compact"><div><p class="eyebrow">单站单语言</p><h1>网站设置</h1>'
            . '<p><a href="/admin">← 返回内容列表</a></p></div>'
            . self::userCard($session, $csrfToken) . '</section>' . $onboarding
            . '<section class="panel editor">'
            . '<form method="post" action="/admin/settings">' . self::hidden('_csrf', $csrfToken)
            . '<label>网站名称 <span class="required">必填</span><input name="site_name" maxlength="120" value="'
            . self::h($settings['site_name']) . '" required></label>'
            . '<label>一句话介绍<input name="tagline" maxlength="200" value="'
            . self::h($settings['tagline']) . '"></label>'
            . '<label>网站介绍 <span class="required">必填</span><textarea name="description" rows="4" maxlength="500" required>'
            . self::h($settings['description']) . '</textarea><small>用于首页摘要和搜索结果说明。</small></label>'
            . '<label>网站唯一语言 <span class="required">必填</span><input name="language" maxlength="10" value="'
            . self::h($settings['language']) . '" required><small>例如 zh-Hans-CN、en-US、ar-SA；需要另一种语言时创建另一个网站。</small></label>'
            . '<label>文字方向<select name="direction"><option value="ltr"'
            . ($settings['direction'] === 'ltr' ? ' selected' : '') . '>从左到右（中文、英文等）</option>'
            . '<option value="rtl"' . ($settings['direction'] === 'rtl' ? ' selected' : '')
            . '>从右到左（阿拉伯语、希伯来语等）</option></select></label>'
            . '<label>正式网址 <span class="required">必填</span><input type="url" name="base_url" maxlength="2048" placeholder="https://example.com" value="'
            . self::h($settings['base_url']) . '" required><small>用于搜索收录和网站地图；可以使用 HTTP，有条件时建议升级为 HTTPS。</small></label>'
            . '<label>首页图片<select name="cover_media_id">' . $coverOptions
            . '</select><small><a href="/admin/media">前往素材库上传图片</a></small></label>'
            . '<label>联系标题<input name="contact_title" maxlength="160" value="'
            . self::h($settings['contact_title']) . '"></label>'
            . '<label>联系说明<textarea name="contact_summary" rows="3" maxlength="1000">'
            . self::h($settings['contact_summary']) . '</textarea></label>'
            . '<label class="check"><input type="checkbox" name="search_indexing" value="1"'
            . $checked . '><span>允许搜索引擎收录这个正式网站</span><small>新站默认关闭。预览站和测试站不要开启。</small></label>'
            . '<div class="notice">开启收录后，系统会输出 canonical、robots.txt、sitemap.xml 和结构化数据；关闭时所有页面同时发送 noindex。</div>'
            . '<div class="form-actions">' . $publicLink
            . '<button class="button" type="submit">' . $submitLabel
            . '</button></div></form></section>';
        return self::page('网站设置', $body);
    }

    private static function saveSettings(PDO $pdo, array $post, array $session): array
    {
        $firstSetup = KuaizCmsSiteSettings::get($pdo) === null;
        $cover = self::postText($post, 'cover_media_id', 20, true);
        if ($cover !== '' && (!ctype_digit($cover) || (int)$cover < 1)) {
            throw new RuntimeException('cms_site_cover_invalid');
        }
        $indexingValue = $post['search_indexing'] ?? null;
        if ($indexingValue !== null && $indexingValue !== '1') {
            throw new RuntimeException('cms_site_indexing_invalid');
        }
        KuaizCmsSiteSettings::save($pdo, [
            'site_name' => self::postText($post, 'site_name', 480),
            'tagline' => self::postText($post, 'tagline', 800, true),
            'description' => self::postText($post, 'description', 2000),
            'language' => self::postText($post, 'language', 20),
            'direction' => self::postText($post, 'direction', 3),
            'base_url' => self::postText($post, 'base_url', 2048),
            'search_indexing' => $indexingValue === '1',
            'contact_title' => self::postText($post, 'contact_title', 640, true),
            'contact_summary' => self::postText($post, 'contact_summary', 4000, true),
            'cover_media_id' => $cover === '' ? null : (int)$cover,
        ], 'user:' . $session['user']['id']);
        return self::redirect($firstSetup ? '/admin/themes?welcome=1' : '/admin');
    }

    private static function themesPage(
        PDO $pdo,
        array $session,
        string $csrfToken,
        bool $welcome = false
    ): array {
        $cards = '';
        foreach (KuaizCmsThemeRegistry::themes($pdo) as $theme) {
            $manifest = $theme['manifest'];
            $active = $theme['status'] === 'active';
            $badge = $active ? '<span class="theme-status">当前使用</span>' : '';
            $action = $active && !$welcome
                ? '<span class="button secondary disabled">已经启用</span>'
                : '<form method="post" action="/admin/themes/activate">'
                    . self::hidden('_csrf', $csrfToken)
                    . self::hidden('theme_id', $theme['theme_id'])
                    . self::hidden('version', $theme['version'])
                    . '<button class="button wide" type="submit">选择这个风格</button></form>';
            $cards .= '<article class="theme-card">'
                . '<img class="theme-preview" src="' . self::h(self::themePreview($manifest))
                . '" alt="' . self::h($theme['name']) . '风格预览">'
                . '<div class="theme-card-body"><div class="theme-title"><div><h2>'
                . self::h($theme['name']) . '</h2><small>版本 ' . self::h($theme['version'])
                . '</small></div>' . $badge . '</div><p>'
                . self::h($manifest['description']) . '</p>' . $action . '</div></article>';
        }
        if ($cards === '') {
            $cards = '<div class="notice error">当前没有可用的网站风格，请重新运行安装程序。</div>';
        }
        $notice = $welcome
            ? '<div class="notice"><b>基础设置已经保存。</b> 最后选一套网站风格；以后仍可随时更换，内容不会丢失。</div>'
            : '';
        $body = '<section class="hero compact"><div><p class="eyebrow">最后一步</p>'
            . '<h1>选择网站风格</h1><p><a href="/admin">← 返回内容列表</a></p></div>'
            . self::userCard($session, $csrfToken) . '</section>' . $notice
            . '<section class="theme-grid" aria-label="已安装的网站风格">' . $cards . '</section>'
            . '<div class="notice">切换风格只改变网站外观，不会修改文章、图片和 SEO 设置。</div>';
        return self::page('网站风格', $body);
    }

    private static function activateTheme(PDO $pdo, array $post, array $session): array
    {
        KuaizCmsThemeRegistry::activate(
            $pdo,
            self::postText($post, 'theme_id', 80),
            self::postText($post, 'version', 40),
            'user:' . $session['user']['id']
        );
        return self::redirect('/admin?onboarding=ready');
    }

    private static function themePreview(array $manifest): string
    {
        $colors = $manifest['design']['colors'];
        $rounded = max(2, min(18, (int)$manifest['design']['shape']['radius']));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="720" height="440" viewBox="0 0 720 440">'
            . '<rect width="720" height="440" fill="' . $colors['background'] . '"/>'
            . '<rect width="720" height="62" fill="' . $colors['surface'] . '"/>'
            . '<rect x="42" y="24" width="108" height="14" rx="7" fill="' . $colors['text'] . '"/>'
            . '<rect x="545" y="25" width="52" height="11" rx="5" fill="' . $colors['muted'] . '"/>'
            . '<rect x="611" y="25" width="66" height="11" rx="5" fill="' . $colors['primary'] . '"/>'
            . '<rect x="65" y="116" width="78" height="11" rx="5" fill="' . $colors['accent'] . '"/>'
            . '<rect x="65" y="146" width="410" height="30" rx="8" fill="' . $colors['text'] . '"/>'
            . '<rect x="65" y="188" width="334" height="13" rx="6" fill="' . $colors['muted'] . '"/>'
            . '<rect x="65" y="215" width="128" height="42" rx="' . $rounded . '" fill="' . $colors['primary'] . '"/>'
            . '<rect x="65" y="310" width="180" height="94" rx="' . $rounded . '" fill="' . $colors['surface'] . '" stroke="' . $colors['border'] . '"/>'
            . '<rect x="270" y="310" width="180" height="94" rx="' . $rounded . '" fill="' . $colors['surface'] . '" stroke="' . $colors['border'] . '"/>'
            . '<rect x="475" y="310" width="180" height="94" rx="' . $rounded . '" fill="' . $colors['surface'] . '" stroke="' . $colors['border'] . '"/>'
            . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private static function setupPage(string $error = '', int $status = 200): array
    {
        $notice = $error === '' ? '' : '<div class="notice error">' . self::h($error) . '</div>';
        $body = '<section class="auth"><p class="eyebrow">Kuaiz CMS Community</p><h1>启用网站后台</h1>'
            . '<p>输入安装完成时显示的一次性启用码，然后创建本地管理员。启用后，网站不依赖快智总台也能编辑和发布。</p>'
            . $notice . '<form method="post" action="/admin/setup">'
            . '<label>一次性启用码<input name="setup_token" maxlength="64" autocomplete="one-time-code" required></label>'
            . '<label>登录名或邮箱<input name="username" maxlength="128" autocomplete="username" required></label>'
            . '<label>显示名称<input name="display_name" maxlength="80" autocomplete="name" required></label>'
            . '<label>管理员密码<input type="password" name="password" maxlength="1024" autocomplete="new-password" required>'
            . '<small>至少 20 个字符，建议使用一整句容易记住的话。</small></label>'
            . '<label>再次输入密码<input type="password" name="password_confirmation" maxlength="1024" autocomplete="new-password" required></label>'
            . '<button class="button wide" type="submit">创建管理员并进入后台</button></form></section>';
        return self::page('启用后台', $body, $status);
    }

    private static function loginPage(
        string $noticeText = '',
        int $status = 200,
        array $extraHeaders = []
    ): array {
        $loginCsrf = bin2hex(random_bytes(32));
        $cookieHeaders = $extraHeaders['Set-Cookie'] ?? [];
        if (!is_array($cookieHeaders)) {
            $cookieHeaders = [$cookieHeaders];
        }
        $cookieHeaders[] = self::cookieHeader(self::$loginCsrfCookie, $loginCsrf, 900);
        $extraHeaders['Set-Cookie'] = $cookieHeaders;
        $notice = $noticeText === '' ? '' : '<div class="notice">' . self::h($noticeText) . '</div>';
        $body = '<section class="auth"><p class="eyebrow">Kuaiz CMS Community</p><h1>登录网站后台</h1>'
            . '<p>这是当前网站自己的管理后台，即使没有开通 AI 自动运营也能正常使用。</p>'
            . $notice . '<form method="post" action="/admin/login">'
            . self::hidden('_login_csrf', $loginCsrf)
            . '<label>登录名或邮箱<input name="username" maxlength="128" autocomplete="username" required autofocus></label>'
            . '<label>密码<input type="password" name="password" maxlength="1024" autocomplete="current-password" required></label>'
            . '<button class="button wide" type="submit">登录</button></form></section>';
        return self::page('登录', $body, $status, $extraHeaders);
    }

    private static function userCard(array $session, string $csrfToken): string
    {
        return '<div class="user"><div><strong>' . self::h($session['user']['display_name'])
            . '</strong><small>' . self::h(self::roleLabel($session['user']['role']))
            . '</small></div><form method="post" action="/admin/logout">'
            . self::hidden('_csrf', $csrfToken)
            . '<button class="text-button" type="submit">退出</button></form></div>';
    }

    private static function field(array $field, $value, array $mediaItems): string
    {
        $key = (string)$field['key'];
        $type = (string)$field['type'];
        $required = (bool)$field['required'];
        $label = self::h((string)($field['label'] ?? $key));
        $requiredText = $required ? ' <span class="required">必填</span>' : '';
        $requiredAttribute = $required ? ' required' : '';
        $name = 'fields[' . self::h($key) . ']';
        if ($type === 'image') {
            $options = '<option value="">不选择图片</option>';
            foreach ($mediaItems as $media) {
                $selected = is_int($value) && $value === $media['id'] ? ' selected' : '';
                $description = $media['alt_text'] !== ''
                    ? $media['alt_text'] : $media['original_name'];
                $options .= '<option value="' . $media['id'] . '"' . $selected . '>'
                    . self::h($description) . ' · ' . $media['width'] . '×' . $media['height']
                    . '</option>';
            }
            $control = '<select name="' . $name . '"' . $requiredAttribute . '>'
                . $options . '</select><small><a href="/admin/media">管理素材库</a></small>';
        } elseif ($type === 'long_text') {
            $control = '<textarea name="' . $name . '" rows="9"' . $requiredAttribute . '>'
                . self::h(is_string($value) ? $value : '') . '</textarea>';
        } elseif ($type === 'boolean') {
            $selectedTrue = $value === true ? ' selected' : '';
            $selectedFalse = $value === false ? ' selected' : '';
            $control = '<select name="' . $name . '"><option value="0"' . $selectedFalse
                . '>否</option><option value="1"' . $selectedTrue . '>是</option></select>';
        } else {
            $inputTypes = ['number' => 'number', 'date' => 'date', 'url' => 'url'];
            $inputType = $inputTypes[$type] ?? 'text';
            $step = $type === 'number' ? ' step="any"' : '';
            $placeholder = $type === 'datetime' ? ' placeholder="例如 2026-08-06T14:30:00+08:00"' : '';
            $control = '<input type="' . $inputType . '" name="' . $name . '" value="'
                . self::h($value === null ? '' : (string)$value) . '"' . $step . $placeholder
                . $requiredAttribute . '>';
        }
        return '<label>' . $label . $requiredText . $control . '</label>';
    }

    private static function contentPayload(array $type, $submitted): array
    {
        if (!is_array($submitted) || array_is_list($submitted)) {
            throw new RuntimeException('cms_admin_content_fields_invalid');
        }
        $definitions = [];
        foreach ($type['schema']['fields'] as $field) {
            $definitions[(string)$field['key']] = $field;
        }
        if (array_diff(array_keys($submitted), array_keys($definitions)) !== []) {
            throw new RuntimeException('cms_admin_content_fields_invalid');
        }
        $payload = [];
        foreach ($definitions as $key => $field) {
            if (!array_key_exists($key, $submitted)) {
                if ($field['required']) {
                    throw new RuntimeException('cms_content_required_field_missing:' . $key);
                }
                continue;
            }
            $value = $submitted[$key];
            if (!is_string($value)) {
                throw new RuntimeException('cms_admin_content_fields_invalid');
            }
            if ($value === '' && !$field['required'] && $field['type'] !== 'boolean') {
                continue;
            }
            if ($field['type'] === 'boolean') {
                if (!in_array($value, ['0', '1'], true)) {
                    throw new RuntimeException('cms_admin_content_fields_invalid');
                }
                $payload[$key] = $value === '1';
            } elseif ($field['type'] === 'number') {
                if (!preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value)) {
                    throw new RuntimeException('cms_admin_content_fields_invalid');
                }
                $payload[$key] = str_contains($value, '.') ? (float)$value : (int)$value;
            } elseif ($field['type'] === 'image') {
                if (!ctype_digit($value) || (int)$value < 1) {
                    throw new RuntimeException('cms_admin_content_fields_invalid');
                }
                $payload[$key] = (int)$value;
            } else {
                $payload[$key] = $value;
            }
        }
        return $payload;
    }

    private static function findContentType(PDO $pdo, string $extensionId, string $typeKey): array
    {
        foreach (KuaizCmsContentRepository::contentTypes($pdo) as $type) {
            if ($type['extension_id'] === $extensionId && $type['type_key'] === $typeKey) {
                return $type;
            }
        }
        throw new RuntimeException('cms_content_type_not_found');
    }

    private static function rolesForMutation(string $path): array
    {
        if ($path === '/admin/logout') {
            return ['admin', 'editor', 'viewer'];
        }
        if (in_array($path, [
            '/admin/content/save', '/admin/content/state',
            '/admin/media/upload', '/admin/media/update', '/admin/media/state',
        ], true)) {
            return ['admin', 'editor'];
        }
        if (in_array($path, ['/admin/settings', '/admin/themes/activate'], true)) {
            return ['admin'];
        }
        throw new RuntimeException('cms_auth_role_forbidden');
    }

    private static function requiredStorageRoot(?string $storageRoot): string
    {
        if (!is_string($storageRoot) || $storageRoot === '') {
            throw new RuntimeException('cms_media_storage_root_missing');
        }
        return $storageRoot;
    }

    private static function page(
        string $title,
        string $body,
        int $status = 200,
        array $extraHeaders = []
    ): array {
        $nonce = bin2hex(random_bytes(16));
        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::h($title) . ' · Kuaiz CMS</title><style nonce="' . $nonce . '">'
            . self::styles() . '</style></head><body><main>' . $body
            . '</main><footer>Kuaiz CMS Community · 本地内容管理</footer></body></html>';
        $headers = self::securityHeaders($nonce);
        foreach ($extraHeaders as $name => $value) {
            $headers[$name] = $value;
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $html];
    }

    private static function redirect(string $location, array $extraHeaders = []): array
    {
        if (!str_starts_with($location, '/admin')) {
            throw new RuntimeException('cms_admin_redirect_invalid');
        }
        $headers = self::securityHeaders('');
        $headers['Location'] = $location;
        foreach ($extraHeaders as $name => $value) {
            $headers[$name] = $value;
        }
        return ['status' => 303, 'headers' => $headers, 'body' => ''];
    }

    private static function error(int $status, string $message, array $headers = []): array
    {
        return self::page('后台提示', '<section class="auth"><h1>无法完成</h1><p>'
            . self::h($message) . '</p><p><a href="/admin">返回后台</a></p></section>', $status, $headers);
    }

    private static function domainError(
        RuntimeException $error,
        array $session,
        string $csrfToken
    ): array {
        $body = '<section class="hero compact"><div><p class="eyebrow">操作没有完成</p><h1>'
            . self::h(self::message($error)) . '</h1><p><a href="/admin">← 返回内容列表</a></p></div>'
            . self::userCard($session, $csrfToken) . '</section>';
        $status = str_contains($error->getMessage(), 'forbidden') ? 403 : 422;
        return self::page('操作没有完成', $body, $status);
    }

    private static function securityHeaders(string $nonce): array
    {
        $style = $nonce === '' ? "'none'" : "'nonce-" . $nonce . "'";
        return [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; style-src " . $style
                . "; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];
    }

    private static function loginCookies(array $login): array
    {
        return ['Set-Cookie' => [
            self::cookieHeader(self::$sessionCookie, (string)$login['token'], 43200),
            self::cookieHeader(self::$csrfCookie, (string)$login['csrf_token'], 43200),
            self::cookieHeader(self::$loginCsrfCookie, '', 0),
        ]];
    }

    private static function expiredCookies(): array
    {
        return ['Set-Cookie' => [
            self::cookieHeader(self::$sessionCookie, '', 0),
            self::cookieHeader(self::$csrfCookie, '', 0),
        ]];
    }

    private static function cookieHeader(string $name, string $value, int $maxAge): string
    {
        return $name . '=' . $value . '; Path=/; Max-Age=' . $maxAge
            . (self::$secureCookies ? '; Secure' : '') . '; HttpOnly; SameSite=Strict';
    }

    private static function configureCookies(array $server): void
    {
        $direct = strtolower((string)($server['HTTPS'] ?? ''));
        $forwarded = strtolower(trim(explode(
            ',',
            (string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')
        )[0]));
        $port = (string)($server['SERVER_PORT'] ?? '');
        self::$secureCookies = ($direct !== '' && $direct !== 'off')
            || $forwarded === 'https'
            || ($direct === '' && $forwarded === '' && $port !== '80');
        $prefix = self::$secureCookies ? '__Host-' : '';
        self::$sessionCookie = $prefix . 'kuaiz_cms_session';
        self::$csrfCookie = $prefix . 'kuaiz_cms_csrf';
        self::$loginCsrfCookie = $prefix . 'kuaiz_cms_login_csrf';
    }

    private static function cookie(array $cookies, string $key): ?string
    {
        $value = $cookies[$key] ?? null;
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) ? $value : null;
    }

    private static function clientKey(array $server): string
    {
        $address = (string)($server['REMOTE_ADDR'] ?? '');
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            $address = 'unknown';
        }
        return 'ip:' . $address;
    }

    private static function postText(
        array $post,
        string $key,
        int $maximum,
        bool $allowEmpty = false
    ): string {
        $value = $post[$key] ?? null;
        if (!is_string($value) || strlen($value) > $maximum
            || (!$allowEmpty && $value === '') || !preg_match('//u', $value)) {
            throw new RuntimeException('cms_admin_form_invalid');
        }
        return $value;
    }

    private static function queryText(
        array $query,
        string $key,
        int $maximum,
        bool $allowEmpty = false
    ): string {
        $value = $query[$key] ?? '';
        if (!is_string($value) || strlen($value) > $maximum
            || (!$allowEmpty && $value === '') || !preg_match('//u', $value)) {
            throw new RuntimeException('cms_admin_query_invalid');
        }
        return $value;
    }

    private static function postId(array $post, string $key): int
    {
        $value = self::postText($post, $key, 20);
        if (!ctype_digit($value) || (int)$value < 1) {
            throw new RuntimeException('cms_admin_entry_identity_invalid');
        }
        return (int)$value;
    }

    private static function queryId(array $query, string $key): int
    {
        $value = self::queryText($query, $key, 20);
        if (!ctype_digit($value) || (int)$value < 1) {
            throw new RuntimeException('cms_admin_entry_identity_invalid');
        }
        return (int)$value;
    }

    private static function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . self::h($name) . '" value="' . self::h($value) . '">';
    }

    private static function entryTitle(array $entry): string
    {
        foreach (['title', 'name', 'headline'] as $key) {
            if (isset($entry['payload'][$key]) && is_string($entry['payload'][$key])
                && trim($entry['payload'][$key]) !== '') {
                return (string)$entry['payload'][$key];
            }
        }
        return (string)$entry['slug'];
    }

    private static function message(RuntimeException $error): string
    {
        $code = explode(':', $error->getMessage(), 2)[0];
        $messages = [
            'cms_auth_setup_token_invalid' => '一次性启用码不正确。',
            'cms_auth_username_invalid' => '登录名格式不正确。',
            'cms_auth_password_invalid' => '密码至少需要 20 个字符。',
            'cms_auth_credentials_invalid' => '登录名或密码不正确。',
            'cms_auth_rate_limited' => '尝试次数过多，请 15 分钟后再试。',
            'cms_auth_role_forbidden' => '当前账号没有执行此操作的权限。',
            'cms_auth_admin_required' => '当前账号没有执行此操作的权限。',
            'cms_auth_csrf_invalid' => '登录状态已失效，请重新登录。',
            'cms_auth_session_invalid' => '登录状态已失效，请重新登录。',
            'cms_auth_login_csrf_invalid' => '登录页面已过期，请重新填写。',
            'cms_admin_password_confirmation_mismatch' => '两次输入的密码不一致。',
            'cms_content_required_field_missing' => '请填写所有必填内容。',
            'cms_content_field_invalid' => '部分内容格式不正确，请检查后重试。',
            'cms_admin_content_fields_invalid' => '部分内容格式不正确，请检查后重试。',
            'cms_content_media_not_found' => '选择的图片不存在。',
            'cms_content_entry_not_found' => '这条内容不存在或已经不可用。',
            'cms_content_archived_publish_forbidden' => '请先恢复已归档内容，再发布。',
            'cms_admin_entry_identity_changed' => '内容身份发生变化，已拒绝保存。',
            'cms_media_upload_size_invalid' => '图片文件过大，最大支持 12MB。',
            'cms_media_type_unsupported' => '这种图片格式暂不支持，请按上传页列出的格式选择。',
            'cms_media_dimensions_invalid' => '图片尺寸或像素数量超过安全限制。',
            'cms_media_image_invalid' => '图片内容损坏或格式不正确。',
            'cms_media_decode_failed' => '图片内容损坏或格式不正确。',
            'cms_media_in_use' => '这张图片仍被当前内容使用，暂时不能归档。',
            'cms_media_image_runtime_missing' => '主机缺少图片处理能力，请联系服务商启用 PHP GD。',
            'cms_media_decoder_missing' => '当前主机不能处理这种图片，请按上传页列出的格式选择。',
            'cms_media_upload_failed' => '图片上传没有完成，请重新选择文件。',
            'cms_media_upload_invalid' => '图片上传没有完成，请重新选择文件。',
            'cms_site_name_invalid' => '请完整填写网站名称和网站介绍。',
            'cms_site_description_invalid' => '请完整填写网站名称和网站介绍。',
            'cms_site_language_invalid' => '网站语言格式不正确，例如 zh-Hans-CN、en-US 或 ar-SA。',
            'cms_site_direction_invalid' => '文字方向设置不正确。',
            'cms_site_base_url_invalid' => '正式网址必须是 HTTP 或 HTTPS 根域名，例如 https://example.com。',
            'cms_site_cover_invalid' => '选择的首页图片不存在或已经归档。',
            'cms_site_indexing_invalid' => '搜索引擎收录设置不正确。',
            'theme_not_installed' => '选择的网站风格不存在，请刷新页面后重试。',
            'theme_id_invalid' => '网站风格信息不正确，请刷新页面后重试。',
            'theme_version_invalid' => '网站风格信息不正确，请刷新页面后重试。',
            'theme_extension_slot_unavailable' => '这个风格需要尚未安装的扩展，暂时不能启用。',
        ];
        return $messages[$code] ?? '输入内容有误或当前操作无法完成。';
    }

    private static function statusLabel(string $status): string
    {
        $labels = ['draft' => '草稿', 'published' => '已发布', 'archived' => '已归档'];
        return $labels[$status] ?? '未知';
    }

    private static function roleLabel(string $role): string
    {
        $labels = ['admin' => '管理员', 'editor' => '编辑', 'viewer' => '只读成员'];
        return $labels[$role] ?? '成员';
    }

    private static function byteSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        return max(1, (int)ceil($bytes / 1024)) . ' KB';
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function styles(): string
    {
        return <<<'CSS'
:root{color-scheme:light;--bg:#f4f1e9;--surface:#fffdf8;--text:#17231d;--muted:#617067;--brand:#176146;--line:#dcd8ce;--danger:#9f352d}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.65 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif}a{color:var(--brand);text-decoration:none}a:hover{text-decoration:underline}main{width:min(1160px,calc(100% - 32px));margin:32px auto 64px}.hero{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin:0 0 24px}.hero.compact{align-items:center}.hero h1,.auth h1{font-size:clamp(30px,5vw,52px);line-height:1.08;margin:4px 0 12px;letter-spacing:-.035em}.hero p{margin:0;color:var(--muted)}.hero-action{margin-top:16px!important}.eyebrow{color:var(--brand)!important;font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.panel,.auth{background:var(--surface);border:1px solid var(--line);border-radius:22px;box-shadow:0 18px 50px rgba(23,35,29,.07)}.panel{padding:24px;margin:18px 0}.auth{width:min(540px,100%);margin:8vh auto;padding:clamp(24px,5vw,48px)}.auth>p{color:var(--muted)}.panel-head,.user,.types li,.revision header,.form-actions,.state-actions{display:flex;align-items:center;justify-content:space-between;gap:16px}.panel h2{font-size:21px;margin:0}.user{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:10px 14px;min-width:210px}.user strong,.user small{display:block}.user small,small{color:var(--muted)}.types{list-style:none;margin:16px 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.types li{border:1px solid var(--line);border-radius:14px;padding:12px}.button{appearance:none;border:0;border-radius:11px;background:var(--brand);color:white;cursor:pointer;display:inline-block;font:inherit;font-weight:700;padding:10px 16px;text-align:center}.button:hover{text-decoration:none;filter:brightness(.95)}.button.secondary{background:#e7efe9;color:var(--brand)}.button.wide{width:100%;margin-top:8px}.button.disabled{cursor:default;display:block;opacity:.72;text-align:center;width:100%}.text-button{appearance:none;border:0;background:none;color:var(--brand);cursor:pointer;font:inherit;padding:0}.filters{display:flex;flex-wrap:wrap;gap:12px}.table-wrap{overflow:auto;margin-top:16px}table{border-collapse:collapse;width:100%;min-width:700px}th,td{border-top:1px solid var(--line);padding:13px 10px;text-align:left;vertical-align:middle}th{color:var(--muted);font-size:12px;text-transform:uppercase}td small{display:block}.actions{white-space:nowrap}.status,.flag{border-radius:999px;background:#edf3ef;color:var(--brand);display:inline-block;font-size:12px;padding:2px 8px}.flag{background:#fff0d7;color:#8b5410;margin-left:6px}.empty{color:var(--muted);padding:22px!important;text-align:center!important}form label{display:block;font-weight:700;margin:18px 0}input,textarea,select{background:white;border:1px solid #bfc8c1;border-radius:11px;color:var(--text);display:block;font:inherit;margin-top:7px;padding:11px 12px;width:100%}input:focus,textarea:focus,select:focus{border-color:var(--brand);outline:3px solid rgba(23,97,70,.12)}label small{display:block;font-weight:400;margin-top:5px}.check{display:grid!important;grid-template-columns:auto 1fr;column-gap:10px;align-items:center}.check input{margin:0;width:auto}.check small{grid-column:2}.required{color:var(--danger);font-size:12px}.editor{max-width:820px}.form-actions{justify-content:flex-end;margin-top:24px}.state-actions{border-top:1px solid var(--line);justify-content:flex-start;margin-top:28px;padding-top:18px}.notice{background:#edf3ef;border-radius:12px;color:var(--brand);margin:18px 0;padding:12px 14px}.notice.error{background:#fff0ed;color:var(--danger)}.timeline{display:grid;gap:16px}.revision{border:1px solid var(--line);border-radius:14px;padding:16px}.revision header span{color:var(--brand);font-size:12px}.revision pre{background:#18231e;color:#e9f0eb;border-radius:10px;max-height:340px;overflow:auto;padding:14px;white-space:pre-wrap}.media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px;margin-top:18px}.media-card{border:1px solid var(--line);border-radius:16px;overflow:hidden}.media-card>a{background:#e9ede9;display:block;aspect-ratio:4/3}.media-card img{height:100%;object-fit:cover;width:100%}.media-body{padding:14px}.media-body>strong,.media-body>small{display:block}.media-meta{border-top:1px solid var(--line);margin-top:12px;padding-top:1px}.media-meta label{font-size:13px;margin:10px 0}.media-meta input,.media-meta textarea{padding:8px 9px}.media-state{margin-top:12px}.upload form{max-width:680px}.theme-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px}.theme-card{background:var(--surface);border:1px solid var(--line);border-radius:20px;box-shadow:0 18px 50px rgba(23,35,29,.07);overflow:hidden}.theme-preview{aspect-ratio:18/11;background:#e9ede9;border-bottom:1px solid var(--line);display:block;object-fit:cover;width:100%}.theme-card-body{padding:20px}.theme-card-body>p{color:var(--muted);min-height:3.3em}.theme-card-body form{margin:0}.theme-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.theme-title h2{margin:0}.theme-status{background:#e1f2e8;border-radius:999px;color:var(--brand);font-size:12px;font-weight:800;padding:4px 9px;white-space:nowrap}footer{color:var(--muted);font-size:12px;padding:0 16px 32px;text-align:center}@media(max-width:720px){main{margin-top:20px}.hero{display:block}.user{margin-top:18px}.panel{padding:18px}.panel-head{align-items:flex-start;display:block}.filters{margin-top:12px}.form-actions{align-items:stretch;flex-direction:column}.form-actions .button{width:100%}.theme-grid{grid-template-columns:1fr}}
CSS;
    }
}
