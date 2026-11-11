<?php

declare(strict_types=1);

namespace NCG;

if (!defined('ABSPATH')) exit;

final class LiteSpeed {
    public static function detected(): bool {
        return defined('LSCWP_V') || has_action('litespeed_init') !== false || isset($_SERVER['X-LSCACHE']);
    }

    public static function safe_public_cache(): void {
        do_action('litespeed_control_set_cacheable', 'Newsroom Guard: safe public content');
    }

    public static function no_cache(): void {
        do_action('litespeed_control_set_nocache', 'Newsroom Guard: protected/dynamic request');
    }

    public static function purge_url(string $url): void {
        if ($url !== '') do_action('litespeed_purge_url', $url);
    }
}
