<?php
declare(strict_types=1);

/** Small PHP 7.4 compatibility helpers used by the CMS runtime. */
if (!function_exists('array_is_list')) {
    function array_is_list(array $array): bool
    {
        $expectedKey = 0;
        foreach ($array as $key => $_value) {
            if ($key !== $expectedKey) {
                return false;
            }
            $expectedKey++;
        }
        return true;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $length = strlen($needle);
        return $length <= strlen($haystack) && substr($haystack, -$length) === $needle;
    }
}
