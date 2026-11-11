<?php
/**
 * Staff accountability for ordinary WordPress posts.
 *
 * Independent of the optional editorial approval/lock master switch.
 * Does not filter or replace post_author, theme output, public URLs or permissions.
 */
declare(strict_types=1);
namespace NCG;
if (!defined('ABSPATH')) exit;

final class PostHistory {
    private const DB_VERSION = '1';
    private const VERSION_OPTION = 'ncg_post_history_db_version';
    private const PAGE_SIZE = 30;
    private const ACTIONS = ['created','edited','author_changed','published','scheduled','status_changed'];

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'ncg_post_history';
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            recorded_utc datetime NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            actor_label varchar(191) NOT NULL DEFAULT '',
            author_id bigint(20) unsigned NOT NULL DEFAULT 0,
            old_author_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event varchar(40) NOT NULL,
            source varchar(20) NOT NULL DEFAULT '',
            details text NULL,
            PRIMARY KEY  (id),
            KEY post_recorded (post_id,id),
            KEY recorded_idx (recorded_utc),
            KEY actor_recorded (actor_id,id),
            KEY author_recorded (author_id,id),
            KEY event_recorded (event,id)
        ) $charset;");
        // Never mark a failed schema migration complete.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            update_option(self::VERSION_OPTION, self::DB_VERSION, false);
        }
    }

    public static function ready(): bool {
        return get_option(self::VERSION_OPTION) === self::DB_VERSION;
    }

    public static function boot(): void {
        if (!Modules::on('post_history')) return;
        // Upgrade does not invoke register_activation_hook. Install once, including
        // when the first post save happens before an administrator visits wp-admin.
        // The migration hook is only needed until the versioned table exists.
        if (!self::ready()) add_action('init', [self::class, 'maybe_install'], 5);
        add_action('wp_after_insert_post', [self::class, 'inserted'], 20, 4);
        add_action('post_updated', [self::class, 'updated'], 30, 3);
        add_action('transition_post_status', [self::class, 'transition'], 30, 3);
        if (is_admin()) {
            add_action('add_meta_boxes_post', [self::class, 'meta_box']);
        }
    }

    public static function maybe_install(): void {
        if (Modules::on('post_history') && !self::ready()) self::install();
    }

    private static function track($post): bool {
        return is_object($post) && $post->post_type === 'post' &&
            !in_array($post->post_status, ['auto-draft','inherit'], true) &&
            !wp_is_post_revision((int)$post->ID) && !wp_is_post_autosave((int)$post->ID);
    }

    private static function source(): string {
        if (defined('WP_CLI') && WP_CLI) return 'wp-cli';
        if (wp_doing_cron()) return 'cron';
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return 'xml-rpc';
        if (defined('REST_REQUEST') && REST_REQUEST) return 'rest-api';
        return is_admin() ? 'dashboard' : 'web';
    }

    private static function record(string $event, $post, array $details = [], int $old_author_id = 0): void {
        if (!Modules::on('post_history') || !self::ready() || !self::track($post) ||
            !in_array($event, self::ACTIONS, true)) return;
        global $wpdb;
        $actor = get_current_user_id();
        $user = $actor ? get_userdata($actor) : false;
        $wpdb->insert(self::table(), [
            'recorded_utc'=>current_time('mysql', true),
            'post_id'=>(int)$post->ID,
            'actor_id'=>$actor,
            'actor_label'=>$user ? (string)$user->display_name : '',
            'author_id'=>(int)$post->post_author,
            'old_author_id'=>$old_author_id,
            'event'=>$event,
            'source'=>self::source(),
            'details'=>wp_json_encode($details),
        ], ['%s','%d','%d','%s','%d','%d','%s','%s','%s']);
    }

    public static function inserted(int $post_id, $post, bool $update, $post_before): void {
        if (!$update && self::track($post)) {
            self::record('created', $post);
        }
    }

    public static function updated(int $post_id, $after, $before): void {
        if (!self::track($after) || !is_object($before)) return;
        $changed = [];
        foreach (['post_title','post_content','post_excerpt','post_name'] as $key) {
            if ($after->$key !== $before->$key) $changed[] = $key;
        }
        if ((int)$after->post_author !== (int)$before->post_author) {
            self::record('author_changed', $after, [], (int)$before->post_author);
        }
        if ($changed) self::record('edited', $after, ['fields'=>$changed]);
    }

    public static function transition(string $new, string $old, $post): void {
        if ($new === $old || !self::track($post)) return;
        // An auto-draft is created when an editor opens "Add New", not when
        // someone has supplied an article. Capture the first real save instead.
        if ($old === 'auto-draft') self::record('created', $post);
        if ($new === 'publish' && get_post_status((int)$post->ID) === 'publish') {
            self::record('published', $post, ['from'=>$old]);
        } elseif ($new === 'future') {
            self::record('scheduled', $post, ['from'=>$old]);
        } elseif ($old !== 'auto-draft') {
            self::record('status_changed', $post, ['from'=>$old,'to'=>$new]);
        }
    }

    public static function meta_box($post): void {
        if (!current_user_can('edit_others_posts') || !current_user_can('edit_post', (int)$post->ID)) return;
        add_meta_box('ncg-post-history', 'Newsroom Guard — Post History',
            [self::class, 'render_meta_box'], 'post', 'side', 'default');
    }

    private static function actor_label($row): string {
        if (!$row) return 'Not recorded';
        if ((int)$row->actor_id === 0) return 'System / automated (' . (string)$row->source . ')';
        $label = trim((string)$row->actor_label);
        return $label !== '' ? $label . ' (#' . (int)$row->actor_id . ')' : 'User #' . (int)$row->actor_id;
    }

    private static function action_label($row): string {
        $labels = [
            'created'=>'Created article', 'edited'=>'Edited article',
            'author_changed'=>'Changed public author', 'published'=>'Published article',
            'scheduled'=>'Scheduled article', 'status_changed'=>'Changed status',
        ];
        $label = $labels[$row->event] ?? $row->event;
        if ($row->event === 'author_changed') {
            $from = get_the_author_meta('display_name', (int)$row->old_author_id);
            $to = get_the_author_meta('display_name', (int)$row->author_id);
            $label .= ': ' . ($from ?: '#'.$row->old_author_id) . ' → ' . ($to ?: '#'.$row->author_id);
        }
        return $label;
    }

    private static function first_event(int $id, string $event) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE post_id = %d AND event = %s ORDER BY id ASC LIMIT 1", $id, $event
        ));
    }

    public static function render_meta_box($post): void {
        if (!current_user_can('edit_others_posts') || !current_user_can('edit_post', (int)$post->ID)) return;
        if (!Modules::on('post_history') || !self::ready()) {
            echo '<p>Post History tracking is disabled or its database is not ready.</p>';
            return;
        }
        global $wpdb;
        $table = self::table();
        $id = (int)$post->ID;
        $rows = (array)$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE post_id=%d ORDER BY id DESC LIMIT 20", $id
        ));
        $creator = self::first_event($id, 'created');
        $publisher = self::first_event($id, 'published');
        $last_edit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE post_id=%d AND event IN ('edited','author_changed') ORDER BY id DESC LIMIT 1", $id
        ));
        $author = get_the_author_meta('display_name', (int)$post->post_author);
        echo '<p><strong>Public author:</strong> ' . esc_html($author ?: 'Unknown') . '</p>';
        echo '<p><strong>Created by:</strong> ' . esc_html(self::actor_label($creator)) . '</p>';
        echo '<p><strong>First published by:</strong> ' . esc_html(self::actor_label($publisher)) . '</p>';
        echo '<p><strong>Last edited by:</strong> ' . esc_html(self::actor_label($last_edit)) . '</p>';
        echo '<hr><strong>Recent activity</strong>';
        if (!$rows) echo '<p>Nothing recorded yet. Existing older posts cannot be attributed retrospectively.</p>';
        else {
            echo '<ol style="margin-left:18px">';
            foreach ($rows as $row) {
                echo '<li style="margin:8px 0"><strong>' . esc_html(self::action_label($row)) .
                    '</strong><br><small>' . esc_html(self::actor_label($row)) . ' · ' .
                    esc_html($row->recorded_utc) . ' UTC</small></li>';
            }
            echo '</ol>';
        }
        echo '<p><a href="' . esc_url(Dashboard::url('activity',['post'=>$id])) .
            '">View all article activity →</a></p>';
    }

    private static function user_ids(string $input): array {
        if ($input === '') return [];
        if (ctype_digit($input)) return [(int)$input];
        // Bounded user directory query; all SQL values are later prepared.
        return array_map('intval', get_users([
            'search'=>'*' . $input . '*',
            'search_columns'=>['user_login','display_name'],
            'fields'=>'ID', 'number'=>40,
        ]));
    }

    private static function utc_date($input): string {
        $input = is_string($input) ? $input : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $input)) return '';
        [$year,$month,$day] = array_map('intval', explode('-', $input));
        return checkdate($month,$day,$year) ? $input : '';
    }

    private static function filters(): array {
        return [
            'post'=>absint($_GET['post'] ?? 0),
            'staff'=>substr(sanitize_text_field(wp_unslash($_GET['staff'] ?? '')),0,80),
            'reporter'=>substr(sanitize_text_field(wp_unslash($_GET['reporter'] ?? '')),0,80),
            'event'=>sanitize_key(wp_unslash($_GET['event'] ?? '')),
            'from'=>self::utc_date(sanitize_text_field(wp_unslash($_GET['from'] ?? ''))),
            'to'=>self::utc_date(sanitize_text_field(wp_unslash($_GET['to'] ?? ''))),
            'paged'=>min(1000,max(1,absint($_GET['paged'] ?? 1))),
        ];
    }

    private static function query(array $f): array {
        global $wpdb;
        $where = ['1=1'];
        $args = [];
        if ($f['post']) { $where[]='post_id=%d'; $args[]=$f['post']; }
        foreach (['staff'=>['actor_id'], 'reporter'=>['author_id','old_author_id']] as $name=>$columns) {
            if ($f[$name] === '') continue;
            $ids = self::user_ids($f[$name]);
            if (!$ids) { $where[]='1=0'; continue; }
            $placeholders=implode(',',array_fill(0,count($ids),'%d'));
            $parts=[];
            foreach ($columns as $col) {
                $parts[]="$col IN ($placeholders)";
                foreach ($ids as $id) $args[]=$id;
            }
            $where[]='(' . implode(' OR ', $parts) . ')';
        }
        if (in_array($f['event'],self::ACTIONS,true)) {
            $where[]='event=%s'; $args[]=$f['event'];
        }
        if ($f['from']) { $where[]='recorded_utc >= %s'; $args[]=$f['from'].' 00:00:00'; }
        if ($f['to']) {
            $tomorrow=(new \DateTimeImmutable($f['to'], new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
            $where[]='recorded_utc < %s'; $args[]=$tomorrow.' 00:00:00';
        }
        $table=self::table();
        $args[]=self::PAGE_SIZE+1;
        $args[]=($f['paged']-1)*self::PAGE_SIZE;
        return [$wpdb->prepare("SELECT * FROM $table WHERE " . implode(' AND ',$where) .
            " ORDER BY id DESC LIMIT %d OFFSET %d", ...$args)];
    }

    public static function admin_page(): void {
        if (!current_user_can('manage_options')) wp_die('Not authorized');
        if (!Modules::on('post_history')) {
            echo '<p>Post History is switched off. Enable it in Module Manager to collect new activity. Existing records are retained.</p>';
        }
        if (!self::ready()) {
            echo '<div class="notice notice-warning inline"><p>Post History database is unavailable. Check plugin installation and database permissions.</p></div>';
            return;
        }
        $f=self::filters();
        echo '<section class="nrg-panel"><div class="nrg-panel-heading"><div><h2>Search editorial activity</h2><p>Independent staff attribution. Private WordPress admin data only; no theme changes.</p></div></div>';
        echo '<form method="get" class="nrg-filters"><input type="hidden" name="page" value="newsroom-guard"><input type="hidden" name="tab" value="activity">';
        echo '<label>Post ID<input type="number" min="1" name="post" value="' . esc_attr($f['post'] ?: '') . '"></label>';
        echo '<label>Staff (name / login / ID)<input type="search" name="staff" value="' . esc_attr($f['staff']) . '"></label>';
        echo '<label>Reporter (name / login / ID)<input type="search" name="reporter" value="' . esc_attr($f['reporter']) . '"></label>';
        echo '<label>Event<select name="event"><option value="">All events</option>';
        foreach (self::ACTIONS as $event) {
            echo '<option value="' . esc_attr($event) . '"' . selected($f['event'],$event,false) . '>' . esc_html(ucwords(str_replace('_',' ',$event))) . '</option>';
        }
        echo '</select></label>';
        echo '<label>From (UTC)<input type="date" name="from" value="' . esc_attr($f['from']) . '"></label>';
        echo '<label>To (UTC)<input type="date" name="to" value="' . esc_attr($f['to']) . '"></label>';
        echo '<button class="button nrg-filter-btn">Search</button></form>';
        global $wpdb;
        [$sql]=self::query($f);
        $rows=(array)$wpdb->get_results($sql);
        $more=count($rows)>self::PAGE_SIZE;
        echo '<div class="nrg-table-wrap"><table class="widefat nrg-table"><thead><tr><th>Date (UTC)</th><th>Article</th><th>Action</th><th>Staff member</th><th>Public author at event</th><th>Source</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="6">No matching activity has been recorded.</td></tr>';
        foreach (array_slice($rows,0,self::PAGE_SIZE) as $row) {
            $post=get_post((int)$row->post_id);
            $title=$post ? get_the_title($post) : '(deleted post)';
            $edit=$post ? get_edit_post_link((int)$row->post_id) : '';
            $author=get_the_author_meta('display_name', (int)$row->author_id);
            echo '<tr><td>' . esc_html($row->recorded_utc) . '</td><td>';
            if ($edit) echo '<a href="' . esc_url($edit) . '">' . esc_html($title) . '</a>';
            else echo esc_html($title);
            echo '<br><small>#' . (int)$row->post_id . '</small></td><td>' . esc_html(self::action_label($row)) .
                '</td><td>' . esc_html(self::actor_label($row)) . '</td><td>' .
                esc_html($author ?: 'Unknown') . ' (#' . (int)$row->author_id . ')</td><td>' .
                esc_html($row->source) . '</td></tr>';
        }
        echo '</tbody></table></div><div class="nrg-pagination">';
        $base=Dashboard::url('activity',array_filter([
            'post'=>$f['post'],'staff'=>$f['staff'],'reporter'=>$f['reporter'],
            'event'=>$f['event'],'from'=>$f['from'],'to'=>$f['to'],
        ],static function($v){return $v !== '' && $v !== 0;}));
        if ($f['paged']>1) echo '<a class="button" href="' . esc_url(add_query_arg('paged',$f['paged']-1,$base)) . '">Previous</a>';
        echo '<span>Page ' . (int)$f['paged'] . '</span>';
        if ($more) echo '<a class="button" href="' . esc_url(add_query_arg('paged',$f['paged']+1,$base)) . '">Next</a>';
        echo '</div></section>';
        echo '<p class="description">Post History records actions only after installation. System/cron changes are not attributed to an editor. Database administrators can modify local records; this is an operational log, not tamper-proof forensic evidence.</p>';
    }
}
