<?php

declare(strict_types=1);

namespace NCG;

if (!defined('ABSPATH')) exit;

final class LiteSpeed {
    public static function detected(): bool {
        return defined('LSCWP_V') || has_action('litespeed_init') !== false || isset($_SERVER['X-LSCACHE']);
    }

    public static function safe_public_cache(): void {
        do_action('litespeed_control_set_cacheable', 'News Cache Guard: safe public content');
    }

    public static function force_public_cache(): void {
        do_action('litespeed_control_force_cacheable', 'News Cache Guard: trusted force-cache policy');
        do_action('litespeed_control_force_public', 'News Cache Guard: anonymous public response');
        do_action('litespeed_control_set_ttl', (int)Settings::get('force_public_ttl', 300));
    }

    public static function no_cache(): void {
        do_action('litespeed_control_set_nocache', 'News Cache Guard: protected/dynamic request');
    }

    public static function purge_url(string $url): void {
        if ($url !== '') do_action('litespeed_purge_url', $url);
    }
}
