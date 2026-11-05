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
        return ['ok' => (bool)$ok, 'message' => $ok ? 'LiteSpeed cache-key/request-lookup rules synchronized.' : 'Could not update .htaccess.'];
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
        $keys = (int)Settings::get('query_collapse', 1) === 1
            ? self::safe_drop_keys(Settings::lines('drop_query_keys')) : [];
        $reuse = (int)Settings::get('ignore_request_no_cache', 0) === 1;
        if (!$keys && !$reuse) return [];
        $out = ['<IfModule LiteSpeed>'];
        if ($reuse) {
            // Request-side lookup only: reuses an existing eligible cached response
            // even if the client sends Cache-Control/Pragma: no-cache. It MUST NOT
            // force responses into public cache or override LSCWP's privacy rules.
            // Applies at the .htaccess directory scope; cannot identify bot identity.
            $out[] = '# OPT-IN: re-use existing cache on client no-cache requests. Applies to all requests in this directory.';
            $out[] = 'CacheIgnoreCacheControl On';
        }
        if ($keys) {
            $out[] = '# Cache-key-only query normalization. Browser URL and PHP query string remain unchanged.';
            foreach ($keys as $key) {
                $out[] = 'CacheKeyModify -qs:' . $key;
            }
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
