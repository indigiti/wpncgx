<?php
declare(strict_types=1);
namespace NCG;
if (!defined('ABSPATH')) exit;

/**
 * Narrow server-side defenses for WordPress editorial paths.
 *
 * Never rely on a status-transition hook as a pre-publication authorization check:
 * wp_publish_post() updates the row before firing that hook.
 * Direct SQL and arbitrary privileged PHP remain outside the WP API trust boundary.
 */
final class EditorialHardening {
    public static function boot(): void {
        add_filter('rest_pre_insert_post', [self::class, 'rest_pre_insert'], 1, 2);
        add_filter('add_post_metadata', [self::class, 'metadata_guard'], 1, 5);
        add_filter('update_post_metadata', [self::class, 'metadata_guard'], 1, 5);
        add_filter('delete_post_metadata', [self::class, 'metadata_guard'], 1, 5);
        // The core cron callback is normally priority 10. Decide before it can run.
        add_action('publish_future_post', [self::class, 'cron_preflight'], 0, 1);
        add_action('transition_post_status', [self::class, 'mark_admin_schedule'], 5, 3);
    }

    public static function blocked(int $id, int $actor): bool {
        $p = get_post($id);
        if (!$p || $p->post_type !== 'post' || Editorial::bypass($actor)) return false;
        if (in_array($p->post_status, ['publish', 'future'], true) && Editorial::on('approvals')) return true;
        return Editorial::locked($p);
    }

    public static function rest_pre_insert($prepared, $request) {
        if (is_wp_error($prepared)) return $prepared;
        $id = absint($request->get_param('id'));
        if ($id && self::blocked($id, get_current_user_id())) {
            return new \WP_Error('ncg_editorial_protected',
                'This live article is protected. Submit a revision for approval.',
                ['status' => 403]);
        }
        // REST also exposes a separate create path, so reject direct publishing there.
        if (!$id && Editorial::on('approvals')
            && !Editorial::is_admin(get_current_user_id())
            && in_array((string)$request->get_param('status'), ['publish', 'future'], true)) {
            return new \WP_Error('ncg_editorial_review_required',
                'Submit the article as Pending Review for independent approval.',
                ['status' => 403]);
        }
        return $prepared;
    }

    public static function metadata_guard($check, $post_id, $key, $value, $extra = null) {
        if ($check !== null) return $check;
        $post_id = (int)$post_id;
        $key = (string)$key;
        $p = get_post($post_id);
        if (!$p || $p->post_type !== 'post') return $check;
        if (Editorial::$applying) return $check;

        // Internal evidence and approval bookkeeping cannot be forged by editors.
        if (strpos($key, '_ncg_ed_') === 0) return false;
        if (self::blocked($post_id, get_current_user_id())) {
            Editorial::alarm('protected_metadata_blocked', $post_id, ['key'=>$key]);
            return false;
        }
        return $check;
    }

    public static function mark_admin_schedule(string $new, string $old, $post): void {
        if ($post->post_type !== 'post' || $new !== 'future' || $old === 'future') return;
        if (!Editorial::is_admin(get_current_user_id())) return;
        Editorial::$applying = true;
        try {
            update_post_meta($post->ID, '_ncg_ed_schedule_authorized', 'admin:' . get_current_user_id());
        } finally {
            Editorial::$applying = false;
        }
        Editorial::record('admin_scheduled_publication', (int)$post->ID);
    }

    public static function cron_preflight($id): void {
        if (!Editorial::on('approvals')) return;
        $post = get_post((int)$id);
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'future') return;
        if (get_post_meta($post->ID, '_ncg_ed_schedule_authorized', true)) {
            // Core hook may have been removed by an earlier unapproved event
            // in the same WP-Cron runner.
            if (!has_action('publish_future_post', 'check_and_publish_future_post')) {
                add_action('publish_future_post', 'check_and_publish_future_post', 10, 1);
            }
            return;
        }
        remove_action('publish_future_post', 'check_and_publish_future_post', 10);
        Editorial::alarm('unapproved_future_publish_prevented', (int)$id);
        // Preserve the future post and incident evidence for administrator review.
        // Do not move to publish, nor invoke transition hooks.
    }
}
