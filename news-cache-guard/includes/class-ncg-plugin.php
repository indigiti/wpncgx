<?php

declare(strict_types=1);

namespace NCG;

if (!defined('ABSPATH')) exit;

final class Plugin {
    private static ?self $instance = null;
    private Guard $guard;
    private Purger $purger;
    private Admin $admin;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function boot(): void {
        $this->guard = new Guard();
        $this->purger = new Purger();
        $this->admin = new Admin();
        WorkerGuard::boot();
        $this->guard->boot();
        $this->purger->boot();
        if (is_admin()) $this->admin->boot();
    }

    public static function activate(): void {
        if (get_option(Settings::OPTION, null) === null) {
            add_option(Settings::OPTION, Settings::defaults(), '', false);
        }
        Htaccess::sync();
    }

    public static function deactivate(): void {
        // Preserve settings and cache-key rules on deactivation to avoid surprise cache fragmentation.
        // Use the admin Query Collapse toggle + Sync button before deactivation if you want the marker removed.
    }
}
