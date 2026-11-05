<?php

declare(strict_types=1);

namespace NCG;

if (!defined('ABSPATH')) exit;

final class Counter {
    private const GROUP = 'news_cache_guard';

    public static function available(): bool {
        return function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    }

    public static function increment(string $name, string $identity, int $window): int {
        if (!self::available()) return 0;
        $bucket = intdiv(time(), max(1, $window));
        $key = self::key('cnt', $name, $identity, (string)$bucket);
        if (wp_cache_add($key, 1, self::GROUP, $window + 5)) return 1;
        $n = wp_cache_incr($key, 1, self::GROUP);
        if ($n === false) {
            $n = (int)wp_cache_get($key, self::GROUP) + 1;
            wp_cache_set($key, $n, self::GROUP, $window + 5);
        }
        return (int)$n;
    }

    public static function increment_unique(string $name, string $identity, string $value, int $window): int {
        if (!self::available()) return 0;
        $bucket = intdiv(time(), max(1, $window));
        $marker = self::key('uniq', $name, $identity, (string)$bucket, md5($value));
        $countKey = self::key('ucnt', $name, $identity, (string)$bucket);
        if (wp_cache_add($marker, 1, self::GROUP, $window + 5)) {
            if (wp_cache_add($countKey, 1, self::GROUP, $window + 5)) return 1;
            $n = wp_cache_incr($countKey, 1, self::GROUP);
            return $n === false ? 1 : (int)$n;
        }
        $n = wp_cache_get($countKey, self::GROUP);
        return $n === false ? 0 : (int)$n;
    }

    public static function set_flag(string $name, string $identity, int $ttl): void {
        if (!self::available()) return;
        wp_cache_set(self::key('flag', $name, $identity), 1, self::GROUP, $ttl);
    }

    public static function has_flag(string $name, string $identity): bool {
        if (!self::available()) return false;
        return wp_cache_get(self::key('flag', $name, $identity), self::GROUP) !== false;
    }

    public static function delete_flag(string $name, string $identity): void {
        if (!self::available()) return;
        wp_cache_delete(self::key('flag', $name, $identity), self::GROUP);
    }

    public static function stat(string $name, int $ttl = 86400): int {
        if (!self::available()) return 0;
        $key = self::key('stat', $name, gmdate('Ymd'));
        if (wp_cache_add($key, 1, self::GROUP, $ttl)) return 1;
        $n = wp_cache_incr($key, 1, self::GROUP);
        return $n === false ? 0 : (int)$n;
    }

    public static function stat_value(string $name): int {
        if (!self::available()) return 0;
        $v = wp_cache_get(self::key('stat', $name, gmdate('Ymd')), self::GROUP);
        return $v === false ? 0 : (int)$v;
    }

    private static function key(string ...$parts): string {
        return 'ncg:' . implode(':', array_map(static fn($p) => substr(hash('sha256', $p), 0, 20), $parts));
    }
}
