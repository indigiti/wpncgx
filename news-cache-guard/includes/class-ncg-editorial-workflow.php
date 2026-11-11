<?php
declare(strict_types=1);
namespace NCG;
if (!defined('ABSPATH')) exit;

/**
 * Approval decisions are server-side and tied to immutable submitted snapshots.
 * Gutenberg's ordinary Publish button cannot approve a submission.
 */
final class EditorialWorkflow {
    private static bool $internal = false;
    public static function boot(): void {
        add_filter('wp_insert_post_data', [self::class, 'gate'], 9999, 4);
        add_filter('user_has_cap', [self::class, 'publish_cap'], 999, 4);
        add_action('wp_after_insert_post', [self::class, 'queue'], 40, 3);
        add_action('transition_post_status', [self::class, 'transition_guard'], 1, 3);
        add_action('admin_post_ncg_ed_review', [self::class, 'review']);
        add_action('admin_post_ncg_ed_propose', [self::class, 'propose']);
    }
    public static function publish_cap(array $all, array $required, array $args, $user): array {
        if (Editorial::on('approvals') && $user && !Editorial::is_admin((int)$user->ID)) {
            // Runtime-only; no global WordPress role changes. The Gutenberg UI
            // offers Submit for Review instead of a misleading Publish button.
            $all['publish_posts'] = false;
        }
        return $all;
    }
    public static function fingerprint($post): string {
        $p = get_post($post);
        if (!$p) return '';
        return hash('sha256', wp_json_encode([
            $p->post_title, $p->post_content, $p->post_excerpt, $p->post_name,
            (int)$p->post_author, wp_get_post_categories($p->ID),
            wp_get_post_tags($p->ID, ['fields'=>'ids']), (int)get_post_thumbnail_id($p->ID)
        ]));
    }
    private static function snapshot($p): array {
        return [
            'title'=>$p->post_title, 'content'=>$p->post_content,
            'excerpt'=>$p->post_excerpt, 'slug'=>$p->post_name,
            'categories'=>wp_get_post_categories($p->ID),
            'tags'=>wp_get_post_tags($p->ID, ['fields'=>'ids']),
            'thumbnail'=>(int)get_post_thumbnail_id($p->ID),
        ];
    }
    private static function policy(int $author, int $creator, int $submitter): string {
        foreach ([$author,$creator,$submitter] as $id) {
            if (Editorial::is_editor($id)) return 'peer_editor';
        }
        return 'editor_or_admin';
    }
    public static function gate(array $data, array $postarr, array $unsanitized = [], bool $update = false): array {
        if (self::$internal || ($data['post_type'] ?? '') !== 'post') return $data;
        $post = !empty($postarr['ID']) ? get_post((int)$postarr['ID']) : null;
        if ($post && in_array($post->post_status, ['publish','future'], true) &&
            EditorialHardening::blocked((int)$post->ID, get_current_user_id())) {
            // This filter precedes the post write AND the subsequent meta/term
            // updates in wp_insert_post(). Silently reverting content would
            // still allow taxonomy and metadata writes to the live article.
            wp_die('Live article is protected. Propose a revision for approval.',
                'Editorial Approval Required', ['response'=>403]);
        }
        if (in_array(($data['post_status'] ?? ''), ['publish','future'], true)) {
            // Administrators have an emergency direct-publish bypass; peers do not.
            if (!Editorial::is_admin(get_current_user_id())) $data['post_status'] = 'pending';
        }
        return $data;
    }
    public static function transition_guard(string $new, string $old, $post): void {
        if (self::$internal || $post->post_type !== 'post' || $new !== 'publish') return;
        if ($old === 'future' && get_post_meta($post->ID, '_ncg_ed_schedule_authorized', true)
            && strtotime($post->post_date_gmt . ' UTC') <= time()) {
            Editorial::record('approved_scheduled_publication', (int)$post->ID);
            return;
        }
        if (Editorial::is_admin(get_current_user_id()) && !wp_doing_cron()) {
            Editorial::record('admin_direct_publication', (int)$post->ID, ['from'=>$old], 'warning');
            return;
        }
        // wp_publish_post() / cron can bypass wp_insert_post_data. Prevent unattended publishing.
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_status'=>'pending'], ['ID'=>$post->ID], ['%s'], ['%d']);
        clean_post_cache($post->ID);
        Editorial::alarm('unapproved_publish_reversed', (int)$post->ID, ['from'=>$old]);
    }
    public static function queue(int $id, $post, bool $update): void {
        if (self::$internal || $post->post_type !== 'post') return;
        if (!metadata_exists('post', $id, '_ncg_ed_creator')) {
            Editorial::$applying = true;
            try { add_post_meta($id, '_ncg_ed_creator', get_current_user_id() ?: (int)$post->post_author, true); }
            finally { Editorial::$applying = false; }
        }
        $actor = get_current_user_id();
        if ($actor > 0 && in_array($post->post_status, ['draft','pending'], true)) {
            Editorial::$applying = true;
            try {
                $contributors = (array)get_post_meta($id, '_ncg_ed_contributors', true);
                $contributors = array_values(array_unique(array_filter(array_map('intval', $contributors))));
                if (!in_array($actor, $contributors, true)) {
                    $contributors[] = $actor;
                    update_post_meta($id, '_ncg_ed_contributors', $contributors);
                }
            } finally { Editorial::$applying = false; }
        }
        if ($post->post_status !== 'pending') return;
        global $wpdb;
        $table = Editorial::table('reviews');
        $prior = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE post_id=%d AND kind='new' AND status='pending' ORDER BY id DESC LIMIT 1", $id));
        $creator = (int)get_post_meta($id, '_ncg_ed_creator', true) ?: (int)$post->post_author;
        $actor = get_current_user_id() ?: (int)$post->post_author;
        $policy = self::policy((int)$post->post_author, $creator, $actor);
        foreach ((array)get_post_meta($id, '_ncg_ed_contributors', true) as $contributor) {
            if (Editorial::is_editor((int)$contributor)) $policy = 'peer_editor';
        }
        if ($prior) {
            $wpdb->update($table, [
                'base_hash'=>self::fingerprint($post),
                'payload'=>wp_json_encode(self::snapshot($post)),
                'policy'=>($prior->policy === 'peer_editor' || $policy === 'peer_editor') ? 'peer_editor' : 'editor_or_admin',
            ], ['id'=>$prior->id, 'status'=>'pending']);
            // Any subsequent contributor to the pending revision is disqualified.
            $editors = (array)get_post_meta($id, '_ncg_ed_pending_editor_ids', true);
            if ($actor && !in_array($actor, $editors, true)) {
                $editors[] = $actor;
                Editorial::$applying = true;
                try { update_post_meta($id, '_ncg_ed_pending_editor_ids', $editors); }
                finally { Editorial::$applying = false; }
            }
            return;
        }
        $ok = $wpdb->insert($table, [
            'post_id'=>$id, 'submitter_id'=>$actor, 'creator_id'=>$creator,
            'kind'=>'new', 'policy'=>$policy, 'status'=>'pending',
            'base_hash'=>self::fingerprint($post),
            'payload'=>wp_json_encode(self::snapshot($post)),
            'created_utc'=>current_time('mysql', true),
        ]);
        if ($ok) Editorial::record('submitted_for_review', $id, ['review_id'=>(int)$wpdb->insert_id]);
    }
    public static function eligible($req, int $reviewer): bool {
        if (!$req || $req->status !== 'pending' || !Editorial::reviewer($reviewer)) return false;
        $post = get_post((int)$req->post_id);
        if (!$post) return false;
        $exclusions = [(int)$req->creator_id, (int)$req->submitter_id, (int)$post->post_author];
        foreach (['_ncg_ed_pending_editor_ids','_ncg_ed_contributors'] as $key) {
            $extra = (array)get_post_meta($post->ID, $key, true);
            foreach ($extra as $id) $exclusions[] = (int)$id;
        }
        if (in_array($reviewer, $exclusions, true)) return false;
        return $req->policy !== 'peer_editor' || Editorial::is_editor($reviewer);
    }
    public static function may_propose($post, int $actor): bool {
        $p = get_post($post);
        return $p && $p->post_type === 'post' && $p->post_status === 'publish'
            && $actor > 0 && user_can($actor, 'edit_posts')
            && ((int)$p->post_author === $actor || user_can($actor, 'edit_others_posts'));
    }
    private static function redirect(string $message, string $view = 'queue'): void {
        $url = current_user_can('manage_options')
            ? add_query_arg(['page'=>'news-cache-guard','ncg_tab'=>'editorial','view'=>$view,
                'ncg_ed_notice'=>$message], admin_url('options-general.php'))
            : add_query_arg(['page'=>'ncg-editorial-reviews','ncg_ed_notice'=>$message], admin_url('edit.php'));
        wp_safe_redirect($url);
        exit;
    }
    public static function propose(): void {
        check_admin_referer('ncg_ed_propose');
        $id = absint($_POST['post_id'] ?? 0);
        $actor = get_current_user_id();
        if (!self::may_propose($id, $actor)) wp_die('Not permitted to propose revisions.');
        $p = get_post($id);
        global $wpdb;
        $table = Editorial::table('reviews');
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE post_id=%d AND status='pending' LIMIT 1", $id));
        if ($exists) self::redirect('An approval request already exists for this article.');
        $payload = [
            'title'=>sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'content'=>wp_kses_post(wp_unslash($_POST['content'] ?? '')),
            'excerpt'=>sanitize_textarea_field(wp_unslash($_POST['excerpt'] ?? '')),
            'slug'=>sanitize_title(wp_unslash($_POST['slug'] ?? '')),
            'categories'=>array_values(array_filter(array_map('absint', (array)($_POST['categories'] ?? [])))),
            'tags'=>wp_get_post_tags($id, ['fields'=>'ids']),
            'thumbnail'=>absint($_POST['thumbnail'] ?? 0),
        ];
        if (!$payload['title']) self::redirect('Headline cannot be empty.');
        if ($payload['thumbnail'] && get_post_type($payload['thumbnail']) !== 'attachment') self::redirect('Invalid featured image.');
        $creator = (int)get_post_meta($id, '_ncg_ed_creator', true) ?: (int)$p->post_author;
        $ok = $wpdb->insert($table, [
            'post_id'=>$id, 'submitter_id'=>$actor, 'creator_id'=>$creator,
            'kind'=>'revision','policy'=>self::policy((int)$p->post_author,$creator,$actor),
            'status'=>'pending','base_hash'=>self::fingerprint($p),
            'payload'=>wp_json_encode($payload),'created_utc'=>current_time('mysql',true),
        ]);
        if (!$ok) self::redirect('Failed to save proposal.');
        Editorial::record('revision_submitted', $id, ['review_id'=>(int)$wpdb->insert_id]);
        self::redirect('Revision submitted for independent review.');
    }
    public static function review(): void {
        check_admin_referer('ncg_ed_review');
        $id = absint($_POST['review_id'] ?? 0);
        $decision = sanitize_key($_POST['decision'] ?? '');
        $note = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
        if (!in_array($decision, ['approve','reject','changes'], true)) wp_die('Invalid decision.');
        if ($decision !== 'approve' && !$note) self::redirect('Reason required for rejection or changes.');
        global $wpdb;
        $table = Editorial::table('reviews');
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
        if (!self::eligible($r, get_current_user_id())) wp_die('Independent reviewer required.');
        $p = get_post((int)$r->post_id);
        if (!$p || !hash_equals((string)$r->base_hash, self::fingerprint($p))) self::redirect('Article changed after submission; resubmit before approval.');
        $payload = json_decode((string)$r->payload, true);
        if (!is_array($payload)) self::redirect('Invalid stored snapshot.');
        $next = $decision === 'approve' ? 'processing' : ($decision === 'reject' ? 'rejected' : 'changes');
        $updated = $wpdb->update($table, ['status'=>$next,'reviewer_id'=>get_current_user_id(),
            'note'=>$note,'decided_utc'=>current_time('mysql',true)], ['id'=>$id,'status'=>'pending']);
        if ($updated !== 1) self::redirect('Another reviewer already handled this request.');
        if ($decision !== 'approve') {
            if ($r->kind === 'new' && $p->post_status === 'pending') {
                self::$internal = true;
                try { wp_update_post(['ID'=>$p->ID,'post_status'=>'draft']); }
                finally { self::$internal = false; }
            }
            Editorial::record('review_' . $next, (int)$p->ID, ['review_id'=>$id, 'note'=>$note]);
            self::redirect('Review decision saved.');
        }
        $result = self::apply($r, $payload);
        if (is_wp_error($result)) {
            $wpdb->update($table, ['status'=>'pending','reviewer_id'=>0], ['id'=>$id, 'status'=>'processing']);
            self::redirect('Approval failed: ' . $result->get_error_message());
        }
        $wpdb->update($table, ['status'=>'approved'], ['id'=>$id,'status'=>'processing']);
        Editorial::record('independently_approved', (int)$p->ID, ['review_id'=>$id,'reviewer_id'=>get_current_user_id()]);
        Editorial::$applying = true;
        try { delete_post_meta($p->ID, '_ncg_ed_pending_editor_ids'); }
        finally { Editorial::$applying = false; }
        self::redirect('Approved and published.');
    }
    private static function apply($request, array $data) {
        $p = get_post((int)$request->post_id);
        if (!$p) return new \WP_Error('missing_post', 'Article no longer exists.');
        if (!hash_equals((string)$request->base_hash, self::fingerprint($p))) return new \WP_Error('stale', 'Article changed since submission.');
        if ($request->kind === 'new') {
            if ($p->post_status !== 'pending') return new \WP_Error('bad_status','Article is not pending.');
            $fields = ['ID'=>$p->ID,'post_status'=>'publish'];
        } else {
            if ($p->post_status !== 'publish') return new \WP_Error('bad_status','Live article is not published.');
            $fields = [
                'ID'=>$p->ID,'post_title'=>(string)$data['title'],
                'post_content'=>(string)$data['content'],
                'post_excerpt'=>(string)$data['excerpt'],
                'post_name'=>(string)$data['slug'],
                'post_category'=>array_map('absint', (array)$data['categories']),
                'tags_input'=>array_map('intval', (array)$data['tags']),
            ];
        }
        self::$internal = true;
        try {
            $result = wp_update_post(wp_slash($fields), true);
            if (!is_wp_error($result) && $request->kind === 'revision') {
                if (!empty($data['thumbnail'])) set_post_thumbnail($p->ID, absint($data['thumbnail']));
                else delete_post_thumbnail($p->ID);
            }
        } finally { self::$internal = false; }
        return $result;
    }
}
