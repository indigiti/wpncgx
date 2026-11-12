<?php
/**
 * Real WordPress/MariaDB P3 integration (ephemeral CI site).
 * Reads worker/cron diagnostics without scheduling, running or writing jobs.
 */
if (!defined('ABSPATH') || !class_exists('\NCG\WorkerGuard')) {
    WP_CLI::error('Newsroom Guard must be active');
}
require_once __DIR__ . '/../includes/class-ncg-worker-diagnostics.php';
function ncg_p3_assert($ok, $message) {
    if (!$ok) WP_CLI::error($message);
    WP_CLI::log('PASS: ' . $message);
}
$admin = get_user_by('login', 'admin');
ncg_p3_assert($admin && $admin->ID > 0, 'Integration admin user exists');
wp_set_current_user($admin->ID);

$writes = [];
$write_filter = static function($sql) use (&$writes) {
    // Only count writes generated while inspecting the snapshot.
    if (preg_match('/^\s*(?:INSERT|REPLACE|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE)\s/i', $sql)) {
        $writes[] = $sql;
    }
    return $sql;
};
$cron_before = get_option('cron');
add_filter('query', $write_filter, 999, 1);
$rows = \NCG\WorkerDiagnostics::snapshot();
remove_filter('query', $write_filter, 999);
$cron_after = get_option('cron');
ncg_p3_assert(is_array($rows) && isset($rows['wp_cron'], $rows['next_queue'],
    $rows['oldest_pending'], $rows['queue_signal'], $rows['server_cron']),
    'WordPress cron and queue snapshot renders fields');
ncg_p3_assert(count($writes) === 0, 'Worker snapshot performs zero SQL writes');
ncg_p3_assert($cron_before === $cron_after,
    'WP-Cron schedules left unchanged by the diagnostics');

ob_start();
\NCG\WorkerGuard::render_admin_page();
$page = ob_get_clean();
ncg_p3_assert(strpos($page, 'Worker and cron readiness (read-only)') !== false,
    'Existing Tools Worker Guard page includes the diagnostic section');
ncg_p3_assert(strpos($page, 'External server cron') !== false,
    'Admin page explains that external cron is not verifiable');
WP_CLI::success('P3 worker diagnostics WordPress integration passed.');
