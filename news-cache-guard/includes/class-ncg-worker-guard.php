<?php
/**
 * PM Worker Guard integration for Newsroom Guard.
 * Limits Action Scheduler queue claims and SEO XML sitemap batch sizes; it does
 * not alter PHP-FPM process-manager settings or kill running workers.
 */

declare(strict_types=1);

namespace NCG;

if (!defined('ABSPATH')) {
    exit;
}

final class WorkerGuard {
    public const VERSION = '1.0.4';
    private static bool $booted = false;

    public static function boot(): void {
        if (self::$booted) return;
        self::$booted = true;
        if (class_exists(Modules::class, false) && !Modules::on('worker')) {
            // Diagnostics remain accessible when limits are disabled.
            if (is_admin()) add_action('admin_menu', [__CLASS__, 'admin_menu']);
            return;
        }

        add_filter('action_scheduler_queue_runner_batch_size', [__CLASS__, 'action_scheduler_batch_size'], 999);
        add_filter('action_scheduler_queue_runner_concurrent_batches', [__CLASS__, 'action_scheduler_concurrent_batches'], 999);

        // The async loopback runner may only be stopped by an explicit wp-config constant.
        // If no server-side queue runner exists, disabling it strands due actions.
        if (self::disable_async_runner()) {
            add_filter('action_scheduler_allow_async_request_runner', '__return_false', 999);
        }

        add_filter('wpseo_sitemap_entries_per_page', [__CLASS__, 'yoast_sitemap_entries'], 999);
        add_filter('option_rank-math-options-sitemap', [__CLASS__, 'rank_math_sitemap_options'], 999);
        add_filter('rank_math/sitemap/enable_caching', [__CLASS__, 'rank_math_sitemap_cache'], 999);

        if (is_admin()) {
            add_action('admin_menu', [__CLASS__, 'admin_menu']);
            if (self::disable_async_runner()) {
                add_action('admin_notices', [__CLASS__, 'async_runner_warning']);
            }
        }
    }

    private static function clamp_override(string $constant, int $default, int $min, int $max): int {
        $value = defined($constant) ? (int) constant($constant) : $default;
        return max($min, min($max, $value));
    }

    public static function batch_size(): int {
        return self::clamp_override('PMWG_ACTION_SCHEDULER_BATCH_SIZE', 5, 1, 25);
    }

    public static function concurrent_batches(): int {
        return self::clamp_override('PMWG_ACTION_SCHEDULER_CONCURRENT_BATCHES', 1, 1, 5);
    }

    public static function yoast_cap(): int {
        return self::clamp_override('PMWG_YOAST_LINKS_PER_SITEMAP', 250, 50, 1000);
    }

    public static function rank_math_cap(): int {
        return self::clamp_override('PMWG_RANKMATH_LINKS_PER_SITEMAP', 200, 50, 1000);
    }

    public static function action_scheduler_batch_size($batch_size): int {
        // A separate plugin may have already set a lower limit: do not increase it.
        $existing = is_numeric($batch_size) ? (int) $batch_size : 0;
        return $existing > 0 ? min($existing, self::batch_size()) : self::batch_size();
    }

    public static function action_scheduler_concurrent_batches($concurrent_batches): int {
        $existing = is_numeric($concurrent_batches) ? (int) $concurrent_batches : 0;
        return $existing > 0 ? min($existing, self::concurrent_batches()) : self::concurrent_batches();
    }

    public static function yoast_sitemap_entries($entries): int {
        $current = is_numeric($entries) ? (int) $entries : 0;
        return $current > 0 ? min($current, self::yoast_cap()) : self::yoast_cap();
    }

    public static function rank_math_sitemap_options($options) {
        if (!is_array($options) || is_admin()) {
            return $options;
        }
        // Do not replace unset or invalid values: Rank Math may have safer defaults.
        if (!isset($options['items_per_page']) || !is_numeric($options['items_per_page'])) {
            return $options;
        }
        $current = (int) $options['items_per_page'];
        if ($current > self::rank_math_cap()) {
            $options['items_per_page'] = (string) self::rank_math_cap();
        }
        // Existing smaller settings are preserved, without changing wp_options.
        return $options;
    }

    public static function rank_math_sitemap_cache($enabled): bool {
        // Rank Math normally enables its own transient caching. Respect an explicit
        // false from another integration unless site operators deliberately opt in.
        if (defined('PMWG_FORCE_RANKMATH_SITEMAP_CACHE') && PMWG_FORCE_RANKMATH_SITEMAP_CACHE === true) {
            return true;
        }
        return (bool) $enabled;
    }

    public static function disable_async_runner(): bool {
        return defined('PMWG_DISABLE_ASYNC_RUNNER') && PMWG_DISABLE_ASYNC_RUNNER === true;
    }

    public static function admin_menu(): void {
        add_management_page('PM Worker Guard', 'PM Worker Guard', 'manage_options', 'ncg-worker-guard', [__CLASS__, 'render_admin_page']);
    }

    public static function async_runner_warning(): void {
        if (!self::disable_async_runner() || !current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>PM Worker Guard:</strong> Action Scheduler async loopbacks are disabled. Confirm a working WP-CLI/server cron; otherwise scheduled actions may stall.</p></div>';
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $as = class_exists('ActionScheduler', false) || function_exists('as_schedule_single_action');
        $rank = defined('RANK_MATH_VERSION') || class_exists('RankMath', false) || class_exists('\\RankMath\\Helper', false);
        $yoast = defined('WPSEO_VERSION') || class_exists('WPSEO_Options', false);
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        // Use explicit --path; escaping protects paths with spaces or quote characters.
        $cli = 'wp --path=' . escapeshellarg(ABSPATH) . ' action-scheduler run --batch-size=' . self::batch_size() . ' --batches=1';
        $cache_override = defined('PMWG_FORCE_RANKMATH_SITEMAP_CACHE') && PMWG_FORCE_RANKMATH_SITEMAP_CACHE === true;
        $rows = [
            'Action Scheduler detected' => $as ? 'Yes' : 'No',
            'Queue runner max actions/batch' => (string) self::batch_size(),
            'Queue runner max concurrent batches' => (string) self::concurrent_batches(),
            'Web async runner disabled' => self::disable_async_runner() ? 'Yes (requires verified server cron)' : 'No',
            'Rank Math detected' => $rank ? 'Yes' : 'No',
            'Rank Math links per sitemap cap' => (string) self::rank_math_cap(),
            'Rank Math sitemap cache policy' => $cache_override ? 'Force enabled (opt-in)' : 'Respect Rank Math and other filters',
            'Yoast detected' => $yoast ? 'Yes' : 'No',
            'Yoast entries per sitemap cap' => (string) self::yoast_cap(),
            'DISABLE_WP_CRON' => $cron_disabled ? 'Yes (verify server cron)' : 'No',
        ];
        echo '<div class="wrap"><h1>PM Worker Guard <small>v' . esc_html(self::VERSION) . '</small></h1>';
        echo '<p>Conservative Action Scheduler queue claims and SEO sitemap sizes. This module does not cap PHP-FPM workers or guarantee that WP-CLI cron is running.</p>';
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table>';
        // Bounded admin-only diagnostics. One oldest-pending action lookup;
        // no cron modification, queue polling, or full backlog COUNT(*).
        echo '<h2>Worker and cron readiness (read-only)</h2>';
        echo '<p>Current snapshot for this admin page. An overdue action is a warning, not proof that a worker has failed. External server cron cannot be validated from PHP.</p>';
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        $diagnosticLabels = [
            'wp_cron'=>'WP-Cron trigger',
            'async'=>'Action Scheduler async',
            'next_queue'=>'Next WP-Cron queue event',
            'next_cleanup'=>'Next editorial cleanup event',
            'oldest_pending'=>'Oldest pending Action Scheduler job',
            'queue_signal'=>'Queue indication',
            'server_cron'=>'External server cron',
            'risk'=>'Operational check',
        ];
        foreach (WorkerDiagnostics::snapshot() as $key=>$value) {
            echo '<tr><th scope="row">' . esc_html($diagnosticLabels[$key] ?? $key) .
                '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Validate queue execution before disabling async requests</h2>';
        echo '<p>Run this from an SSH session first (without <code>--quiet</code>) and inspect Action Scheduler pending/failed counts:</p>';
        echo '<pre style="padding:12px;background:#fff;max-width:900px;overflow:auto;border:1px solid #ccd0d4">' . esc_html($cli) . '</pre>';
        echo '<p>Once confirmed, use a once-per-minute cron with the same command plus <code>--quiet</code>, and only then add to <code>wp-config.php</code>:</p>';
        echo '<pre style="padding:12px;background:#fff;max-width:900px;overflow:auto;border:1px solid #ccd0d4">define( \'PMWG_DISABLE_ASYNC_RUNNER\', true );</pre>';
        echo '<h2>Optional wp-config.php overrides</h2>';
        echo '<pre style="padding:12px;background:#fff;max-width:900px;overflow:auto;border:1px solid #ccd0d4">define( \'PMWG_ACTION_SCHEDULER_BATCH_SIZE\', 5 );' . "\n"
            . 'define( \'PMWG_ACTION_SCHEDULER_CONCURRENT_BATCHES\', 1 );' . "\n"
            . 'define( \'PMWG_RANKMATH_LINKS_PER_SITEMAP\', 200 );' . "\n"
            . 'define( \'PMWG_YOAST_LINKS_PER_SITEMAP\', 250 );</pre>';
        echo '<p>The sitemap caps may change sitemap pagination. After rollout, flush Rank Math/Yoast sitemap caches and verify sitemap indexes. <a href="' . esc_url(admin_url('options-general.php?page=news-cache-guard')) . '">Back to Newsroom Guard</a>.</p>';
        echo '</div>';
    }
}
