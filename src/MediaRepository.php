<?php
declare(strict_types=1);

/** Safe image normalization, storage and lifecycle management for Kuaiz CMS. */
final class KuaizCmsMediaRepository
{
    private const MAX_UPLOAD_BYTES = 12582912;
    private const MAX_PIXELS = 12000000;
    private const MAX_SOURCE_EDGE = 12000;
    private const MAX_OUTPUT_EDGE = 3000;
    private const THUMBNAIL_EDGE = 640;
    private const WEBP_QUALITY = 84;

    public static function storeImage(
        PDO $pdo,
        string $storageRoot,
        string $uploadedPath,
        string $originalName,
        string $altText,
        string $caption,
        string $actor
    ): array {
        self::actor($actor);
        $originalName = self::originalName($originalName);
        $altText = self::text($altText, 500, 'cms_media_alt_invalid', true);
        $caption = self::text($caption, 2000, 'cms_media_caption_invalid', true);
        if ($uploadedPath === '' || is_link($uploadedPath) || !is_file($uploadedPath)) {
            throw new RuntimeException('cms_media_upload_file_unsafe');
        }
        $sourceBytes = filesize($uploadedPath);
        if (!is_int($sourceBytes) || $sourceBytes < 12 || $sourceBytes > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('cms_media_upload_size_invalid');
        }
        if (!class_exists('finfo') || !function_exists('getimagesize')
            || !function_exists('imagewebp') || !function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('cms_media_image_runtime_missing');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($uploadedPath);
        if (!is_string($mimeType)
            || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('cms_media_type_unsupported');
        }
        $imageInfo = @getimagesize($uploadedPath);
        if (!is_array($imageInfo) || !isset($imageInfo[0], $imageInfo[1], $imageInfo['mime'])
            || $imageInfo['mime'] !== $mimeType) {
            throw new RuntimeException('cms_media_image_invalid');
        }
        $sourceWidth = (int)$imageInfo[0];
        $sourceHeight = (int)$imageInfo[1];
        if ($sourceWidth < 1 || $sourceHeight < 1
            || $sourceWidth > self::MAX_SOURCE_EDGE || $sourceHeight > self::MAX_SOURCE_EDGE
            || $sourceWidth * $sourceHeight > self::MAX_PIXELS) {
            throw new RuntimeException('cms_media_dimensions_invalid');
        }

        $source = self::decode($uploadedPath, $mimeType);
        try {
            $source = self::orient($source, $uploadedPath, $mimeType);
            [$outputWidth, $outputHeight] = self::fit(
                imagesx($source),
                imagesy($source),
                self::MAX_OUTPUT_EDGE
            );
            $normalized = self::resample($source, $outputWidth, $outputHeight);
            [$thumbWidth, $thumbHeight] = self::fit(
                $outputWidth,
                $outputHeight,
                self::THUMBNAIL_EDGE
            );
            $thumbnail = self::resample($normalized, $thumbWidth, $thumbHeight);
        } finally {
            isset($source) && is_object($source) && imagedestroy($source);
        }

        $root = self::storageRoot($storageRoot);
        $temporaryDirectory = self::directory($root . '/.tmp', $root);
        $normalizedTemporary = tempnam($temporaryDirectory, 'image-');
        $thumbnailTemporary = tempnam($temporaryDirectory, 'thumb-');
        if (!is_string($normalizedTemporary) || !is_string($thumbnailTemporary)) {
            isset($normalized) && is_object($normalized) && imagedestroy($normalized);
            isset($thumbnail) && is_object($thumbnail) && imagedestroy($thumbnail);
            throw new RuntimeException('cms_media_temporary_file_failed');
        }
        try {
            if (!imagewebp($normalized, $normalizedTemporary, self::WEBP_QUALITY)
                || !imagewebp($thumbnail, $thumbnailTemporary, self::WEBP_QUALITY)) {
                throw new RuntimeException('cms_media_encode_failed');
            }
        } finally {
            imagedestroy($normalized);
            imagedestroy($thumbnail);
        }
        @chmod($normalizedTemporary, 0600);
        @chmod($thumbnailTemporary, 0600);

        $sha256 = hash_file('sha256', $normalizedTemporary);
        if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            @unlink($normalizedTemporary);
            @unlink($thumbnailTemporary);
            throw new RuntimeException('cms_media_hash_failed');
        }
        $relativeDirectory = 'media/' . substr($sha256, 0, 2) . '/' . substr($sha256, 2, 2);
        $storageKey = $relativeDirectory . '/' . $sha256 . '.webp';
        $thumbnailStorageKey = $relativeDirectory . '/' . $sha256 . '.thumb.webp';
        $targetDirectory = self::directory($root . '/' . $relativeDirectory, $root);
        $normalizedTarget = $targetDirectory . '/' . $sha256 . '.webp';
        $thumbnailTarget = $targetDirectory . '/' . $sha256 . '.thumb.webp';
        $createdNormalized = false;
        $createdThumbnail = false;
        try {
            $createdNormalized = self::installFile(
                $normalizedTemporary,
                $normalizedTarget,
                $sha256
            );
            $thumbnailSha256 = hash_file('sha256', $thumbnailTemporary);
            if (!is_string($thumbnailSha256)) {
                throw new RuntimeException('cms_media_hash_failed');
            }
            $createdThumbnail = self::installFile(
                $thumbnailTemporary,
                $thumbnailTarget,
                $thumbnailSha256
            );
            $record = self::record(
                $pdo,
                $storageKey,
                $thumbnailStorageKey,
                $originalName,
                $sha256,
                (int)filesize($normalizedTarget),
                $outputWidth,
                $outputHeight,
                $altText,
                $caption,
                $actor
            );
        } catch (Throwable $error) {
            @unlink($normalizedTemporary);
            @unlink($thumbnailTemporary);
            if ($createdNormalized) {
                @unlink($normalizedTarget);
            }
            if ($createdThumbnail) {
                @unlink($thumbnailTarget);
            }
            throw $error;
        }
        return $record;
    }

    public static function items(
        PDO $pdo,
        string $status = 'active',
        int $limit = 100,
        int $offset = 0
    ): array {
        self::status($status);
        if ($limit < 1 || $limit > 200 || $offset < 0 || $offset > 1000000) {
            throw new RuntimeException('cms_media_pagination_invalid');
        }
        $statement = $pdo->prepare(<<<'SQL'
SELECT m.*,
       COUNT(DISTINCT CASE
         WHEN rm.revision_id=e.current_revision_id OR rm.revision_id=e.published_revision_id
         THEN e.id END) AS active_usage_count,
       COUNT(DISTINCT rm.revision_id) AS revision_usage_count
FROM cms_media m
LEFT JOIN cms_revision_media rm ON rm.media_id=m.id
LEFT JOIN cms_entry_revisions r ON r.id=rm.revision_id
LEFT JOIN cms_entries e ON e.id=r.entry_id
WHERE m.status=:status
GROUP BY m.id
ORDER BY m.created_at DESC,m.id DESC
LIMIT :limit OFFSET :offset
SQL);
        $statement->bindValue(':status', $status, PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return array_map([self::class, 'publicRecord'], $statement->fetchAll());
    }

    public static function item(PDO $pdo, int $mediaId): array
    {
        if ($mediaId < 1) {
            throw new RuntimeException('cms_media_identity_invalid');
        }
        $statement = $pdo->prepare('SELECT * FROM cms_media WHERE id=:media_id');
        $statement->execute([':media_id' => $mediaId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('cms_media_not_found');
        }
        return self::publicRecord($row);
    }

    public static function readFile(
        PDO $pdo,
        string $storageRoot,
        int $mediaId,
        bool $thumbnail = false
    ): array {
        $media = self::item($pdo, $mediaId);
        $key = $thumbnail ? $media['thumbnail_storage_key'] : $media['storage_key'];
        if (!is_string($key) || !preg_match(
            '#^media/[a-f0-9]{2}/[a-f0-9]{2}/[a-f0-9]{64}(?:\.thumb)?\.webp$#D',
            $key
        )) {
            throw new RuntimeException('cms_media_storage_key_invalid');
        }
        $root = self::storageRoot($storageRoot);
        $path = $root . '/' . $key;
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('cms_media_file_missing');
        }
        $realRoot = realpath($root);
        $realPath = realpath($path);
        if (!is_string($realRoot) || !is_string($realPath)
            || !str_starts_with($realPath, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('cms_media_file_unsafe');
        }
        $bytes = filesize($realPath);
        if (!is_int($bytes) || $bytes < 1 || $bytes > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('cms_media_file_invalid');
        }
        return ['path' => $realPath, 'mime_type' => 'image/webp', 'byte_size' => $bytes];
    }

    public static function updateText(
        PDO $pdo,
        int $mediaId,
        string $altText,
        string $caption,
        string $actor
    ): array {
        self::actor($actor);
        $altText = self::text($altText, 500, 'cms_media_alt_invalid', true);
        $caption = self::text($caption, 2000, 'cms_media_caption_invalid', true);
        self::item($pdo, $mediaId);
        $now = time();
        self::transactionAvailable($pdo);
        $pdo->beginTransaction();
        try {
            $pdo->prepare(<<<'SQL'
UPDATE cms_media SET alt_text=:alt_text,caption=:caption,updated_at=:updated_at
WHERE id=:media_id
SQL)->execute([
                ':alt_text' => $altText,
                ':caption' => $caption,
                ':updated_at' => $now,
                ':media_id' => $mediaId,
            ]);
            self::audit(
                $pdo,
                $actor,
                'media.metadata_updated',
                $mediaId,
                ['alt_text_present' => $altText !== '', 'caption_present' => $caption !== ''],
                $now
            );
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }
        return self::item($pdo, $mediaId);
    }

    public static function archive(PDO $pdo, int $mediaId, string $actor): array
    {
        self::actor($actor);
        $media = self::item($pdo, $mediaId);
        if ($media['status'] === 'archived') {
            return $media;
        }
        $statement = $pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM cms_revision_media rm
JOIN cms_entry_revisions r ON r.id=rm.revision_id
JOIN cms_entries e ON e.id=r.entry_id
WHERE rm.media_id=:media_id
  AND (rm.revision_id=e.current_revision_id OR rm.revision_id=e.published_revision_id)
SQL);
        $statement->execute([':media_id' => $mediaId]);
        if ((int)$statement->fetchColumn() !== 0) {
            throw new RuntimeException('cms_media_in_use');
        }
        $now = time();
        self::transactionAvailable($pdo);
        $pdo->beginTransaction();
        try {
            $pdo->prepare(<<<'SQL'
UPDATE cms_media SET status='archived',archived_at=:archived_at,updated_at=:updated_at
WHERE id=:media_id
SQL)->execute([':archived_at' => $now, ':updated_at' => $now, ':media_id' => $mediaId]);
            self::audit($pdo, $actor, 'media.archived', $mediaId, [], $now);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }
        return self::item($pdo, $mediaId);
    }

    public static function restore(PDO $pdo, int $mediaId, string $actor): array
    {
        self::actor($actor);
        self::item($pdo, $mediaId);
        $now = time();
        self::transactionAvailable($pdo);
        $pdo->beginTransaction();
        try {
            $pdo->prepare(<<<'SQL'
UPDATE cms_media SET status='active',archived_at=NULL,updated_at=:updated_at
WHERE id=:media_id
SQL)->execute([':updated_at' => $now, ':media_id' => $mediaId]);
            self::audit($pdo, $actor, 'media.restored', $mediaId, [], $now);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }
        return self::item($pdo, $mediaId);
    }

    private static function record(
        PDO $pdo,
        string $storageKey,
        string $thumbnailStorageKey,
        string $originalName,
        string $sha256,
        int $byteSize,
        int $width,
        int $height,
        string $altText,
        string $caption,
        string $actor
    ): array {
        self::transactionAvailable($pdo);
        $now = time();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT * FROM cms_media WHERE storage_key=:storage_key');
            $statement->execute([':storage_key' => $storageKey]);
            $existing = $statement->fetch();
            if (is_array($existing)) {
                $mediaId = (int)$existing['id'];
                $pdo->prepare(<<<'SQL'
UPDATE cms_media
SET original_name=:original_name,alt_text=:alt_text,caption=:caption,
    status='active',archived_at=NULL,updated_at=:updated_at
WHERE id=:media_id
SQL)->execute([
                    ':original_name' => $originalName,
                    ':alt_text' => $altText,
                    ':caption' => $caption,
                    ':updated_at' => $now,
                    ':media_id' => $mediaId,
                ]);
                $action = 'media.reused';
            } else {
                $pdo->prepare(<<<'SQL'
INSERT INTO cms_media(
  storage_key,original_name,mime_type,byte_size,sha256,alt_text,caption,
  created_at,updated_at,width,height,thumbnail_storage_key,status,archived_at)
VALUES(
  :storage_key,:original_name,'image/webp',:byte_size,:sha256,:alt_text,:caption,
  :created_at,:updated_at,:width,:height,:thumbnail_storage_key,'active',NULL)
SQL)->execute([
                    ':storage_key' => $storageKey,
                    ':original_name' => $originalName,
                    ':byte_size' => $byteSize,
                    ':sha256' => $sha256,
                    ':alt_text' => $altText,
                    ':caption' => $caption,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                    ':width' => $width,
                    ':height' => $height,
                    ':thumbnail_storage_key' => $thumbnailStorageKey,
                ]);
                $mediaId = (int)$pdo->lastInsertId();
                $action = 'media.created';
            }
            self::audit(
                $pdo,
                $actor,
                $action,
                $mediaId,
                [
                    'byte_size' => $byteSize,
                    'height' => $height,
                    'sha256' => $sha256,
                    'width' => $width,
                ],
                $now
            );
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->inTransaction() && $pdo->rollBack();
            throw $error;
        }
        return self::item($pdo, $mediaId);
    }

    private static function decode(string $path, string $mimeType): object
    {
        $loader = match ($mimeType) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            default => null,
        };
        if (!is_string($loader) || !function_exists($loader)) {
            throw new RuntimeException('cms_media_decoder_missing');
        }
        $image = @$loader($path);
        if (!is_object($image)) {
            throw new RuntimeException('cms_media_decode_failed');
        }
        return $image;
    }

    private static function orient(object $image, string $path, string $mimeType): object
    {
        if ($mimeType !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path, 'IFD0', true, false);
        $orientation = is_array($exif)
            ? (int)($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1) : 1;
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if (!is_object($rotated)) {
            throw new RuntimeException('cms_media_orientation_failed');
        }
        imagedestroy($image);
        return $rotated;
    }

    private static function fit(int $width, int $height, int $maximum): array
    {
        $ratio = min(1, $maximum / max($width, $height));
        return [max(1, (int)round($width * $ratio)), max(1, (int)round($height * $ratio))];
    }

    private static function resample(object $source, int $width, int $height): object
    {
        $target = imagecreatetruecolor($width, $height);
        if (!is_object($target)) {
            throw new RuntimeException('cms_media_canvas_failed');
        }
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $width, $height, $transparent);
        if (!imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($source),
            imagesy($source)
        )) {
            imagedestroy($target);
            throw new RuntimeException('cms_media_resize_failed');
        }
        return $target;
    }

    private static function storageRoot(string $storageRoot): string
    {
        if ($storageRoot === '' || is_link($storageRoot) || !is_dir($storageRoot)
            || !is_writable($storageRoot)) {
            throw new RuntimeException('cms_media_storage_root_unsafe');
        }
        $real = realpath($storageRoot);
        if (!is_string($real)) {
            throw new RuntimeException('cms_media_storage_root_unsafe');
        }
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private static function directory(string $path, string $root): string
    {
        if (is_link($path)) {
            throw new RuntimeException('cms_media_storage_directory_unsafe');
        }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('cms_media_storage_directory_failed');
        }
        $realPath = realpath($path);
        $realRoot = realpath($root);
        if (is_link($path) || !is_writable($path)
            || !is_string($realPath) || !is_string($realRoot)
            || !str_starts_with(
                $realPath,
                rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            )) {
            throw new RuntimeException('cms_media_storage_directory_unsafe');
        }
        @chmod($path, 0700);
        return $realPath;
    }

    private static function installFile(string $temporary, string $target, string $sha256): bool
    {
        if (is_file($target)) {
            $existingSha256 = hash_file('sha256', $target);
            @unlink($temporary);
            if (!is_string($existingSha256) || !hash_equals($sha256, $existingSha256)) {
                throw new RuntimeException('cms_media_existing_file_corrupt');
            }
            return false;
        }
        if (is_link($target) || !rename($temporary, $target)) {
            throw new RuntimeException('cms_media_store_failed');
        }
        @chmod($target, 0600);
        return true;
    }

    private static function originalName(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));
        $parts = explode('/', $name);
        $name = (string)end($parts);
        return self::text($name, 255, 'cms_media_original_name_invalid');
    }

    private static function text(
        string $value,
        int $maximum,
        string $errorCode,
        bool $allowEmpty = false
    ): string {
        $value = trim($value);
        if ((!$allowEmpty && $value === '') || strlen($value) > $maximum * 4
            || !preg_match('//u', $value)
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new RuntimeException($errorCode);
        }
        return $value;
    }

    private static function actor(string $actor): void
    {
        if (!preg_match('/^[a-z][a-z0-9:_-]{0,79}$/D', $actor)) {
            throw new RuntimeException('cms_media_actor_invalid');
        }
    }

    private static function status(string $status): void
    {
        if (!in_array($status, ['active', 'archived'], true)) {
            throw new RuntimeException('cms_media_status_invalid');
        }
    }

    private static function transactionAvailable(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            throw new RuntimeException('cms_media_nested_transaction_forbidden');
        }
    }

    private static function publicRecord(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'storage_key' => (string)$row['storage_key'],
            'thumbnail_storage_key' => $row['thumbnail_storage_key'] === null
                ? null : (string)$row['thumbnail_storage_key'],
            'original_name' => (string)$row['original_name'],
            'mime_type' => (string)$row['mime_type'],
            'byte_size' => (int)$row['byte_size'],
            'sha256' => (string)$row['sha256'],
            'width' => (int)$row['width'],
            'height' => (int)$row['height'],
            'alt_text' => (string)$row['alt_text'],
            'caption' => (string)$row['caption'],
            'status' => (string)$row['status'],
            'active_usage_count' => isset($row['active_usage_count'])
                ? (int)$row['active_usage_count'] : 0,
            'revision_usage_count' => isset($row['revision_usage_count'])
                ? (int)$row['revision_usage_count'] : 0,
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
            'archived_at' => $row['archived_at'] === null ? null : (int)$row['archived_at'],
        ];
    }

    private static function audit(
        PDO $pdo,
        string $actor,
        string $action,
        int $mediaId,
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
VALUES(:actor,:action,'media',:resource_id,:details_json,:created_at)
SQL)->execute([
            ':actor' => $actor,
            ':action' => $action,
            ':resource_id' => (string)$mediaId,
            ':details_json' => $body,
            ':created_at' => $now,
        ]);
    }
}
