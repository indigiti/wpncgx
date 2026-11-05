<?php

declare(strict_types=1);

namespace NCG;

if (!defined('ABSPATH')) exit;

final class Htaccess {
    public const MARKER = 'News Cache Guard';

    public static function sync(): array {
        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        $file = ABSPATH . '.htaccess';
        if (!file_exists($file)) {
            @touch($file);
        }
        if (!is_writable($file)) {
            return ['ok' => false, 'message' => '.htaccess is not writable. Add the generated rules manually.'];
        }
        $rules = self::rules();
        $ok = insert_with_markers($file, self::MARKER, $rules);
        return ['ok' => (bool)$ok, 'message' => $ok ? 'LiteSpeed cache-key rules synchronized.' : 'Could not update .htaccess.'];
    }

    public static function remove(): void {
        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
        $file = ABSPATH . '.htaccess';
        if (file_exists($file) && is_writable($file)) {
            insert_with_markers($file, self::MARKER, []);
        }
    }

    public static function rules(): array {
        if ((int)Settings::get('query_collapse', 1) !== 1) return [];
        $keys = self::safe_drop_keys(Settings::lines('drop_query_keys'));
        if (!$keys) return [];
        $out = [
            '<IfModule LiteSpeed>',
            '# Cache-key-only query normalization. Browser URL and PHP query string remain unchanged.',
        ];
        foreach ($keys as $key) {
            $out[] = 'CacheKeyModify -qs:' . $key;
        }
        $out[] = '</IfModule>';
        return $out;
    }

    public static function matches_drop_key(string $key): bool {
        foreach (self::safe_drop_keys(Settings::lines('drop_query_keys')) as $rule) {
            if (substr($rule, -1) === '*') {
                if (strpos($key, substr($rule, 0, -1)) === 0) return true;
            } elseif ($rule === $key) {
                return true;
            }
        }
        return false;
    }

    public static function safe_drop_keys(array $keys): array {
        $protected = array_map('strtolower', Settings::lines('protected_query_keys'));
        $out = [];
        foreach ($keys as $key) {
            $key = trim($key);
            if ($key === '' || !preg_match('/^[A-Za-z0-9_\-]+\*?$/', $key)) continue;
            $base = strtolower(rtrim($key, '*'));
            $wildcard = substr($key, -1) === '*';
            foreach ($protected as $protectedKey) {
                if ($base === $protectedKey || ($wildcard && strpos($protectedKey, $base) === 0)) {
                    continue 2;
                }
            }
            $out[] = $key;
        }
        return array_values(array_unique($out));
    }
}
