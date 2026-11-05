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
        // Do not replace failed atomic increments with non-atomic get/set:
        // simultaneous PHP workers could overwrite each other's counts.
        return $n === false ? 0 : (int)$n;
    }

    public static function increment_unique(string $name, string $identity, string $value, int $window): int {
        if (!self::available()) return 0;
        $bucket = intdiv(time(), max(1, $window));
        $marker = self::key('uniq', $name, $identity, (string)$bucket, md5($value));
        $countKey = self::key('ucnt', $name, $identity, (string)$bucket);
        if (wp_cache_add($marker, 1, self::GROUP, $window + 5)) {
            if (wp_cache_add($countKey, 1, self::GROUP, $window + 5)) return 1;
            $n = wp_cache_incr($countKey, 1, self::GROUP);
            return $n === false ? 0 : (int)$n;
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

    /** Manual, read-only-to-site diagnostic. Writes only short-lived object-cache keys. */
    public static function atomic_probe(): array {
        if (!self::available()) return ['ok' => false, 'reason' => 'No persistent object cache configured'];
        try {
            $key = 'probe:' . bin2hex(random_bytes(12));
            $created = wp_cache_add($key, 1, self::GROUP, 15);
            $next = $created ? wp_cache_incr($key, 1, self::GROUP) : false;
            $value = $created ? wp_cache_get($key, self::GROUP) : false;
            if ($created) wp_cache_delete($key, self::GROUP);
            if (!$created || $next !== 2 || (int)$value !== 2) {
                return ['ok' => false, 'reason' => 'Atomic add/incr/read failed'];
            }
            return ['ok' => true, 'reason' => 'Atomic add/incr/read passed on this request'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'Object-cache operation failed'];
        }
    }

    private static function key(string ...$parts): string {
        return 'ncg:' . implode(':', array_map(static fn($p) => substr(hash('sha256', $p), 0, 20), $parts));
    }
}
