<?php
/**
 * Admin-only cache environment hints. No network calls, no cache mutation.
 * These signals are not evidence that any request was served from cache.
 */
declare(strict_types=1);
namespace NCG;
if (!defined('ABSPATH')) exit;

final class CacheEnvironment {
    /**
     * Cheap, read-only snapshot of THIS administrator PHP request so far.
     * These are not server CPU/RAM totals, frontend cache-hit measurements or
     * page-load benchmarks. No SQL, Redis or outbound requests are added.
     */
    public static function resource_snapshot(): array {
        $elapsed = '';
        if (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])) {
            $seconds = microtime(true) - (float)$_SERVER['REQUEST_TIME_FLOAT'];
            if ($seconds >= 0 && $seconds < 3600) {
                $elapsed = number_format($seconds * 1000, 1, '.', '') . ' ms';
            }
        }
        return [
            'elapsed' => $elapsed !== '' ? $elapsed : 'Unavailable',
            'memory' => number_format(memory_get_usage(true) / 1048576, 1, '.', '') . ' MiB',
            'peak' => number_format(memory_get_peak_usage(true) / 1048576, 1, '.', '') . ' MiB',
            'queries' => function_exists('get_num_queries') ? (string)get_num_queries() : 'Unavailable',
            'object_cache' => function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()
                ? 'External object cache configured' : 'No external object cache detected',
        ];
    }

    public static function signals(): array {
        $software = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
        if (stripos($software, 'litespeed') !== false) $origin = 'LiteSpeed server header';
        elseif (stripos($software, 'nginx') !== false) $origin = 'Nginx server header';
        elseif (stripos($software, 'apache') !== false) $origin = 'Apache server header';
        else $origin = 'Not identified from PHP server headers';

        // Plugin activation is only a hint: Varnish and edge caching cannot be
        // verified by looking at a WordPress plugin list or PHP response.
        $active = (array)get_option('active_plugins', []);
        $breeze = in_array('breeze/breeze.php', $active, true);
        if (function_exists('is_multisite') && is_multisite()) {
            $network = (array)get_site_option('active_sitewide_plugins', []);
            $breeze = $breeze || array_key_exists('breeze/breeze.php', $network);
        }

        return [
            'origin' => $origin,
            'litespeed' => LiteSpeed::detected()
                ? 'LiteSpeed integration signal detected (HIT not verified)'
                : 'LiteSpeed integration not detected',
            'breeze' => $breeze ? 'Breeze is listed as active' : 'Breeze not listed as active',
            'varnish' => 'Unverified from PHP; inspect Cloudways and response headers',
            'edge' => 'Unverified from PHP; inspect Cloudflare response headers',
        ];
    }
}
