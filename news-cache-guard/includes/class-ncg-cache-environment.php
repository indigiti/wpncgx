<?php
/**
 * Admin-only cache environment hints. No network calls, no cache mutation.
 * These signals are not evidence that any request was served from cache.
 */
declare(strict_types=1);
namespace NCG;
if (!defined('ABSPATH')) exit;

final class CacheEnvironment {
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
