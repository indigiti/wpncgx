<?php
/**
 * P3: bounded, read-only background work diagnostics.
 *
 * This code is loaded only in the WordPress admin, and snapshot() is called
 * only by the authorised Tools -> PM Worker Guard page. It never schedules,
 * runs, cancels, claims or reschedules actions.
 */
declare(strict_types=1);
namespace NCG;

if (!defined('ABSPATH')) exit;

final class WorkerDiagnostics {
    private const OVERDUE_SECONDS = 300;

    private static function next_event(string $hook): string {
        $next = wp_next_scheduled($hook);
        if (!is_int($next) || $next <= 0) return 'No matching event is scheduled';
        $description = gmdate('Y-m-d H:i:s', $next) . ' UTC';
        if ($next < time()) {
            $late = time() - $next;
            $description .= ' (' . (int)floor($late / 60) . ' min past due)';
        }
        return $description;
    }

    private static function oldest_action(): array {
        // Only one indexed oldest-pending lookup. Never COUNT(*) the queue,
        // fetch full action objects or load/iterate historical action logs.
        if (!function_exists('as_get_scheduled_actions') ||
            !class_exists('ActionScheduler', false) ||
            !method_exists('ActionScheduler', 'store') ||
            !function_exists('did_action') ||
            !did_action('action_scheduler_init')) {
            return [
                'oldest_pending' => 'Action Scheduler unavailable or not initialized',
                'queue_signal' => 'Unknown — queue not inspected',
            ];
        }

        try {
            $ids = as_get_scheduled_actions([
                'status' => 'pending',
                'per_page' => 1,
                'orderby' => 'date',
                'order' => 'ASC',
            ], 'ids');
            if (!is_array($ids)) {
                return [
                    'oldest_pending' => 'Queue query unavailable',
                    'queue_signal' => 'Unknown — no conclusion about queue health',
                ];
            }
            if (!$ids) {
                return [
                    'oldest_pending' => 'No pending actions found',
                    'queue_signal' => 'No pending actions in this snapshot',
                ];
            }
            $id = (int)reset($ids);
            if ($id <= 0) throw new \RuntimeException('Invalid action ID');
            $store = \ActionScheduler::store();
            $date = $store->get_date($id);
            if (!$date instanceof \DateTimeInterface) {
                throw new \RuntimeException('Unknown action date');
            }
            $scheduled = $date->getTimestamp();
            $delay = time() - $scheduled;
            $oldest = gmdate('Y-m-d H:i:s', $scheduled) . ' UTC';
            if ($delay <= 0) {
                return [
                    'oldest_pending' => $oldest . ' (scheduled in the future)',
                    'queue_signal' => 'No overdue action in the oldest-pending sample',
                ];
            }
            $oldest .= ' (' . (int)floor($delay / 60) . ' min overdue)';
            return [
                'oldest_pending' => $oldest,
                'queue_signal' => $delay >= self::OVERDUE_SECONDS
                    ? 'Potential processing delay — verify queue runner'
                    : 'Under 5 min overdue — not proof of a processing failure',
            ];
        } catch (\Throwable $e) {
            // Fail only the diagnostics, never fail the admin page or try to
            // repair a queue without a verified and monitored server runner.
            return [
                'oldest_pending' => 'Unable to inspect pending action',
                'queue_signal' => 'Unknown — diagnostics query failed',
            ];
        }
    }

    public static function snapshot(): array {
        $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $asyncDisabled = WorkerGuard::disable_async_runner();
        $rows = [
            'wp_cron' => $cronDisabled
                ? 'WP-Cron page-triggered spawning disabled'
                : 'WP-Cron page-triggered spawning permitted',
            'async' => $asyncDisabled
                ? 'Action Scheduler async loopbacks disabled by opt-in'
                : 'Action Scheduler async loopbacks not disabled by Newsroom Guard',
            'next_queue' => self::next_event('action_scheduler_run_queue'),
            'next_cleanup' => self::next_event('ncg_editorial_cleanup'),
        ];
        $rows += self::oldest_action();
        $rows['server_cron'] = 'External server cron execution cannot be verified from WordPress';
        $rows['risk'] = $cronDisabled && $asyncDisabled
            ? 'Check external WP-Cron and Action Scheduler runners before enabling this combination'
            : 'No runner configuration changed; verify actual execution externally';
        return $rows;
    }
}
