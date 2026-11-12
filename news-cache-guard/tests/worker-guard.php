<?php
/** Standalone PM Worker Guard checks; does not require WordPress. */
declare(strict_types=1);
define('ABSPATH', '/tmp/wordpress demo/');
$phase = $argv[1] ?? 'default';
if ($phase === 'phase2') {
    define('PMWG_DISABLE_ASYNC_RUNNER', true);
}
if ($phase === 'forcecache') {
    define('PMWG_FORCE_RANKMATH_SITEMAP_CACHE', true);
}
$GLOBALS['pmwg_filters'] = [];
$GLOBALS['pmwg_actions'] = [];
$GLOBALS['pmwg_admin'] = false;
function add_filter($hook, $callback, $priority = 10) {
    $GLOBALS['pmwg_filters'][$hook][] = [$callback, $priority];
}
function add_action($hook, $callback, $priority = 10) {
    $GLOBALS['pmwg_actions'][$hook][] = [$callback, $priority];
}
function is_admin() {
    return $GLOBALS['pmwg_admin'];
}
function current_user_can($cap) {
    return $cap === 'manage_options';
}
function add_management_page(...$args) {
    $GLOBALS['pmwg_page'] = $args;
}
function esc_html($string) { return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8'); }
function esc_url($string) { return (string) $string; }
function admin_url($path) { return '/wp-admin/' . $path; }
function __return_false() { return false; }
function wp_next_scheduled($hook) { $GLOBALS['pmwg_cron_reads'][] = $hook; return false; }
function did_action($hook) { return 0; }

require_once __DIR__ . '/../includes/class-ncg-worker-guard.php';
require_once __DIR__ . '/../includes/class-ncg-worker-diagnostics.php';
function check(bool $ok, string $text): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$text}\n"); exit(1); }
    echo "PASS: {$text}\n";
}
use NCG\WorkerGuard as W;
W::boot();
W::boot();
check(count($GLOBALS['pmwg_filters']['action_scheduler_queue_runner_batch_size']) === 1, 'Boot is idempotent');
check(count($GLOBALS['pmwg_filters']['action_scheduler_queue_runner_concurrent_batches']) === 1, 'Concurrency filter registered');
check(count($GLOBALS['pmwg_filters']['wpseo_sitemap_entries_per_page']) === 1, 'Yoast filter registered');
check(count($GLOBALS['pmwg_filters']['option_rank-math-options-sitemap']) === 1, 'Rank Math filter registered');
check(count($GLOBALS['pmwg_filters']['rank_math/sitemap/enable_caching']) === 1, 'Rank Math cache policy filter registered');
check(isset($GLOBALS['pmwg_filters']['action_scheduler_allow_async_request_runner']) === ($phase === 'phase2'), 'Async filter only when explicitly opted in');
check(W::batch_size() === 5 && W::concurrent_batches() === 1, 'Conservative Action Scheduler defaults');
check(W::yoast_cap() === 250 && W::rank_math_cap() === 200, 'Conservative sitemap defaults');
check(W::action_scheduler_batch_size(25) === 5, 'Queue batch capped at 5');
check(W::action_scheduler_batch_size(2) === 2, 'Lower queue batch preserved');
check(W::action_scheduler_batch_size(0) === 5, 'Invalid queue batch safely falls back');
check(W::action_scheduler_concurrent_batches(10) === 1, 'Queue concurrency capped at 1');
check(W::action_scheduler_concurrent_batches(0) === 1, 'Invalid concurrency safely falls back');
check(W::yoast_sitemap_entries(1000) === 250, 'Yoast entries capped at 250');
check(W::yoast_sitemap_entries(100) === 100, 'Lower Yoast setting preserved');
check(W::rank_math_sitemap_options(['items_per_page' => '500'])['items_per_page'] === '200', 'Rank Math links capped at 200');
check(W::rank_math_sitemap_options(['items_per_page' => '120'])['items_per_page'] === '120', 'Lower Rank Math setting preserved');
check(W::rank_math_sitemap_options([]) === [], 'Unset Rank Math option not fabricated');
check(W::rank_math_sitemap_options(['items_per_page' => '0'])['items_per_page'] === '0', 'Invalid Rank Math option not silently increased');
check(W::rank_math_sitemap_options(false) === false, 'Non-array Rank Math option passed unchanged');
$GLOBALS['pmwg_admin'] = true;
check(W::rank_math_sitemap_options(['items_per_page' => '500'])['items_per_page'] === '500', 'Rank Math admin settings untouched');
check(W::rank_math_sitemap_cache(false) === ($phase === 'forcecache'), 'Explicit cache disable preserved without override');
check(W::rank_math_sitemap_cache(true), 'Rank Math cache enabled state preserved');
W::admin_menu();
check(($GLOBALS['pmwg_page'][3] ?? '') === 'ncg-worker-guard', 'Tools page available');
ob_start();
W::render_admin_page();
$html = ob_get_clean();
check(strpos($html, 'action-scheduler run --batch-size=5 --batches=1') !== false, 'Admin gives correct safe WP-CLI example');
check(strpos($html, 'PM Worker Guard') !== false, 'Admin page title visible');
check(strpos($html, 'Worker and cron readiness (read-only)') !== false, 'On-demand diagnostics are exposed to admin');
check(strpos($html, 'External server cron execution cannot be verified') !== false, 'Worker diagnostics never pretend to confirm server cron');
check(W::disable_async_runner() === ($phase === 'phase2'), 'Async runner disable status matches constant');
if ($phase === 'phase2') {
    ob_start();
    W::async_runner_warning();
    $notice = ob_get_clean();
    check(strpos($notice, 'scheduled actions may stall') !== false, 'Phase 2 warns about potential stranded jobs');
}
echo "All PM Worker Guard {$phase} checks passed.\n";
