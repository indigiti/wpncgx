<?php
/**
 * Plugin Name: Newsroom Guard
 * Description: Newsroom publishing security, editorial approvals, audit intelligence, LiteSpeed caching and origin protection for WordPress.
 * Version: 1.1.0-rc1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: DCN
 * License: GPL-2.0-or-later
 * Text Domain: news-cache-guard
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('NCG_VERSION', '1.1.0-rc1');
define('NCG_FILE', __FILE__);
define('NCG_DIR', plugin_dir_path(__FILE__));
define('NCG_URL', plugin_dir_url(__FILE__));
define('NCG_BASENAME', plugin_basename(__FILE__));

require_once NCG_DIR . 'includes/class-ncg-settings.php';
require_once NCG_DIR . 'includes/class-ncg-modules.php';
require_once NCG_DIR . 'includes/class-ncg-ip.php';
require_once NCG_DIR . 'includes/class-ncg-counter.php';
require_once NCG_DIR . 'includes/class-ncg-litespeed.php';
require_once NCG_DIR . 'includes/class-ncg-htaccess.php';
require_once NCG_DIR . 'includes/class-ncg-purger.php';
require_once NCG_DIR . 'includes/class-ncg-guard.php';
require_once NCG_DIR . 'includes/class-ncg-admin.php';
require_once NCG_DIR . 'includes/class-ncg-worker-guard.php';
require_once NCG_DIR . 'includes/class-ncg-plugin.php';
require_once NCG_DIR . 'includes/class-ncg-editorial.php';
require_once NCG_DIR . 'includes/class-ncg-editorial-hardening.php';
require_once NCG_DIR . 'includes/class-ncg-editorial-workflow.php';
require_once NCG_DIR . 'includes/class-ncg-editorial-admin.php';
require_once NCG_DIR . 'includes/class-ncg-dashboard.php';

register_activation_hook(__FILE__, ['NCG\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['NCG\\Plugin', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    NCG\Plugin::instance()->boot();
}, 1);
