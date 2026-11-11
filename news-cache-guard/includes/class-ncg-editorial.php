<?php
/**
 * Modular editorial controls for News Cache Guard.
 * Cache protection never depends on the editorial master switch.
 */
declare(strict_types=1);
namespace NCG;
if (!defined('ABSPATH')) exit;

final class Editorial {
    public const OPTION = 'ncg_editorial_settings';
    public const DB_VERSION = '1';
    public const FIRST = '_ncg_ed_first_published_utc';
    public static bool $applying = false;

    public static function defaults(): array {
        return [
            'master' => 0, 'audit' => 1, 'security' => 1,
            'approvals' => 1, 'lock' => 1,
            'lock_hours' => 4, 'bypass_ids' => '529',
            'retention_days' => 180, 'email_alerts' => 0,
        ];
    }
    public static function settings(): array {
        $saved = get_option(self::OPTION, []);
        return array_replace(self::defaults(), is_array($saved) ? $saved : []);
    }
    public static function on(string $group): bool {
        $s = self::settings();
        return !empty($s['master']) && !empty($s[$group]);
    }
    public static function clean($raw): array {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach (['master','audit','security','approvals','lock','email_alerts'] as $key) {
            $out[$key] = empty($raw[$key]) ? 0 : 1;
        }
        $out['lock_hours'] = max(0, min(720, (int)($raw['lock_hours'] ?? 4)));
        $out['retention_days'] = max(30, min(3650, (int)($raw['retention_days'] ?? 180)));
        $ids = preg_split('/[\\s,]+/', (string)($raw['bypass_ids'] ?? ''));
        $valid_ids = [];
        foreach ($ids as $id) {
            if ($id !== '' && ctype_digit((string)$id) && (int)$id > 0) $valid_ids[] = (int)$id;
        }
        $out['bypass_ids'] = implode(',', array_unique($valid_ids));
        return $out;
    }
    public static function table(string $kind): string {
        global $wpdb;
        return $wpdb->prefix . 'ncg_ed_' . $kind;
    }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $events = self::table('events');
        $reviews = self::table('reviews');
        dbDelta("CREATE TABLE $events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_utc datetime NOT NULL,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(60) NOT NULL,
            ip varchar(45) NOT NULL DEFAULT '',
            source varchar(20) NOT NULL DEFAULT '',
            severity varchar(12) NOT NULL DEFAULT 'info',
            details longtext NULL,
            PRIMARY KEY  (id),
            KEY time_idx (created_utc),
            KEY actor_ip_idx (actor_id,ip),
            KEY post_time_idx (post_id,created_utc),
            KEY action_time_idx (action,created_utc)
        ) $charset;");
        dbDelta("CREATE TABLE $reviews (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            submitter_id bigint(20) unsigned NOT NULL,
            creator_id bigint(20) unsigned NOT NULL,
            reviewer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            kind varchar(12) NOT NULL DEFAULT 'new',
            policy varchar(20) NOT NULL DEFAULT 'editor_or_admin',
            status varchar(20) NOT NULL DEFAULT 'pending',
            base_hash char(64) NOT NULL,
            payload longtext NULL,
            note text NULL,
            created_utc datetime NOT NULL,
            decided_utc datetime NULL,
            PRIMARY KEY  (id),
            KEY post_status_idx (post_id,status),
            KEY status_time_idx (status,created_utc)
        ) $charset;");
        update_option('ncg_editorial_db_version', self::DB_VERSION, false);
    }
    public static function boot(): void {
        if (!empty(self::settings()['master']) && get_option('ncg_editorial_db_version') !== self::DB_VERSION) {
            self::install();
        }
        if (self::on('audit')) {
            add_action('transition_post_status', [self::class, 'transition'], 20, 3);
            add_action('post_updated', [self::class, 'post_updated'], 20, 3);
            add_action('before_delete_post', [self::class, 'deleted'], 10, 2);
            add_action('wp_login', [self::class, 'login'], 10, 2);
            add_action('set_user_role', [self::class, 'role_change'], 10, 3);
            add_action('updated_post_meta', [self::class, 'meta_event'], 10, 4);
            add_action('added_post_meta', [self::class, 'meta_event'], 10, 4);
        }
        if (self::on('lock') || self::on('approvals')) {
            add_filter('map_meta_cap', [self::class, 'map_meta_cap'], 9999, 4);
            add_filter('pre_trash_post', [self::class, 'pre_trash'], 10, 2);
            add_filter('pre_delete_post', [self::class, 'pre_delete'], 10, 2);
            add_filter('post_row_actions', [self::class, 'row_actions'], 10, 2);
        }
        if (self::on('approvals')) EditorialWorkflow::boot();
        add_action('ncg_editorial_cleanup', [self::class, 'cleanup']);
        if (!wp_next_scheduled('ncg_editorial_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'ncg_editorial_cleanup');
    }
    public static function deactivate(): void {
        wp_clear_scheduled_hook('ncg_editorial_cleanup');
    }
    public static function is_editor(int $id): bool {
        $u = get_userdata($id);
        return $u && in_array('editor', (array)$u->roles, true);
    }
    public static function is_admin(int $id): bool {
        return $id > 0 && user_can($id, 'manage_options');
    }
    public static function bypass(int $id): bool {
        if (self::is_admin($id)) return true;
        if ($id <= 0) return false;
        $ids = array_map('intval', explode(',', self::settings()['bypass_ids']));
        return in_array($id, $ids, true);
    }
    public static function reviewer(int $id): bool {
        return $id > 0 && user_can($id, 'edit_others_posts') && (self::is_editor($id) || self::is_admin($id));
    }
    public static function ip(): string {
        // Reuse News Cache Guard's trusted Cloudflare/REMOTE_ADDR resolver.
        return IP::client_ip() ?? '';
    }
    public static function source(): string {
        if (defined('WP_CLI') && WP_CLI) return 'wp-cli';
        if (wp_doing_cron()) return 'cron';
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return 'xml-rpc';
        if (defined('REST_REQUEST') && REST_REQUEST) return 'rest-api';
        return is_admin() ? 'dashboard' : 'web';
    }
    public static function record(string $action, int $post = 0, array $details = [], string $severity = 'info', ?int $actor = null): int {
        if (!self::on('audit')) return 0;
        global $wpdb;
        $ok = $wpdb->insert(self::table('events'), [
            'created_utc' => current_time('mysql', true), 'actor_id' => $actor ?? get_current_user_id(),
            'post_id' => $post, 'action' => sanitize_key($action), 'ip' => self::ip(),
            'source' => self::source(), 'severity' => $severity, 'details' => wp_json_encode($details),
        ]);
        return $ok ? (int)$wpdb->insert_id : 0;
    }
    public static function alarm(string $action, int $post, array $details = [], ?int $actor = null): void {
        if (!self::on('security') || !self::on('audit')) return;
        self::record($action, $post, $details, 'warning', $actor);
        if (!empty(self::settings()['email_alerts'])) {
            $key = 'ncg_ed_mail_' . md5($action . '|' . $post . '|' . gmdate('YmdH'));
            if (get_transient($key)) return;
            set_transient($key, 1, HOUR_IN_SECONDS);
            wp_mail(get_option('admin_email'), '[News Cache Guard] Editorial alert: ' . $action,
                'Review the editorial security log at ' . admin_url('options-general.php?page=news-cache-guard&ncg_tab=editorial&view=alerts'));
        }
    }
    public static function first(int $post_id): int {
        $date = get_post_meta($post_id, self::FIRST, true);
        if (!$date) {
            $p = get_post($post_id);
            $date = $p ? $p->post_date_gmt : '';
        }
        if (!$date || $date === '0000-00-00 00:00:00') return 0;
        return max(0, (int)strtotime($date . ' UTC'));
    }
    public static function locked($post): bool {
        $p = get_post($post);
        if (!$p || $p->post_type !== 'post' || $p->post_status !== 'publish' || !self::on('lock')) return false;
        $first = self::first((int)$p->ID);
        return !$first || time() >= $first + (int)self::settings()['lock_hours'] * HOUR_IN_SECONDS;
    }
    public static function map_meta_cap(array $caps, string $cap, int $id, array $args): array {
        if (!in_array($cap, ['edit_post','delete_post'], true) || empty($args[0])) return $caps;
        $post = get_post((int)$args[0]);
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish' || self::bypass($id)) return $caps;
        if (($cap === 'edit_post' && self::on('approvals')) || self::locked($post)) return ['do_not_allow'];
        return $caps;
    }
    public static function pre_trash($check, $post) {
        if ($check === null && self::locked($post) && !self::bypass(get_current_user_id())) {
            self::alarm('locked_trash_blocked', (int)$post->ID);
            return false;
        }
        return $check;
    }
    public static function pre_delete($check, $post) {
        if ($check === null && self::locked($post) && !self::bypass(get_current_user_id())) {
            self::alarm('locked_delete_blocked', (int)$post->ID);
            return false;
        }
        return $check;
    }
    public static function row_actions(array $actions, $post): array {
        if ($post->post_type !== 'post' || $post->post_status !== 'publish' || self::bypass(get_current_user_id())) return $actions;
        if (self::on('approvals') || self::locked($post)) {
            unset($actions['edit'], $actions['inline hide-if-no-js']);
            if (self::on('approvals') && get_current_user_id() && (get_current_user_id() === (int)$post->post_author || current_user_can('edit_others_posts'))) {
                $actions['ncg_proposal'] = '<a href="' . esc_url(admin_url('options-general.php?page=news-cache-guard&ncg_tab=editorial&view=propose&post=' . $post->ID)) . '">Propose revision</a>';
            }
        }
        if (self::locked($post)) unset($actions['trash'], $actions['delete']);
        return $actions;
    }
    public static function transition(string $new, string $old, $p): void {
        if ($p->post_type !== 'post' || $new === $old) return;
        if ($new === 'publish' && !metadata_exists('post', $p->ID, self::FIRST)) {
            add_post_meta($p->ID, self::FIRST, current_time('mysql', true), true);
            add_post_meta($p->ID, '_ncg_ed_first_publisher_id', get_current_user_id(), true);
            add_post_meta($p->ID, '_ncg_ed_first_publisher_ip', self::ip(), true);
        }
        self::record('status_' . sanitize_key($new), (int)$p->ID, ['from'=>$old, 'to'=>$new]);
    }
    public static function post_updated(int $id, $after, $before): void {
        if ($after->post_type !== 'post') return;
        $diff = [];
        foreach (['post_title','post_content','post_excerpt','post_name','post_author'] as $field) {
            if ($after->$field !== $before->$field) $diff[] = $field;
        }
        if (!$diff) return;
        self::record('post_edited', $id, ['fields'=>$diff,'old_content_sha256'=>hash('sha256', $before->post_content),'new_content_sha256'=>hash('sha256', $after->post_content)]);
        if (self::on('security') && $before->post_content !== $after->post_content) {
            preg_match_all('~<a\\b[^>]*href\\s*=\\s*["\\x27](https?://[^"\\x27]+)~i', $before->post_content, $old);
            preg_match_all('~<a\\b[^>]*href\\s*=\\s*["\\x27](https?://[^"\\x27]+)~i', $after->post_content, $new);
            $added = array_values(array_diff(array_unique($new[1] ?? []), array_unique($old[1] ?? [])));
            if ($added) self::alarm('external_links_added', $id, ['urls'=>array_slice($added,0,20)]);
        }
        if (self::locked($before) && self::bypass(get_current_user_id())) self::alarm('locked_post_privileged_edit', $id);
    }
    public static function deleted(int $id, $post): void {
        if ($post && $post->post_type === 'post') self::record('post_permanently_deleted', $id, ['title'=>$post->post_title], 'warning');
    }
    public static function login(string $name, $user): void {
        if (!self::on('audit')) return;
        global $wpdb;
        $ip = self::ip();
        $table = self::table('events');
        $before = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE actor_id=%d AND action='login_success'", $user->ID));
        $known = !$ip ? 1 : (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE actor_id=%d AND ip=%s AND action='login_success'", $user->ID, $ip));
        self::record('login_success', 0, [], 'info', (int)$user->ID);
        if ($before > 0 && !$known && self::reviewer((int)$user->ID)) self::alarm('new_editor_login_ip', 0, ['user_id'=>$user->ID], (int)$user->ID);
    }
    public static function role_change(int $id, string $role, array $old): void {
        self::record('user_role_changed', 0, ['user_id'=>$id, 'from'=>$old, 'to'=>$role], 'warning');
        if (in_array($role, ['editor','administrator'], true)) self::alarm('privilege_escalation', 0, ['user_id'=>$id,'role'=>$role]);
    }
    public static function meta_event($meta_id, int $post_id, string $key, $value): void {
        if (get_post_type($post_id) !== 'post') return;
        if (in_array($key, ['_thumbnail_id','_yoast_wpseo_title','_yoast_wpseo_metadesc','_yoast_wpseo_canonical','rank_math_title','rank_math_description','rank_math_canonical_url'], true)) {
            self::record('sensitive_meta_edited', $post_id, ['key'=>$key, 'sha256'=>hash('sha256', maybe_serialize($value))]);
        }
    }
    public static function cleanup(): void {
        if (!self::on('audit')) return;
        global $wpdb;
        $before = gmdate('Y-m-d H:i:s', time() - (int)self::settings()['retention_days'] * DAY_IN_SECONDS);
        $table = self::table('events');
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE created_utc < %s LIMIT 1000", $before));
    }
}
