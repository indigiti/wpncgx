<?php
/**
 * Plugin Name: News Cache Guard for LiteSpeed
 * Description: Origin protection and shared LiteSpeed cache controls for high-traffic WordPress news sites.
 * Version: 1.0.4
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

define('NCG_VERSION', '1.0.4');
define('NCG_FILE', __FILE__);
define('NCG_DIR', plugin_dir_path(__FILE__));
define('NCG_BASENAME', plugin_basename(__FILE__));

require_once NCG_DIR . 'includes/class-ncg-settings.php';
require_once NCG_DIR . 'includes/class-ncg-ip.php';
require_once NCG_DIR . 'includes/class-ncg-counter.php';
require_once NCG_DIR . 'includes/class-ncg-litespeed.php';
require_once NCG_DIR . 'includes/class-ncg-htaccess.php';
require_once NCG_DIR . 'includes/class-ncg-purger.php';
require_once NCG_DIR . 'includes/class-ncg-guard.php';
require_once NCG_DIR . 'includes/class-ncg-admin.php';
require_once NCG_DIR . 'includes/class-ncg-worker-guard.php';
require_once NCG_DIR . 'includes/class-ncg-plugin.php';

register_activation_hook(__FILE__, ['NCG\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['NCG\\Plugin', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    NCG\Plugin::instance()->boot();
}, 1);
