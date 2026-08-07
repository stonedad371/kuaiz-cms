<?php
declare(strict_types=1);

/** Cross-request lock used by backup, restore and the web front controller. */
final class KuaizCmsMaintenance
{
    public static function shared(string $dataDirectory): mixed
    {
        return self::acquire($dataDirectory, LOCK_SH | LOCK_NB, 'cms_maintenance_in_progress');
    }

    public static function exclusive(string $dataDirectory): mixed
    {
        return self::acquire($dataDirectory, LOCK_EX | LOCK_NB, 'cms_maintenance_busy');
    }

    public static function release(mixed $handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private static function acquire(
        string $dataDirectory,
        int $operation,
        string $busyError
    ): mixed {
        $root = self::dataRoot($dataDirectory);
        $path = $root . '/.maintenance.lock';
        if (is_link($path)) {
            throw new RuntimeException('cms_maintenance_lock_unsafe');
        }
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException('cms_maintenance_lock_unavailable');
        }
        @chmod($path, 0600);
        if (is_link($path) || !@flock($handle, $operation)) {
            @fclose($handle);
            throw new RuntimeException(is_link($path) ? 'cms_maintenance_lock_unsafe' : $busyError);
        }
        return $handle;
    }

    private static function dataRoot(string $dataDirectory): string
    {
        if ($dataDirectory === '' || str_contains($dataDirectory, "\0")
            || is_link($dataDirectory) || !is_dir($dataDirectory)
            || !is_writable($dataDirectory)) {
            throw new RuntimeException('cms_data_directory_unsafe');
        }
        $root = realpath($dataDirectory);
        if (!is_string($root)) {
            throw new RuntimeException('cms_data_directory_unsafe');
        }
        return rtrim($root, DIRECTORY_SEPARATOR);
    }
}
