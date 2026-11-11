<?php
declare(strict_types=1);
namespace NCG;
if (!defined('ABSPATH')) exit;

final class EditorialAdmin {
    public static function boot(): void {
        add_action('admin_init', [self::class, 'register']);
    }
    public static function register(): void {
        register_setting('ncg_editorial', Editorial::OPTION, [
            'type'=>'array', 'default'=>Editorial::defaults(),
            'sanitize_callback'=>[Editorial::class,'clean'],
        ]);
    }
    public static function tabs(string $active = 'cache'): void {
        echo '<nav class="nav-tab-wrapper" style="margin:16px 0 20px">';
        foreach (['cache'=>'Cache & Origin Protection','editorial'=>'Editorial & Publishing'] as $key=>$label) {
            echo '<a class="nav-tab ' . ($active === $key ? 'nav-tab-active' : '') . '" href="' .
                esc_url(add_query_arg(['page'=>'news-cache-guard','ncg_tab'=>$key], admin_url('options-general.php'))) . '">' .
                esc_html($label) . '</a>';
        }
        echo '</nav>';
    }
    private static function toggle(string $name, string $label, array $s, string $desc): void {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="' .
            esc_attr(Editorial::OPTION) . '[' . esc_attr($name) . ']" value="1" ' .
            checked(!empty($s[$name]), true, false) . '> Enabled</label><p class="description">' .
            esc_html($desc) . '</p></td></tr>';
    }
    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden.');
        $s = Editorial::settings();
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'settings';
        echo '<div class="wrap"><h1>News Cache Guard <small style="font-size:13px">Editorial & Publishing</small></h1>';
        self::tabs('editorial');
        echo '<p>Independent editorial controls. Cache protection and public page caching are unaffected by every switch below.</p>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:10px;margin:14px 0">';
        foreach (['settings'=>'Settings','queue'=>'Approval Queue','audit'=>'Audit Log','alerts'=>'Security Alerts'] as $key=>$label) {
            echo '<a class="button ' . ($view === $key ? 'button-primary' : '') . '" href="' .
                esc_url(add_query_arg(['page'=>'news-cache-guard','ncg_tab'=>'editorial','view'=>$key], admin_url('options-general.php'))) . '">' .
                esc_html($label) . '</a>';
        }
        echo '</div>';
        if (!empty($_GET['ncg_ed_notice'])) echo '<div class="notice notice-info"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['ncg_ed_notice']))) . '</p></div>';
        if ($view === 'queue') self::queue();
        elseif ($view === 'audit' || $view === 'alerts') self::events($view);
        elseif ($view === 'propose') self::proposal();
        else self::settings_form($s);
        echo '</div>';
    }
    private static function settings_form(array $s): void {
        if (!empty($s['master']) && empty($s['audit']) && !empty($s['security']))
            echo '<div class="notice notice-warning"><p>Security alerts require Audit Logging. Enable Audit Logging to capture evidence.</p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '">';
        settings_fields('ncg_editorial');
        echo '<table class="form-table" role="presentation">';
        self::toggle('master','Master Editorial Module',$s,'Default OFF. Enable only after testing permissions and approvals on staging. All groups below depend on this switch.');
        self::toggle('audit','Group 1 · Publishing Audit',$s,'Tracks publication, edits, login IPs, sensitive SEO changes, deletes and editorial activity.');
        self::toggle('security','Group 2 · Security Intelligence',$s,'New editor IP, privilege changes, added external links and privileged corrections. Requires Audit ON.');
        self::toggle('approvals','Group 3 · Two-Person Editorial Approval',$s,'Authors and Editors submit for independent review; an Editor needs another Editor. Administrators retain emergency direct publication.');
        self::toggle('lock','Group 4 · Post-Publication Lock',$s,'After the configured interval, direct edits and deletions are denied to non-exempt users.');
        echo '<tr><th>Lock after (hours)</th><td><input type="number" min="0" max="720" name="' . esc_attr(Editorial::OPTION) . '[lock_hours]" value="' . esc_attr((string)$s['lock_hours']) . '"> <p class="description">Default 4. Zero locks immediately after publishing.</p></td></tr>';
        echo '<tr><th>Exempt user IDs</th><td><input type="text" class="regular-text" name="' . esc_attr(Editorial::OPTION) . '[bypass_ids]" value="' . esc_attr((string)$s['bypass_ids']) . '"><p class="description">Comma-separated. Default 529. WordPress Administrators are always exempt from lock and can emergency-publish.</p></td></tr>';
        echo '<tr><th>Log retention (days)</th><td><input type="number" min="30" max="3650" name="' . esc_attr(Editorial::OPTION) . '[retention_days]" value="' . esc_attr((string)$s['retention_days']) . '"></td></tr>';
        self::toggle('email_alerts','Email high-priority alerts',$s,'Send rate-limited messages to the site administrator email (when audit and security groups are active).');
        echo '</table>';
        submit_button('Save Editorial Settings');
        echo '</form><hr><h2>Operational safeguards</h2><p>Turning a group OFF prevents new enforcement or events, but preserves existing audit records and review requests. Turning Approvals OFF does not automatically publish pending articles. The master switch never changes cache, LiteSpeed or Redis settings.</p>';
        echo '<p><strong>Separation of duties:</strong> Administrators retain an emergency publication exception; this is audited when Audit Logging is on. WordPress administrators can also deactivate plugins, so external governance is required for absolute enforcement.</p>';
    }
    private static function queue(): void {
        if (!Editorial::on('approvals')) { echo '<p>Enable Master and Approval group to process requests.</p>'; return; }
        global $wpdb;
        $table = Editorial::table('reviews');
        $records = $wpdb->get_results("SELECT * FROM $table WHERE status='pending' ORDER BY created_utc ASC LIMIT 100");
        echo '<h2>Pending Editorial Review</h2>';
        if (!$records) { echo '<p>No pending requests.</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Article</th><th>Submitted by</th><th>Type</th><th>Requested</th><th>Decision</th></tr></thead><tbody>';
        foreach ($records as $r) {
            $p = get_post((int)$r->post_id);
            if (!$p) continue;
            echo '<tr><td><strong>' . esc_html(get_the_title($p)) . '</strong><br><small>Post #' . (int)$p->ID .
                ' · ' . esc_html($r->policy) . '</small></td><td>' . esc_html(get_the_author_meta('display_name', (int)$r->submitter_id)) .
                '</td><td>' . esc_html($r->kind) . '</td><td>' . esc_html($r->created_utc) . ' UTC</td><td>';
            if (EditorialWorkflow::eligible($r, get_current_user_id())) {
                echo '<details><summary>Review snapshot</summary><div style="max-width:420px;max-height:250px;overflow:auto;white-space:pre-wrap;border:1px solid #ddd;padding:8px;margin:8px 0">' .
                    esc_html(wp_json_encode(json_decode($r->payload, true), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) . '</div>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ncg_ed_review"><input type="hidden" name="review_id" value="' . (int)$r->id . '">';
                wp_nonce_field('ncg_ed_review');
                echo '<textarea name="note" rows="2" style="width:100%" placeholder="Reason for rejection or requested changes"></textarea>';
                echo '<button name="decision" value="approve" class="button button-primary">Approve & Publish</button> ';
                echo '<button name="decision" value="changes" class="button">Request Changes</button> ';
                echo '<button name="decision" value="reject" class="button">Reject</button></form></details>';
            } else echo '<span style="color:#a32d2d">Independent reviewer required</span>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    private static function proposal(): void {
        if (!Editorial::on('approvals')) { echo '<p>Approval workflow is disabled.</p>'; return; }
        $id = absint($_GET['post'] ?? 0);
        if (!EditorialWorkflow::may_propose($id, get_current_user_id())) { echo '<p>You cannot propose changes to this article.</p>'; return; }
        $p = get_post($id);
        echo '<h2>Propose revision: ' . esc_html($p->post_title) . '</h2><p>The live article remains unchanged until an independent editor approves the proposal.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:900px"><input type="hidden" name="action" value="ncg_ed_propose"><input type="hidden" name="post_id" value="' . $id . '">';
        wp_nonce_field('ncg_ed_propose');
        foreach (['title'=>'Headline','slug'=>'Slug','thumbnail'=>'Featured image attachment ID'] as $key=>$label) {
            $value = $key === 'title' ? $p->post_title : ($key === 'slug' ? $p->post_name : get_post_thumbnail_id($id));
            echo '<p><label><strong>' . esc_html($label) . '</strong><br><input class="large-text" name="' . esc_attr($key) . '" value="' . esc_attr((string)$value) . '"></label></p>';
        }
        echo '<p><label><strong>Article content</strong><br><textarea name="content" rows="16" class="large-text code">' . esc_textarea($p->post_content) . '</textarea></label></p>';
        echo '<p><label><strong>Excerpt</strong><br><textarea name="excerpt" rows="3" class="large-text">' . esc_textarea($p->post_excerpt) . '</textarea></label></p>';
        echo '<p><label><strong>Categories (Ctrl/Cmd to select multiple)</strong><br><select multiple name="categories[]" style="min-width:280px;min-height:130px">';
        $current = wp_get_post_categories($id);
        foreach (get_categories(['hide_empty'=>false]) as $cat) {
            echo '<option value="' . (int)$cat->term_id . '" ' . selected(in_array((int)$cat->term_id, $current, true), true, false) . '>' . esc_html($cat->name) . '</option>';
        }
        echo '</select></label></p>';
        submit_button('Submit Revision for Approval');
        echo '</form>';
    }
    private static function events(string $view): void {
        if (!Editorial::on('audit')) { echo '<p>Audit Logging is disabled.</p>'; return; }
        global $wpdb;
        $table = Editorial::table('events');
        $page = max(1, absint($_GET['paged'] ?? 1));
        $start = isset($_GET['after']) ? sanitize_text_field(wp_unslash($_GET['after'])) : '';
        $filter = isset($_GET['actor']) ? absint($_GET['actor']) : 0;
        $sql = "SELECT * FROM $table WHERE 1=1";
        $params = [];
        if ($view === 'alerts') $sql .= " AND severity IN ('warning','critical')";
        if ($filter) { $sql .= ' AND actor_id=%d'; $params[]=$filter; }
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $start)) { $sql .= ' AND created_utc >= %s'; $params[] = $start . ' 00:00:00'; }
        $sql .= ' ORDER BY id DESC LIMIT 51 OFFSET %d';
        $params[] = ($page-1)*50;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        echo '<h2>' . ($view === 'alerts' ? 'Security Alerts' : 'Publishing Audit Log') . '</h2>';
        echo '<form method="get"><input type="hidden" name="page" value="news-cache-guard"><input type="hidden" name="ncg_tab" value="editorial"><input type="hidden" name="view" value="' . esc_attr($view) . '">';
        echo 'After (UTC): <input type="date" name="after" value="' . esc_attr($start) . '"> Actor user ID: <input type="number" min="0" name="actor" value="' . esc_attr((string)$filter) . '"> <button class="button">Filter</button></form>';
        echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>UTC</th><th>Action</th><th>Post</th><th>Actor</th><th>Source</th><th>IP</th><th>Severity</th><th>Details</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, 50) as $r) {
            echo '<tr><td>' . esc_html($r->created_utc) . '</td><td>' . esc_html($r->action) . '</td><td>' . (int)$r->post_id .
                '</td><td>' . (int)$r->actor_id . '</td><td>' . esc_html($r->source) . '</td><td><code>' . esc_html($r->ip) .
                '</code></td><td>' . esc_html($r->severity) . '</td><td><details><summary>View</summary><pre style="white-space:pre-wrap;max-width:380px">' .
                esc_html((string)$r->details) . '</pre></details></td></tr>';
        }
        echo '</tbody></table>';
        if ($page > 1) echo '<a class="button" href="' . esc_url(add_query_arg('paged', $page-1)) . '">Previous</a> ';
        if (count($rows) > 50) echo '<a class="button" href="' . esc_url(add_query_arg('paged', $page+1)) . '">Next</a>';
    }
}
