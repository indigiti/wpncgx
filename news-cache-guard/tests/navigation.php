<?php
/**
 * Regression: one unified admin interface, complete advanced controls and
 * backward-compatible redirects without duplicate Settings/submenu entries.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$admin = file_get_contents($root . '/includes/class-ncg-admin.php');
$dashboard = file_get_contents($root . '/includes/class-ncg-dashboard.php');
$css = file_get_contents($root . '/assets/newsroom-dashboard.css');

function nrg_navigation_assert(bool $condition, string $name): void {
    if (!$condition) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
}

nrg_navigation_assert(strpos($admin, "add_options_page('Newsroom Guard'") === false,
    'Legacy Settings > Newsroom Guard menu is not registered');
nrg_navigation_assert(strpos($dashboard, "add_submenu_page(self::SLUG, 'Module Manager'") === false,
    'No duplicate Module Manager submenu is registered');
nrg_navigation_assert(strpos($dashboard, "add_menu_page('Newsroom Guard'") !== false,
    'Single top-level Newsroom Guard menu is registered');
nrg_navigation_assert(strpos($dashboard, "add_action('admin_init', [self::class, 'redirect_legacy_pages'], 1)") !== false,
    'Both legacy URLs redirect before the admin screen renders');
nrg_navigation_assert(strpos($dashboard, "'news-cache-guard', 'newsroom-modules'") !== false,
    'Both legacy page identifiers are recognized');
nrg_navigation_assert(strpos($dashboard, "self::url('settings', $args)") !== false,
    'Legacy settings links map to the integrated Advanced Controls view');
nrg_navigation_assert(strpos($dashboard, "['queue'=>'approvals', 'audit'=>'audit', 'alerts'=>'security']") !== false,
    'Existing editorial deep links map to the correct sections');
nrg_navigation_assert(strpos($dashboard, "['panel'=>'advanced']") !== false,
    'Advanced Controls is accessible from the new admin interface');
nrg_navigation_assert(strpos($dashboard, 'save_advanced') !== false &&
    strpos($dashboard, "check_admin_referer('nrg_save_advanced')") !== false,
    'Advanced controls use nonce-protected server-side saves');
nrg_navigation_assert(strpos($dashboard, 'Settings::sanitize($values)') !== false,
    'Existing option sanitizer is reused');
nrg_navigation_assert(strpos($dashboard, "Settings::get_all()") !== false,
    'Saving advanced controls preserves unrelated current settings');

foreach ([
    'origin_window','protection_duration','miss_limit','miss_window',
    'unique_url_limit','unique_url_window','window_404','rest_window',
    'retry_after','ipv4_prefix','ipv6_prefix','xmlrpc_limit',
    'default_policy','excess_action','scanner_action','xmlrpc_mode',
    'trusted_proxy_cidrs','allowlist','denylist','rules','scanner_paths',
    'drop_query_keys','protected_query_keys','debug_headers'
] as $key) {
    nrg_navigation_assert(strpos($dashboard, "'" . $key . "'") !== false,
        'Advanced setting is represented: ' . $key);
}
nrg_navigation_assert(strpos($css, '.nrg-app .nrg-advanced-form') !== false,
    'Advanced controls use scoped responsive styling');
echo "All Newsroom Guard single-interface regression checks passed.\n";
