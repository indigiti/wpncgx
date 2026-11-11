<?php
/**
 * Newsroom Guard administration workspace.
 * Only runs in wp-admin: zero public-page CSS, scripts or reporting queries.
 */
declare(strict_types=1);
namespace NCG;
if (!defined('ABSPATH')) exit;

final class Dashboard {
    private const SLUG = 'newsroom-guard';
    private const LIMIT = 30;

    private static function sections(): array {
        return [
            'overview'   => ['Overview', 'dashicons-dashboard'],
            'cache'      => ['Cache & Performance', 'dashicons-performance'],
            'bots'       => ['Bot Protection', 'dashicons-shield'],
            'workers'    => ['Worker Guard', 'dashicons-admin-tools'],
            'approvals'  => ['Editorial Approvals', 'dashicons-yes-alt'],
            'audit'      => ['Publishing Audit', 'dashicons-list-view'],
            'security'   => ['Security Intelligence', 'dashicons-warning'],
            'lock'       => ['Post Lock', 'dashicons-lock'],
            'reports'    => ['Reports', 'dashicons-chart-bar'],
            'settings'   => ['Module Manager', 'dashicons-admin-generic'],
        ];
    }

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_nrg_save_modules', [self::class, 'save_modules']);
        add_action('admin_post_nrg_save_core', [self::class, 'save_core']);
        add_action('admin_post_nrg_save_lock', [self::class, 'save_lock']);
        add_action('admin_post_nrg_export_events', [self::class, 'export_events']);
    }

    public static function menu(): void {
        add_menu_page('Newsroom Guard', 'Newsroom Guard', 'manage_options', self::SLUG,
            [self::class, 'render'], 'dashicons-shield-alt', 58);
        add_submenu_page(self::SLUG, 'Overview', 'Overview', 'manage_options', self::SLUG, [self::class, 'render']);
        add_submenu_page(self::SLUG, 'Module Manager', 'Module Manager', 'manage_options',
            'newsroom-modules', [self::class, 'module_redirect']);
    }

    public static function module_redirect(): void {
        wp_safe_redirect(self::url('settings'));
        exit;
    }

    public static function assets($hook): void {
        if (!in_array($hook, ['toplevel_page_' . self::SLUG, 'posts_page_ncg-editorial-reviews', 'admin_page_ncg-editorial-propose'], true)) return;
        wp_enqueue_style('nrg-admin', NCG_URL . 'assets/newsroom-dashboard.css', [], NCG_VERSION);
        // WordPress dashicons are already included on most admin screens.
        wp_enqueue_style('dashicons');
    }

    public static function url(string $tab = 'overview', array $args = []): string {
        return add_query_arg(array_merge(['page'=>self::SLUG, 'tab'=>$tab], $args), admin_url('admin.php'));
    }

    private static function current_tab(): string {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview';
        return array_key_exists($tab, self::sections()) ? $tab : 'overview';
    }

    private static function note(string $message, string $class = 'neutral'): void {
        echo '<div class="nrg-note nrg-note-' . esc_attr($class) . '">' . esc_html($message) . '</div>';
    }

    private static function chip(string $text, string $tone = 'soft'): void {
        echo '<span class="nrg-chip nrg-chip-' . esc_attr($tone) . '">' . esc_html($text) . '</span>';
    }

    private static function card_open(string $title, string $description = ''): void {
        echo '<section class="nrg-panel"><div class="nrg-panel-heading"><div><h2>' . esc_html($title) . '</h2>';
        if ($description !== '') echo '<p>' . esc_html($description) . '</p>';
        echo '</div></div>';
    }
    private static function card_close(): void { echo '</section>'; }

    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Not authorized');
        $tab = self::current_tab();
        $items = self::sections();
        echo '<div class="wrap nrg-app"><div class="nrg-shell">';
        echo '<aside class="nrg-sidebar" aria-label="Newsroom Guard navigation">';
        echo '<a class="nrg-brand" href="' . esc_url(self::url()) . '"><span class="nrg-brand-mark">N</span><span><strong>Newsroom Guard</strong><small>Publishing operations</small></span></a>';
        echo '<div class="nrg-nav-label">WORKSPACE</div><nav class="nrg-nav">';
        foreach ($items as $key=>$meta) {
            echo '<a class="nrg-nav-link ' . ($key === $tab ? 'is-active' : '') . '" href="' . esc_url(self::url($key)) . '" ' .
                ($key === $tab ? 'aria-current="page"' : '') . '><span class="dashicons ' . esc_attr($meta[1]) . '" aria-hidden="true"></span><span>' .
                esc_html($meta[0]) . '</span></a>';
        }
        echo '</nav><div class="nrg-sidebar-bottom"><span class="nrg-sidebar-dot"></span> Version ' .
            esc_html(NCG_VERSION) . '<br><span>Staging verification required</span></div></aside>';
        echo '<main class="nrg-main"><header class="nrg-topbar"><div class="nrg-breadcrumb">Newsroom Guard <span>/</span> ' .
            esc_html($items[$tab][0]) . '</div><div class="nrg-top-actions">';
        echo '<span class="nrg-top-site">' . esc_html(get_bloginfo('name')) . '</span>';
        echo '<a class="nrg-small-btn" href="' . esc_url(admin_url('options-general.php?page=news-cache-guard')) . '">Advanced settings</a>';
        echo '</div></header><div class="nrg-content">';
        if (isset($_GET['nrg_review_notice'])) self::note(sanitize_text_field(wp_unslash($_GET['nrg_review_notice'])), 'success');
        if (isset($_GET['nrg_notice'])) {
            $message = sanitize_key(wp_unslash($_GET['nrg_notice']));
            $messages = [
                'saved'=>'Settings saved successfully.',
                'htaccess_failed'=>'Settings saved, but .htaccess synchronization failed. Check server permissions.',
                'module_saved'=>'Module switches saved. Any independent MU plugin still requires separate management.',
                'confirm_required'=>'Enable editorial enforcement only after confirming your staging checks.',
            ];
            if (isset($messages[$message])) self::note($messages[$message], $message === 'saved' || $message === 'module_saved' ? 'success' : 'warning');
        }
        echo '<div class="nrg-title-block"><div class="nrg-eyebrow">NEWSROOM OPERATIONS</div><h1>' .
            esc_html($items[$tab][0]) . '</h1><p>' . esc_html(self::subheading($tab)) . '</p></div>';
        switch ($tab) {
            case 'overview': self::overview(); break;
            case 'cache': self::cache(); break;
            case 'bots': self::bots(); break;
            case 'workers': self::workers(); break;
            case 'approvals': self::approvals(); break;
            case 'audit': self::audit(false); break;
            case 'security': self::audit(true); break;
            case 'lock': self::lock(); break;
            case 'reports': self::reports(); break;
            case 'settings': self::settings(); break;
        }
        echo '<footer class="nrg-footer">Newsroom Guard · All activity data is sourced from this WordPress site. No sample metrics are displayed.</footer>';
        echo '</div></main></div></div>';
    }

    private static function subheading(string $tab): string {
        $copy = [
            'overview'=>'A clear, actionable view of publishing, safety and site performance.',
            'cache'=>'Maintain fast public pages while preserving strict private-cache exclusions.',
            'bots'=>'Control requests reaching PHP, scanner activity and trusted IP handling.',
            'workers'=>'Conservative Action Scheduler and SEO workload guardrails.',
            'approvals'=>'Only independent reviewers can approve editorial publication.',
            'audit'=>'Searchable accountability for publication and post changes.',
            'security'=>'Investigate elevated-risk events using recorded evidence.',
            'lock'=>'Prevent unauthorized edits after publication without blocking necessary corrections.',
            'reports'=>'Export bounded, traceable event records for responsible review.',
            'settings'=>'Control each supported module without changing unrelated features.',
        ];
        return $copy[$tab] ?? '';
    }

    private static function tables_ready(): bool {
        global $wpdb;
        $table = Editorial::table('events');
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        return $found === $table;
    }

    private static function stat(string $label, string $value, string $hint, string $tone = ''): void {
        echo '<div class="nrg-stat ' . esc_attr($tone) . '"><span>' . esc_html($label) . '</span><strong>' .
            esc_html($value) . '</strong><small>' . esc_html($hint) . '</small></div>';
    }

    private static function overview(): void {
        global $wpdb;
        $ed = Editorial::settings();
        $ready = self::tables_ready();
        $pending = '—'; $alerts = '—'; $edits = '—';
        if ($ready) {
            $reviews = Editorial::table('reviews');
            $events = Editorial::table('events');
            $pending = number_format_i18n((int)$wpdb->get_var("SELECT COUNT(*) FROM $reviews WHERE status='pending'"));
            $cutoff = gmdate('Y-m-d H:i:s', time()-7*DAY_IN_SECONDS);
            $alerts = number_format_i18n((int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $events WHERE severity IN ('warning','critical') AND created_utc >= %s", $cutoff)));
            $edits = number_format_i18n((int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $events WHERE created_utc >= %s", $cutoff)));
        }
        if (empty($ed['master'])) self::note('Editorial enforcement is OFF. Enable it only after passing your newsroom staging checks.', 'warning');
        if (!Counter::available()) self::note('No persistent object cache detected; origin-rate counters and live rate limiting are unavailable.', 'warning');
        echo '<div class="nrg-stats">';
        self::stat('Pending approvals', $pending, $ready ? 'Current review backlog' : 'Editorial tables not installed');
        self::stat('Recorded activity', $edits, 'Last 7 days (UTC)');
        self::stat('Security events', $alerts, 'Last 7 days (UTC)', 'nrg-stat-accent');
        self::stat('Origin requests', Counter::available() ? number_format_i18n(Counter::stat_value('origin_requests')) : 'N/A',
            Counter::available() ? 'Today · Redis counters' : 'Redis not connected');
        echo '</div><div class="nrg-columns">';
        self::card_open('Publishing activity', 'Verified audit events · last 14 days');
        self::activity_chart($ready);
        self::card_close();
        self::card_open('Systems at a glance', 'Live configuration and integration signals');
        self::system_rows();
        self::card_close();
        echo '</div><div class="nrg-columns">';
        self::card_open('Latest editorial events', 'Most recent recorded site actions');
        self::latest_events($ready);
        self::card_close();
        self::card_open('Quick actions', 'Go directly to the next operational task');
        echo '<div class="nrg-actions">';
        foreach ([
            ['Review pending articles','approvals','Pending reviews and independent approval'],
            ['Inspect security events','security','Check recent high-risk activity'],
            ['Review module switches','settings','Enable or disable supported modules'],
            ['Download audit report','reports','Export event evidence with date filters']
        ] as $action) {
            echo '<a href="' . esc_url(self::url($action[1])) . '"><span><strong>' . esc_html($action[0]) .
                '</strong><small>' . esc_html($action[2]) . '</small></span><span aria-hidden="true">↗</span></a>';
        }
        echo '</div>'; self::card_close(); echo '</div>';
    }

    private static function activity_chart(bool $ready): void {
        if (!$ready || !Editorial::on('audit')) {
            echo '<div class="nrg-empty">Audit collection is disabled or no audit table is available. No invented chart data is shown.</div>';
            return;
        }
        global $wpdb;
        $table = Editorial::table('events');
        $start = gmdate('Y-m-d 00:00:00', time()-13*DAY_IN_SECONDS);
        $data = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_utc) AS day, COUNT(*) AS total FROM $table WHERE created_utc >= %s GROUP BY DATE(created_utc) ORDER BY day ASC", $start), ARRAY_A);
        $counts = [];
        foreach ((array)$data as $entry) $counts[$entry['day']] = (int)$entry['total'];
        $max = max([1, ...array_values($counts)]);
        echo '<div class="nrg-chart" role="img" aria-label="Daily editorial event counts for the past fourteen days">';
        for ($i=13; $i>=0; $i--) {
            $day = gmdate('Y-m-d', time()-$i*DAY_IN_SECONDS);
            $n = $counts[$day] ?? 0;
            $height = $n ? max(5, round($n/$max*100)) : 0;
            echo '<div class="nrg-chart-day" title="' . esc_attr($day . ': ' . $n . ' events') .
                '"><div class="nrg-chart-track"><div class="nrg-chart-bar" style="height:' .
                esc_attr((string)$height) . '%"></div></div><small>' .
                esc_html(gmdate('j', strtotime($day . ' UTC'))) . '</small></div>';
        }
        echo '</div><p class="nrg-footnote">Source: saved audit events, grouped by UTC date. No traffic claims are inferred.</p>';
    }

    private static function system_rows(): void {
        $ed = Editorial::settings();
        $rows = [
            ['LiteSpeed', LiteSpeed::detected() ? 'Detected' : 'Not detected', LiteSpeed::detected()],
            ['Persistent object cache', Counter::available() ? 'Configured · health not certified' : 'Unavailable', Counter::available()],
            ['Origin protection', Modules::on('bot') && Settings::get('enabled',1) ? (Settings::get('dry_run',1) ? 'Dry run' : 'Enforcing') : 'Disabled', Modules::on('bot')],
            ['Worker Guard', Modules::on('worker') ? 'Enabled' : 'Disabled', Modules::on('worker')],
            ['Editorial module', !empty($ed['master']) ? 'Enabled' : 'Off by default', !empty($ed['master'])],
            ['Early Search Guard MU', defined('NCG_MU_SEARCH_GUARD_LOADED') ? 'Active independently' : 'Not installed', defined('NCG_MU_SEARCH_GUARD_LOADED')],
        ];
        echo '<div class="nrg-system-list">';
        foreach ($rows as [$label,$state,$on]) {
            echo '<div><span>' . esc_html($label) . '</span><strong><i class="nrg-led ' .
                ($on ? 'is-on' : '') . '"></i>' . esc_html($state) . '</strong></div>';
        }
        echo '</div>';
    }

    private static function latest_events(bool $ready): void {
        if (!$ready || !Editorial::on('audit')) {
            echo '<div class="nrg-empty">Audit collection is currently off.</div>'; return;
        }
        global $wpdb;
        $table = Editorial::table('events');
        $rows = $wpdb->get_results("SELECT created_utc, action, post_id, actor_id, severity FROM $table ORDER BY id DESC LIMIT 7");
        if (!$rows) { echo '<div class="nrg-empty">No recorded events yet.</div>'; return; }
        echo '<div class="nrg-event-list">';
        foreach ($rows as $r) {
            echo '<div><span class="nrg-event-icon">•</span><span><strong>' . esc_html(str_replace('_',' ',ucwords($r->action,'_'))) .
                '</strong><small>Post #' . (int)$r->post_id . ' · User #' . (int)$r->actor_id . '</small></span><time>' .
                esc_html($r->created_utc) . ' UTC</time></div>';
        }
        echo '</div><a class="nrg-text-link" href="' . esc_url(self::url('audit')) . '">View full audit log →</a>';
    }

    private static function action_form(string $action, string $name = 'Save changes'): void {
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
        echo '<div class="nrg-form-footer"><button type="submit" class="button button-primary nrg-primary">' .
            esc_html($name) . '</button></div>';
    }

    private static function checkbox(string $name, string $label, bool $value, string $detail = ''): void {
        echo '<label class="nrg-setting"><span><strong>' . esc_html($label) . '</strong>';
        if ($detail !== '') echo '<small>' . esc_html($detail) . '</small>';
        echo '</span><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' .
            checked($value, true, false) . '><span class="nrg-switch" aria-hidden="true"></span></label>';
    }

    private static function number(string $name, string $label, int $value, int $min, int $max, string $help=''): void {
        echo '<label class="nrg-field"><span><strong>' . esc_html($label) . '</strong>';
        if ($help) echo '<small>' . esc_html($help) . '</small>';
        echo '</span><input type="number" name="' . esc_attr($name) . '" min="' . $min .
            '" max="' . $max . '" value="' . esc_attr((string)$value) . '"></label>';
    }

    private static function cache(): void {
        $s = Settings::get_all();
        self::note('LiteSpeed controls the actual page cache. These switches control only this plugin’s optional query-key rules and purge behaviour; they never force private pages into a public cache.');
        echo '<div class="nrg-columns">';
        self::card_open('Cache policy', 'Preserve built-in LiteSpeed privacy exclusions');
        echo '<div class="nrg-system-list">';
        foreach ([
            ['LiteSpeed integration',LiteSpeed::detected() ? 'Detected':'Not detected'],
            ['Query normalization', $s['query_collapse'] ? 'Enabled':'Disabled'],
            ['No-cache request reuse', $s['ignore_request_no_cache'] ? 'Opt-in enabled':'Off'],
        ] as $pair) echo '<div><span>' . esc_html($pair[0]) . '</span><strong>' . esc_html($pair[1]) . '</strong></div>';
        echo '</div>'; self::card_close();
        self::card_open('Cache controls', 'Saves only these values; all other origin settings are preserved');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="section" value="cache">';
        self::checkbox('query_collapse','Normalize tracking query keys',!!$s['query_collapse'],'Keep protected and content-changing keys in cache identity.');
        self::checkbox('ignore_request_no_cache','Advanced: reuse eligible cached responses',!!$s['ignore_request_no_cache'],'Affects hard refresh for all clients; verify privacy and purges on staging.');
        self::checkbox('purge_home','Purge home page on article updates',!!$s['purge_home']);
        self::checkbox('purge_categories','Purge related categories',!!$s['purge_categories']);
        self::checkbox('purge_feeds','Purge feeds',!!$s['purge_feeds']);
        self::checkbox('purge_sitemap','Purge sitemaps',!!$s['purge_sitemap']);
        self::action_form('nrg_save_core');echo '</form>';self::card_close();echo '</div>';
        self::note('Check the generated .htaccess block and Cloudflare behaviour after every cache-rule change.', 'warning');
    }

    private static function bots(): void {
        $s = Settings::get_all();
        self::card_open('Origin & bot protection', 'IP rate rules, scanner detection and conservative limits');
        if (defined('NCG_MU_SEARCH_GUARD_LOADED')) self::note('Strict early Search Guard MU is independently loaded from wp-content/mu-plugins and is NOT disabled by the bot switch.', 'warning');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="section" value="bots">';
        self::checkbox('bot_module','Bot Protection module',Modules::on('bot'),'Disables ordinary plugin request protection when off. Independent MU Search Guard remains separate.');
        self::checkbox('enabled','Enable origin protection',!!$s['enabled']);
        self::checkbox('dry_run','Dry run before enforcement',!!$s['dry_run'],'Observe decisions before blocking visitors.');
        self::number('origin_threshold','Requests per origin window',(int)$s['origin_threshold'],1,10000);
        self::number('rest_limit','REST requests per configured window',(int)$s['rest_limit'],1,10000);
        self::number('limit_404','404 limit',(int)$s['limit_404'],1,10000);
        self::action_form('nrg_save_core');echo '</form>';
        echo '<a class="nrg-text-link" href="' . esc_url(admin_url('options-general.php?page=news-cache-guard')) . '">Advanced IP allowlist, CIDR policies and XML-RPC rules →</a>';
        self::card_close();
    }

    private static function workers(): void {
        self::card_open('Worker protection', 'Limits background workloads without changing PHP-FPM process counts');
        self::system_rows_worker();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        self::checkbox('worker','Enable Worker Guard',Modules::on('worker'),
            'Turning this off removes Action Scheduler and sitemap caps. Existing cron configuration is unchanged.');
        echo '<input type="hidden" name="switch" value="worker">';
        self::action_form('nrg_save_modules','Save Worker Guard setting');
        echo '</form><a class="nrg-text-link" href="' . esc_url(admin_url('tools.php?page=ncg-worker-guard')) . '">Detailed worker diagnostics and cron instructions →</a>';
        self::card_close();
    }

    private static function system_rows_worker(): void {
        $rows = [
            ['Action Scheduler claim cap',(string)WorkerGuard::batch_size()],
            ['Concurrent claims cap',(string)WorkerGuard::concurrent_batches()],
            ['Yoast sitemap entries',(string)WorkerGuard::yoast_cap()],
            ['Rank Math sitemap entries',(string)WorkerGuard::rank_math_cap()],
            ['Async loopbacks',WorkerGuard::disable_async_runner() ? 'Disabled by wp-config' : 'Preserved'],
        ];
        echo '<div class="nrg-system-list">';
        foreach($rows as [$k,$v]) echo '<div><span>' . esc_html($k) . '</span><strong>' . esc_html($v) . '</strong></div>';
        echo '</div>';
    }

    private static function approvals(): void {
        self::card_open('Independent editorial review', 'Contributor and Author submissions require an independent Editor; Editor submissions require a different Editor');
        if (!Editorial::on('approvals')) self::note('Approvals are disabled. Enable the editorial master and Approvals group in Module Manager.', 'warning');
        else EditorialAdmin::queue();
        self::card_close();
    }

    private static function audit(bool $alerts): void {
        self::card_open($alerts ? 'Security event feed' : 'Publishing accountability',
            'Server-side pagination, UTC dates, permitted filters and controlled access');
        if (!self::tables_ready()) {self::note('Editorial database tables are not installed or accessible.', 'warning'); self::card_close();return;}
        if (!Editorial::on('audit')) self::note('Audit collection is OFF. Older records, if any, remain available to Administrators.', 'warning');
        $filters = self::filters();
        echo '<form method="get" class="nrg-filters"><input type="hidden" name="page" value="' . self::SLUG .
            '"><input type="hidden" name="tab" value="' . ($alerts?'security':'audit') . '">';
        echo '<label>From (UTC)<input type="date" name="after" value="' . esc_attr($filters['after']) . '"></label>';
        echo '<label>To (UTC)<input type="date" name="before" value="' . esc_attr($filters['before']) . '"></label>';
        echo '<label>Actor ID<input type="number" min="0" name="actor" value="' . esc_attr((string)$filters['actor']) . '"></label>';
        echo '<label>Action<input type="text" name="event" maxlength="60" value="' . esc_attr($filters['event']) . '" placeholder="e.g. post_edited"></label>';
        echo '<button class="button nrg-filter-btn">Apply filters</button></form>';
        $rows=self::events($filters,$alerts,self::LIMIT+1, max(0,($filters['page']-1)*self::LIMIT));
        $more=count($rows)>self::LIMIT;
        echo '<div class="nrg-table-wrap"><table class="widefat nrg-table"><thead><tr><th>When (UTC)</th><th>Activity</th><th>Article</th><th>Actor</th><th>Source</th><th>IP</th><th>Priority</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="7"><div class="nrg-empty">No events match the selected filters.</div></td></tr>';
        foreach(array_slice($rows,0,self::LIMIT) as $r) {
            echo '<tr><td>' . esc_html($r->created_utc) . '</td><td><strong>' . esc_html($r->action) .
                '</strong><details><summary>Evidence</summary><pre>' . esc_html((string)$r->details) . '</pre></details></td><td>' .
                (int)$r->post_id . '</td><td>' . (int)$r->actor_id . '</td><td>' . esc_html($r->source) .
                '</td><td>' . esc_html($r->ip) . '</td><td>';
            self::chip($r->severity, $r->severity==='warning'||$r->severity==='critical'?'danger':'soft');
            echo '</td></tr>';
        }
        echo '</tbody></table></div><div class="nrg-pagination">';
        if ($filters['page']>1) echo '<a class="button" href="' . esc_url(add_query_arg('paged',$filters['page']-1)) . '">Previous</a>';
        echo '<span>Page ' . $filters['page'] . '</span>';
        if ($more) echo '<a class="button" href="' . esc_url(add_query_arg('paged',$filters['page']+1)) . '">Next</a>';
        echo '</div>';self::card_close();
    }

    private static function valid_date($value): string {
        $value = is_string($value) ? $value : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)) return '';
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));
        return $d && $d->format('Y-m-d')===$value ? $value : '';
    }

    public static function filters(): array {
        $source=($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
        return [
            'after'=>self::valid_date(sanitize_text_field(wp_unslash($source['after']??''))),
            'before'=>self::valid_date(sanitize_text_field(wp_unslash($source['before']??''))),
            'actor'=>absint($source['actor']??0),
            'event'=>substr(sanitize_key(wp_unslash($source['event']??'')),0,60),
            'page'=>max(1,absint($source['paged']??1)),
        ];
    }

    private static function query_events(array $f,bool $alerts,int $limit,int $offset): array {
        $where=['1=1'];$args=[];
        if ($alerts) $where[]="severity IN ('warning','critical')";
        if ($f['after']){$where[]='created_utc >= %s';$args[]=$f['after'].' 00:00:00';}
        if ($f['before']){$where[]='created_utc < %s';$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$f['before'],new \DateTimeZone('UTC'));$args[]=$date->modify('+1 day')->format('Y-m-d 00:00:00');}
        if ($f['actor']){$where[]='actor_id = %d';$args[]=$f['actor'];}
        if ($f['event']){$where[]='action = %s';$args[]=$f['event'];}
        $args[]=min(5001,max(1,$limit));$args[]=max(0,$offset);
        $sql=' WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT %d OFFSET %d';
        return [$sql,$args];
    }

    private static function events(array $filters,bool $alerts,int $limit,int $offset): array {
        global $wpdb;
        $table=Editorial::table('events');
        [$where,$args]=self::query_events($filters,$alerts,$limit,$offset);
        return (array)$wpdb->get_results($wpdb->prepare("SELECT * FROM $table".$where,...$args));
    }

    private static function lock(): void {
        $e=Editorial::settings();
        self::card_open('Post-publication rules','The original published timestamp determines the deadline');
        if (empty($e['master'])) self::note('Editorial master is OFF. Post Lock will not enforce until it is enabled.', 'warning');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        self::checkbox('lock','Enable Post Lock',!empty($e['lock']), 'Enforcement also requires Editorial Master ON.');
        self::number('lock_hours','Lock after (hours)',(int)$e['lock_hours'],0,720,'Default: 4 hours. 0 means immediately.');
        echo '<label class="nrg-field"><span><strong>Exempt user IDs</strong><small>Comma-separated IDs. Default 529. Never bypasses independent approval.</small></span><input type="text" name="bypass_ids" value="' .
            esc_attr((string)$e['bypass_ids']) . '"></label>';
        self::action_form('nrg_save_lock');echo '</form>';self::card_close();
    }

    private static function reports(): void {
        if (!Modules::on('reports')) {self::note('Reports module is OFF. Enable it from Module Manager. Existing audit evidence is preserved.','warning');return;}
        self::card_open('Export editorial evidence','Maximum 5,000 latest matching events per export; UTC timestamps; administrator-only');
        if (!self::tables_ready()) self::note('No editorial audit table is available.', 'warning');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="nrg-export-form">';
        $f=self::filters();
        echo '<label>From (UTC)<input type="date" name="after" value="' . esc_attr($f['after']) . '"></label>';
        echo '<label>To (UTC)<input type="date" name="before" value="' . esc_attr($f['before']) . '"></label>';
        echo '<label>Actor user ID<input type="number" min="0" name="actor" value="' . esc_attr((string)$f['actor']) . '"></label>';
        echo '<label>Action<input type="text" name="event" maxlength="60" placeholder="Optional event name"></label>';
        echo '<label class="nrg-export-check"><input type="checkbox" name="alerts_only" value="1"> Security events only</label>';
        self::action_form('nrg_export_events','Export CSV');
        echo '</form><p class="nrg-footnote">CSV protects spreadsheet formula cells. Export access is restricted to administrators; handle IP addresses and editorial data under your retention policy.</p>';
        self::card_close();
    }

    private static function settings(): void {
        $e=Editorial::settings();
        echo '<div class="nrg-columns">';
        self::card_open('Module Manager','Changes are saved server-side; each supported module executes only when enabled');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="switch" value="all">';
        self::checkbox('bot','Bot Protection',Modules::on('bot'),'Ordinary WordPress request limits; independently installed MU Search Guard is not affected.');
        self::checkbox('worker','Worker Guard',Modules::on('worker'),'Action Scheduler and sitemap caps; default ON for existing installs.');
        self::checkbox('reports','Reports and CSV exports',Modules::on('reports'),'Administrator-only reporting, no public-page cost.');
        self::checkbox('master','Editorial master',!empty($e['master']),'OFF by default. Enables the selected editorial rules below.');
        self::checkbox('audit','Publishing Audit',!empty($e['audit']),'Records publication changes in dedicated tables.');
        self::checkbox('security','Security Intelligence',!empty($e['security']),'Requires Editorial Master and Publishing Audit to record alerts.');
        self::checkbox('approvals','Two-person Editorial Approvals',!empty($e['approvals']),'Independent review, including Editors approving other Editors.');
        self::checkbox('lock','Post-Publication Lock',!empty($e['lock']),'Time-based restrictions with explicit exemptions.');
        echo '<label class="nrg-confirm"><input type="checkbox" name="confirm_enforcement" value="1"><span>I have validated editorial enforcement on staging and understand that enabling it changes publishing permissions.</span></label>';
        self::action_form('nrg_save_modules','Save module configuration');
        echo '</form>';self::card_close();
        self::card_open('Configuration status', 'Core systems and future modules');
        self::system_rows();
        echo '<div class="nrg-divider"></div><h3>Cache & Performance</h3><p>LiteSpeed keeps its own cache controls. Change this plugin’s query and purge controls in the Cache tab.</p>';
        echo '<a class="nrg-text-link" href="' . esc_url(self::url('cache')) . '">Open cache controls →</a>';
        echo '<div class="nrg-divider"></div><h3>AI Intelligence</h3><p>Not implemented. No pretend AI switch is shown.</p>';
        echo '<div class="nrg-divider"></div><h3>Advanced origin rules</h3>';
        echo '<p>Existing CIDR rules, proxy trust, XML-RPC and diagnostic tools are preserved.</p>';
        echo '<a class="nrg-text-link" href="' . esc_url(admin_url('options-general.php?page=news-cache-guard')) . '">Open advanced settings →</a>';
        self::card_close();echo '</div>';
    }

    private static function redirect(string $tab,string $notice): void {
        wp_safe_redirect(self::url($tab, ['nrg_notice'=>$notice]));
        exit;
    }

    public static function save_modules(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden',403);
        check_admin_referer('nrg_save_modules');
        $scope=sanitize_key(wp_unslash($_POST['switch']??'all'));
        $m=Modules::all();$e=Editorial::settings();
        $keys=$scope==='worker' ? ['worker'] : ['bot','worker','reports'];
        foreach ($keys as $key) $m[$key]=!empty($_POST[$key]) ? 1 : 0;
        if ($scope==='all') {
            foreach (['master','audit','security','approvals','lock'] as $key) $e[$key]=!empty($_POST[$key])?1:0;
            $previous=Editorial::settings();
            if (!empty($e['master']) && empty($previous['master']) && empty($_POST['confirm_enforcement']))
                self::redirect('settings','confirm_required');
            // Enabling a privileged workflow without persistent storage is unsafe.
            if (!empty($e['master']) && !self::tables_ready()) {
                Editorial::install();
                if (!self::tables_ready()) wp_die('Editorial tables unavailable; no settings were changed.',403);
            }
        }
        update_option(Modules::OPTION, Modules::sanitize($m), false);
        if ($scope==='all') update_option(Editorial::OPTION, Editorial::clean($e), false);
        self::redirect($scope==='worker'?'workers':'settings','module_saved');
    }

    public static function save_core(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden',403);
        check_admin_referer('nrg_save_core');
        $section=sanitize_key(wp_unslash($_POST['section']??''));
        if (!in_array($section,['cache','bots'],true)) wp_die('Invalid settings area',400);
        $values=Settings::get_all();
        $bools=$section==='cache' ?
            ['query_collapse','ignore_request_no_cache','purge_home','purge_categories','purge_feeds','purge_sitemap'] :
            ['enabled','dry_run'];
        foreach($bools as $name) $values[$name]=!empty($_POST[$name])?1:0;
        if ($section==='bots') {
            foreach (['origin_threshold','rest_limit','limit_404'] as $key) {
                if (isset($_POST[$key])) $values[$key]=max(1,(int)$_POST[$key]);
            }
        }
        $clean=Settings::sanitize($values);
        // Do not accidentally raise the hard-coded search limit or reset CIDRs.
        update_option(Settings::OPTION,$clean,false);
        if ($section==='bots') {
            $m=Modules::all();
            $m['bot']=!empty($_POST['bot_module'])?1:0;
            update_option(Modules::OPTION, Modules::sanitize($m),false);
        }
        $notice='saved';
        if($section==='cache'){
            $result=Htaccess::sync();
            if (empty($result['ok'])) $notice='htaccess_failed';
        }
        self::redirect($section,$notice);
    }

    public static function save_lock(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden',403);
        check_admin_referer('nrg_save_lock');
        $e=Editorial::settings();
        $e['lock']=!empty($_POST['lock'])?1:0;
        $e['lock_hours']=isset($_POST['lock_hours'])?(int)$_POST['lock_hours']:(int)$e['lock_hours'];
        $e['bypass_ids']=sanitize_text_field(wp_unslash($_POST['bypass_ids']??''));
        update_option(Editorial::OPTION, Editorial::clean($e),false);
        self::redirect('lock','saved');
    }

    private static function safe_csv($value): string {
        $value=(string)$value;
        // Prevent spreadsheet formula interpretation, including leading whitespace.
        return preg_match('/^\s*[=+@\-\t\r]/u',$value) ? "'".$value : $value;
    }

    public static function export_events(): void {
        if (!current_user_can('manage_options') || !Modules::on('reports')) wp_die('Forbidden',403);
        check_admin_referer('nrg_export_events');
        if (!self::tables_ready()) wp_die('Audit table missing; export unavailable.',503);
        $filters=self::filters();
        $alerts=!empty($_POST['alerts_only']);
        $rows=self::events($filters,$alerts,5000,0);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="newsroom-guard-audit-'.gmdate('Ymd-His').'.csv"');
        $out=fopen('php://output','w');
        fputcsv($out,['Time UTC','Action','Post ID','Actor user ID','Source','Client IP','Severity','Details JSON']);
        foreach($rows as $r){
            $values=[$r->created_utc,$r->action,(int)$r->post_id,(int)$r->actor_id,
                $r->source,$r->ip,$r->severity,$r->details];
            fputcsv($out,array_map([self::class,'safe_csv'],$values));
        }
        fclose($out);
        exit;
    }
}
