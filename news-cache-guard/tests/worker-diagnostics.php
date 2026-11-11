<?php
/**
 * P3 admin-only queue visibility with bounded Action Scheduler queries.
 * Usage: php tests/worker-diagnostics.php unavailable|future|overdue|empty|error|both-off
 */
declare(strict_types=1);
define('ABSPATH', __DIR__);
$phase = (string)($argv[1] ?? 'unavailable');
if ($phase === 'both-off') {
    define('DISABLE_WP_CRON', true);
    define('PMWG_DISABLE_ASYNC_RUNNER', true);
}
$GLOBALS['diagnostic_phase'] = $phase;
$GLOBALS['diagnostic_queries'] = [];
$GLOBALS['diagnostic_scheduled'] = 0;
$GLOBALS['diagnostic_cron_checks'] = [];
function wp_next_scheduled($hook) {
    $GLOBALS['diagnostic_cron_checks'][] = $hook;
    return $hook === 'action_scheduler_run_queue' ? time() + 120 : false;
}
function did_action($hook) { return 1; }
function check_diagnostic($yes, string $label): void {
    if (!$yes) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
if ($phase !== 'unavailable') {
    final class ActionScheduler {
        public static function store() {
            return new class {
                public function get_date($id) {
                    if ($GLOBALS['diagnostic_phase'] === 'error') {
                        throw new \RuntimeException('Store failure');
                    }
                    if ($GLOBALS['diagnostic_phase'] === 'future') $time = time() + 900;
                    elseif ($GLOBALS['diagnostic_phase'] === 'overdue') $time = time() - 720;
                    else $time = time() - 45;
                    return new \DateTimeImmutable('@' . $time);
                }
            };
        }
    }
    function as_get_scheduled_actions($args = [], $return_format = 'OBJECT') {
        $GLOBALS['diagnostic_queries'][] = [$args, $return_format];
        return $GLOBALS['diagnostic_phase'] === 'empty' ? [] : [123];
    }
}
require_once __DIR__.'/../includes/class-ncg-worker-guard.php';
require_once __DIR__.'/../includes/class-ncg-worker-diagnostics.php';
$rows = \NCG\WorkerDiagnostics::snapshot();
check_diagnostic(is_array($rows) && isset($rows['next_queue'], $rows['oldest_pending'], $rows['server_cron']),
    'Read-only snapshot returns current scheduling signals');
check_diagnostic(count($GLOBALS['diagnostic_cron_checks']) === 2,
    'Only two explicitly selected WP-Cron schedules are examined');
check_diagnostic($GLOBALS['diagnostic_scheduled'] === 0,
    'Inspection never schedules or runs any tasks');
check_diagnostic(strpos($rows['server_cron'], 'cannot be verified') !== false,
    'External server runner is never assumed healthy');
if ($phase === 'unavailable') {
    check_diagnostic(strpos($rows['oldest_pending'], 'unavailable') !== false,
        'Missing scheduler reported without pretending queue empty');
    check_diagnostic(count($GLOBALS['diagnostic_queries']) === 0, 'No queue queries without scheduler');
} else {
    check_diagnostic(count($GLOBALS['diagnostic_queries']) === 1, 'One bounded pending-action query');
    [$args,$format] = $GLOBALS['diagnostic_queries'][0];
    check_diagnostic(($args['status'] ?? '') === 'pending' &&
        ($args['per_page'] ?? 0) === 1 &&
        ($args['orderby'] ?? '') === 'date' &&
        ($args['order'] ?? '') === 'ASC' &&
        $format === 'ids',
        'Query uses one oldest pending action ID without counts or full objects');
}
if ($phase === 'future') {
    check_diagnostic(strpos($rows['oldest_pending'], 'scheduled in the future') !== false,
        'Future actions are not reported as overdue');
}
if ($phase === 'overdue' || $phase === 'both-off') {
    check_diagnostic(strpos($rows['queue_signal'], 'Potential processing delay') !== false,
        'An old pending action is flagged as potentially delayed, not automatically repaired');
}
if ($phase === 'empty') {
    check_diagnostic($rows['oldest_pending'] === 'No pending actions found',
        'Empty pending queue reported accurately');
}
if ($phase === 'error') {
    check_diagnostic(strpos($rows['queue_signal'], 'Unknown') !== false,
        'Read failure is safely reported as unknown');
}
if ($phase === 'both-off') {
    check_diagnostic(strpos($rows['risk'], 'Check external WP-Cron') !== false,
        'Simultaneously disabling two runners raises a warning without changing them');
}
echo "All P3 worker diagnostics checks passed for " . $phase . ".\n";
