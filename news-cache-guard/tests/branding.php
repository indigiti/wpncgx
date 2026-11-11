<?php
/**
 * Static branding/upgrade compatibility regression.
 * Keep the old slug and .htaccess marker even when the visible name changes.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/news-cache-guard.php');
$admin = file_get_contents($root . '/includes/class-ncg-admin.php');
$dashboard = file_get_contents($root . '/includes/class-ncg-dashboard.php');
$editorial_admin = file_get_contents($root . '/includes/class-ncg-editorial-admin.php');
$worker = file_get_contents($root . '/includes/class-ncg-worker-guard.php');
$htaccess = file_get_contents($root . '/includes/class-ncg-htaccess.php');
$settings = file_get_contents($root . '/includes/class-ncg-settings.php');
$readme = file_get_contents($root . '/readme.txt');
function branding_check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    echo "PASS: $message\n";
}
branding_check((bool)preg_match('/^\s*\* Plugin Name: Newsroom Guard\s*$/m', $plugin), 'WordPress Plugins list shows Newsroom Guard');
branding_check(strpos($dashboard, "add_menu_page('Newsroom Guard', 'Newsroom Guard'") !== false, 'Unified admin menu says Newsroom Guard');
branding_check(strpos($admin, "add_options_page('Newsroom Guard', 'Newsroom Guard'") === false, 'Legacy Settings menu is hidden');
branding_check(strpos($admin, '<h1>Newsroom Guard ') !== false, 'Main administration heading says Newsroom Guard');
branding_check(strpos($editorial_admin, '<h1>Newsroom Guard ') !== false, 'Editorial administration heading says Newsroom Guard');
branding_check(strpos($worker, 'Back to Newsroom Guard') !== false, 'Worker Guard navigation uses new brand');
branding_check(strpos($readme, '=== Newsroom Guard ===') === 0, 'WordPress readme displays new brand');
branding_check(strpos($plugin, 'Text Domain: news-cache-guard') !== false, 'Existing translation text domain preserved');
branding_check(strpos($settings, "public const OPTION = 'ncg_settings';") !== false, 'Existing settings option preserved');
branding_check(strpos($htaccess, "public const MARKER = 'News Cache Guard';") !== false, 'Legacy .htaccess marker preserved for safe upgrades');
echo "Newsroom Guard branding and backwards compatibility checks passed.\n";
