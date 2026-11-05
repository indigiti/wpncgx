<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
require_once __DIR__ . '/includes/class-ncg-settings.php';
require_once __DIR__ . '/includes/class-ncg-htaccess.php';
if (class_exists('NCG\\Htaccess')) { NCG\Htaccess::remove(); }
delete_option('ncg_settings');
