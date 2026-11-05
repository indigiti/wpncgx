<?php
/**
 * Plugin Name: WP Search Guard (MU)
 * Description: Enforce three WordPress front-end searches per visitor per fixed 60-second window before the main query.
 * Version: 1.0.3
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 *
 * Install this *file*, not its enclosing directory, as wp-content/mu-plugins/wp-search-guard.php.
 * Requires a shared persistent object cache with atomic wp_cache_add()/wp_cache_incr().
 */

declare(strict_types=1);

namespace NCG\SearchMU;

if (!defined('ABSPATH')) {
    exit;
}

// The ordinary plugin checks HANDLED, not LOADED: a nonstandard search route
// that this MU plugin does not recognize must still use the ordinary fallback.
define('NCG_MU_SEARCH_GUARD_LOADED', true);

const LIMIT = 3;
const WINDOW = 60;
const MAX_TERM_BYTES = 160;
const CACHE_GROUP = 'wp_search_guard';

/** Identify WordPress's standard query and pretty-permalink search URLs. */
function is_search_route(array $query, string $requestUri): bool {
    if (array_key_exists('s', $query)) {
        return true;
    }
    $path = (string)(parse_url($requestUri, PHP_URL_PATH) ?: '/');
    return (bool)preg_match('~(?:^|/)search(?:/|$)~i', rawurldecode($path));
}

/** No authentication/pluggable API calls: muplugins_loaded is too early for those. */
function is_eligible_request(): bool {
    if (PHP_SAPI === 'cli' || (defined('WP_CLI') && WP_CLI) || is_admin()) {
        return false;
    }
    if ((function_exists('wp_doing_cron') && wp_doing_cron())
        || (defined('DOING_AJAX') && DOING_AJAX)
        || (defined('REST_REQUEST') && REST_REQUEST)) {
        return false;
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }
    return is_search_route($_GET, (string)($_SERVER['REQUEST_URI'] ?? '/'));
}

/** Match an IP to a known trusted Cloudflare network; never trust unvalidated headers. */
function in_cidr(string $ip, string $cidr): bool {
    $parts = explode('/', trim($cidr), 2);
    $ipBin = @inet_pton($ip);
    $network = @inet_pton($parts[0]);
    if ($ipBin === false || $network === false || strlen($ipBin) !== strlen($network)) {
        return false;
    }
    $bits = strlen($ipBin) * 8;
    $prefix = isset($parts[1]) && ctype_digit($parts[1]) ? (int)$parts[1] : $bits;
    if ($prefix > $bits) {
        return false;
    }
    $full = intdiv($prefix, 8);
    if (substr($ipBin, 0, $full) !== substr($network, 0, $full)) {
        return false;
    }
    if ($prefix % 8 === 0) {
        return true;
    }
    $mask = (0xff << (8 - ($prefix % 8))) & 0xff;
    return (ord($ipBin[$full]) & $mask) === (ord($network[$full]) & $mask);
}

/** Cloudflare's public address ranges (keep synchronized when they change). */
function cloudflare_ranges(): array {
    return [
        '173.245.48.0/20','103.21.244.0/22','103.22.200.0/22','103.31.4.0/22',
        '141.101.64.0/18','108.162.192.0/18','190.93.240.0/20','188.114.96.0/20',
        '197.234.240.0/22','198.41.128.0/17','162.158.0.0/15','104.16.0.0/13',
        '104.24.0.0/14','172.64.0.0/13','131.0.72.0/22',
        '2400:cb00::/32','2606:4700::/32','2803:f800::/32','2405:b500::/32',
        '2405:8100::/32','2a06:98c0::/29','2c0f:f248::/32',
    ];
}

/** Resolve Cloudflare only from a verified connecting proxy; ignore spoofed headers. */
function client_ip(): ?string {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
        return null;
    }
    $settings = function_exists('get_option') ? get_option('ncg_settings', []) : [];
    $settings = is_array($settings) ? $settings : [];
    $trust = !isset($settings['trust_cloudflare']) || (int)$settings['trust_cloudflare'] === 1;
    if ($trust) {
        $networks = isset($settings['trusted_proxy_cidrs']) && is_string($settings['trusted_proxy_cidrs'])
            ? (preg_split('/\R/', $settings['trusted_proxy_cidrs']) ?: []) : cloudflare_ranges();
        foreach ($networks as $cidr) {
            if (in_cidr($remote, (string)$cidr)) {
                // Never group an entire Cloudflare edge behind one rate-limit key.
                // Without a validated visitor IP, fail open instead.
                $cf = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
                return filter_var($cf, FILTER_VALIDATE_IP) !== false ? $cf : null;
            }
        }
    }
    return $remote;
}

/** Equal IPv6 /64 buckets prevent trivial evasion by rotating interface IDs. */
function ip_identity(string $ip): ?string {
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return null;
    }
    if (strlen($bin) === 16) {
        $bin = substr($bin, 0, 8); // IPv6 /64, as in News Cache Guard defaults.
    }
    // An HMAC prevents offline dictionary recovery of low-entropy IPv4 addresses
    // from exposed cache keys when normal WordPress auth salts are configured.
    return defined('AUTH_SALT') && strlen((string)AUTH_SALT) >= 16
        ? hash_hmac('sha256', $bin, AUTH_SALT)
        : hash('sha256', $bin);
}

/** Side-effect-free apart from atomic object-cache operations; easy to regression-test. */
function evaluate(int $now): array {
    if (array_key_exists('s', $_GET)) {
        $term = $_GET['s'];
        if (!is_string($term) || strlen($term) > MAX_TERM_BYTES) {
            return ['status' => 400, 'retry_after' => 0];
        }
    }

    if (!function_exists('wp_using_ext_object_cache') || !wp_using_ext_object_cache()) {
        return ['status' => 0, 'retry_after' => 0]; // Explicit fail open.
    }
    $ip = client_ip();
    $identity = $ip === null ? null : ip_identity($ip);
    if ($identity === null) {
        return ['status' => 0, 'retry_after' => 0];
    }
    $windowId = intdiv($now, WINDOW);
    $remaining = WINDOW - ($now % WINDOW);
    // The key holds a hash, not the visitor's raw IP. 5s expiry slack.
    $key = 'sg_' . hash('sha256', $identity . ':' . $windowId);
    if (wp_cache_add($key, 1, CACHE_GROUP, $remaining + 5)) {
        return ['status' => 0, 'retry_after' => 0];
    }
    $count = wp_cache_incr($key, 1, CACHE_GROUP);
    if ($count === false) {
        return ['status' => 0, 'retry_after' => 0]; // Redis failure: fail open.
    }
    return (int)$count > LIMIT
        ? ['status' => 429, 'retry_after' => $remaining]
        : ['status' => 0, 'retry_after' => 0];
}

function run(): void {
    if (!is_eligible_request()) {
        return;
    }
    // Signals the regular plugin not to count this request a second time.
    define('NCG_MU_SEARCH_GUARD_HANDLED', true);
    $result = evaluate(time());
    if ($result['status'] === 0) {
        return;
    }
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    status_header((int)$result['status']);
    header('Cache-Control: private, no-store, max-age=0');
    header('Content-Type: text/plain; charset=UTF-8');
    if ($result['status'] === 429) {
        header('Retry-After: ' . (int)$result['retry_after']);
        echo 'Too many searches. Please try again shortly.';
    } else {
        echo 'Invalid search query.';
    }
    exit;
}

add_action('muplugins_loaded', __NAMESPACE__ . '\\run', 0);
