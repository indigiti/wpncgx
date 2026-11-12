<?php

declare(strict_types=1);

namespace NCG;

if (!defined('ABSPATH')) exit;

final class Settings {
    public const OPTION = 'ncg_settings';
    /** Request-local configuration; separate entries avoid cross-site leakage on Multisite. */
    private static array $requestValues = [];
    private static array $requestLines = [];
    private static bool $booted = false;
    private static int $revision = 0;

    public static function boot(): void {
        if (self::$booted) return;
        self::$booted = true;
        foreach (['updated_option', 'added_option', 'deleted_option'] as $hook) {
            add_action($hook, [self::class, 'invalidate'], 10, 1);
        }
    }

    public static function invalidate(string $option = self::OPTION): void {
        if ($option !== self::OPTION) return;
        self::$requestValues = [];
        self::$requestLines = [];
        ++self::$revision;
    }

    public static function revision(): int { return self::$revision; }

    private static function blog_key(): int {
        return function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 0;
    }

    public static function defaults(): array {
        return [
            'enabled' => 1,
            'dry_run' => 1,
            'emergency_mode' => 0,
            'default_policy' => 'safe_shared',
            'origin_threshold' => 5,
            'origin_window' => 60,
            'protection_duration' => 300,
            'miss_limit' => 2,
            'miss_window' => 60,
            'unique_url_limit' => 3,
            'unique_url_window' => 60,
            'limit_404' => 2,
            'window_404' => 60,
            'search_limit' => 3,
            'search_window' => 60,
            'rest_limit' => 30,
            'rest_window' => 60,
            'ipv4_prefix' => 32,
            'ipv6_prefix' => 64,
            'excess_action' => '429',
            'retry_after' => 60,
            'query_collapse' => 1,
            // Explicit opt-in: ignore client no-cache on cache lookup; does NOT override server response exclusions.
            'ignore_request_no_cache' => 0,
            'drop_query_keys' => "utm*\ngclid\ngbraid\nwbraid\ngad_source\nfbclid\nmsclkid\n_ga\nlmid",
            'protected_query_keys' => "preview\npreview_id\npreview_nonce\naction\nnonce\n_wpnonce\nLSCWP_CTRL\ns\nrest_route",
            'scanner_action' => '404',
            'scanner_paths' => "/.env\n/.git/\n/phpinfo.php\n/wp-config.php.bak\n/vendor/phpunit/\n/.aws/\n/.svn/\n/composer.json\n/composer.lock",
            'xmlrpc_mode' => 'rate_limit',
            'xmlrpc_limit' => 10,
            'trust_cloudflare' => 1,
            'trusted_proxy_cidrs' => self::default_cloudflare_ranges(),
            'allowlist' => '',
            'denylist' => '',
            'rules' => '',
            'force_public_ttl' => 300,
            'purge_home' => 1,
            'purge_categories' => 1,
            'purge_feeds' => 1,
            'purge_sitemap' => 1,
            'debug_headers' => 0,
        ];
    }

    public static function get_all(): array {
        $blog = self::blog_key();
        if (isset(self::$requestValues[$blog])) return self::$requestValues[$blog];
        $saved = get_option(self::OPTION, []);
        if (!is_array($saved)) $saved = [];
        $all = array_merge(self::defaults(), $saved);
        // Retire unsafe Force Cache even if a site previously saved it.
        // Never override LiteSpeed Cache exclusions for personalized output.
        if ($all['default_policy'] === 'force_cache') $all['default_policy'] = 'safe_shared';
        // Strict fallback cap, including sites with an older saved value of 10.
        $all['search_limit'] = 3;
        $all['search_window'] = 60;
        return self::$requestValues[$blog] = $all;
    }

    public static function get(string $key, $default = null) {
        $all = self::get_all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function sanitize(array $in): array {
        $d = self::defaults();
        $out = [];
        $bools = [
            'enabled','dry_run','emergency_mode','query_collapse','ignore_request_no_cache','trust_cloudflare',
            'purge_home','purge_categories','purge_feeds','purge_sitemap','debug_headers'
        ];
        foreach ($bools as $k) $out[$k] = empty($in[$k]) ? 0 : 1;

        $ints = [
            'origin_threshold' => [1,10000], 'origin_window' => [5,3600],
            'protection_duration' => [10,86400], 'miss_limit' => [1,10000],
            'miss_window' => [5,3600], 'unique_url_limit' => [1,10000],
            'unique_url_window' => [5,3600], 'limit_404' => [1,10000],
            'window_404' => [5,3600], 'search_limit' => [1,10000],
            'search_window' => [5,3600], 'rest_limit' => [1,10000],
            'rest_window' => [5,3600], 'retry_after' => [1,3600],
            'xmlrpc_limit' => [1,10000], 'force_public_ttl' => [30,86400],
        ];
        foreach ($ints as $k => [$min,$max]) {
            $v = isset($in[$k]) ? (int)$in[$k] : (int)$d[$k];
            $out[$k] = max($min, min($max, $v));
        }

        // These safety limits cannot be raised through legacy settings.
        $out['search_limit'] = 3;
        $out['search_window'] = 60;

        $out['ipv4_prefix'] = max(8, min(32, (int)($in['ipv4_prefix'] ?? $d['ipv4_prefix'])));
        $out['ipv6_prefix'] = max(32, min(128, (int)($in['ipv6_prefix'] ?? $d['ipv6_prefix'])));

        $out['default_policy'] = in_array(($in['default_policy'] ?? ''), ['monitor','safe_shared','cache_only'], true)
            ? $in['default_policy'] : $d['default_policy'];
        $out['excess_action'] = in_array(($in['excess_action'] ?? ''), ['429','403'], true)
            ? $in['excess_action'] : $d['excess_action'];
        $out['scanner_action'] = in_array(($in['scanner_action'] ?? ''), ['404','403'], true)
            ? $in['scanner_action'] : $d['scanner_action'];
        $out['xmlrpc_mode'] = in_array(($in['xmlrpc_mode'] ?? ''), ['disable','rate_limit','allow'], true)
            ? $in['xmlrpc_mode'] : $d['xmlrpc_mode'];

        foreach (['drop_query_keys','protected_query_keys','scanner_paths','trusted_proxy_cidrs','allowlist','denylist','rules'] as $k) {
            $out[$k] = self::sanitize_multiline((string)($in[$k] ?? ''));
        }
        return $out;
    }

    private static function sanitize_multiline(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_filter(array_map('trim', explode("\n", $text)), static fn($v) => $v !== '');
        $lines = array_map(static function ($line) {
            return preg_replace('/[^A-Za-z0-9_:\/\.\-\*\|, ]/', '', $line) ?? '';
        }, $lines);
        return implode("\n", array_values(array_unique(array_filter($lines))));
    }

    public static function lines(string $key): array {
        $blog = self::blog_key();
        if (isset(self::$requestLines[$blog][$key])) return self::$requestLines[$blog][$key];
        $raw = trim((string)self::get($key, ''));
        $lines = $raw === '' ? [] : array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
        self::$requestLines[$blog][$key] = $lines;
        return $lines;
    }

    private static function default_cloudflare_ranges(): string {
        return implode("\n", [
            '173.245.48.0/20','103.21.244.0/22','103.22.200.0/22','103.31.4.0/22',
            '141.101.64.0/18','108.162.192.0/18','190.93.240.0/20','188.114.96.0/20',
            '197.234.240.0/22','198.41.128.0/17','162.158.0.0/15','104.16.0.0/13',
            '104.24.0.0/14','172.64.0.0/13','131.0.72.0/22',
            '2400:cb00::/32','2606:4700::/32','2803:f800::/32','2405:b500::/32',
            '2405:8100::/32','2a06:98c0::/29','2c0f:f248::/32'
        ]);
    }
}
