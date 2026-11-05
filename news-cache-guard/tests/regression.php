<?php
/** Standalone regression checks; no WordPress installation required. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$GLOBALS['ncg_config'] = [];
$GLOBALS['ncg_cache'] = [];
$GLOBALS['ncg_actions'] = [];
$GLOBALS['ncg_post'] = new WP_Post('publish');

class WP_Post {
    public string $post_status;
    public function __construct(string $status) { $this->post_status = $status; }
}
class WP_REST_Server {}
class WP_REST_Request {}
class WP_Error {
    public function __construct($code, $message, $details) {}
}
function get_option($key, $default = null) { return $GLOBALS['ncg_config']; }
function wp_using_ext_object_cache() { return true; }
function wp_cache_add($key, $value, $group, $ttl) {
    if (isset($GLOBALS['ncg_cache'][$group][$key])) return false;
    $GLOBALS['ncg_cache'][$group][$key] = $value;
    return true;
}
function wp_cache_incr($key, $by, $group) {
    if (!isset($GLOBALS['ncg_cache'][$group][$key])) return false;
    return $GLOBALS['ncg_cache'][$group][$key] += $by;
}
function wp_cache_set($key, $val, $group, $ttl) { $GLOBALS['ncg_cache'][$group][$key] = $val; return true; }
function wp_cache_get($key, $group) { return $GLOBALS['ncg_cache'][$group][$key] ?? false; }
function wp_cache_delete($key, $group) { unset($GLOBALS['ncg_cache'][$group][$key]); return true; }
function add_action(...$args) {}
function add_filter(...$args) {}
function do_action($action, ...$args) { $GLOBALS['ncg_actions'][] = $action; }
function is_admin() { return false; }
function wp_doing_cron() { return false; }
function is_user_logged_in() { return false; }
function is_search() { return $GLOBALS['is_search'] ?? false; }
function is_404() { return false; }
function is_preview() { return false; }
function is_singular() { return true; }
function get_queried_object() { return $GLOBALS['ncg_post']; }
function get_queried_object_id() { return 12; }
function is_home() { return false; }
function is_front_page() { return false; }
function is_category() { return false; }
function is_tag() { return false; }
function is_archive() { return false; }
function is_feed() { return false; }

require_once __DIR__ . '/../includes/class-ncg-settings.php';
require_once __DIR__ . '/../includes/class-ncg-ip.php';
require_once __DIR__ . '/../includes/class-ncg-counter.php';
require_once __DIR__ . '/../includes/class-ncg-htaccess.php';
require_once __DIR__ . '/../includes/class-ncg-litespeed.php';
require_once __DIR__ . '/../includes/class-ncg-guard.php';

function check(bool $ok, string $description): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$description}\n"); exit(1); }
    echo "PASS: {$description}\n";
}
function config(array $overrides = []): void { $GLOBALS['ncg_config'] = $overrides + \NCG\Settings::defaults(); }
function reset_actions(): void { $GLOBALS['ncg_actions'] = []; }
$_SERVER['REMOTE_ADDR'] = '203.0.113.21';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/article/';
$_COOKIE = [];
$_GET = [];
config();

check(\NCG\IP::in_cidr('203.0.113.200', '203.0.113.0/24'), 'IPv4 CIDR match');
check(!\NCG\IP::in_cidr('203.0.114.2', '203.0.113.0/24'), 'IPv4 CIDR mismatch');
check(\NCG\IP::bucket('2001:db8:1234:5678::1') === \NCG\IP::bucket('2001:db8:1234:5678::2'), 'IPv6 /64 bucket normalization');
check(!\NCG\IP::in_cidr('2001:db8:2::1', '2001:db8:1::/48'), 'IPv6 CIDR mismatch');
check(\NCG\IP::in_cidr('2001:0DB8::1', '2001:db8::1'), 'Equivalent textual forms of an IPv6 IP match');

config(['drop_query_keys'=>"s*\npreview*\nutm*\ngclid", 'protected_query_keys'=>"s\npreview\n_wpnonce"]);
check(\NCG\Htaccess::safe_drop_keys(\NCG\Settings::lines('drop_query_keys')) === ['utm*','gclid'], 'Do not drop wildcards shadowing protected keys');
check(\NCG\Htaccess::matches_drop_key('utm_source'), 'UTM query key matching');
check(!\NCG\Htaccess::matches_drop_key('UTM_source'), 'LiteSpeed query key matching is case sensitive');
check(!\NCG\Htaccess::matches_drop_key('preview'), 'Preview key is protected');

config(['default_policy' => 'force_cache']);
reset_actions();
(new \NCG\Guard())->frontend_guard();
check(in_array('litespeed_control_force_public', $GLOBALS['ncg_actions'], true), 'Explicit force-cache on safe public response');

$_GET = ['special_offer' => 'private'];
reset_actions();
(new \NCG\Guard())->frontend_guard();
check(!in_array('litespeed_control_force_public', $GLOBALS['ncg_actions'], true), 'Unknown query arguments never force public cache');
$_GET = [];
$_COOKIE['wp_woocommerce_session_abc'] = 'session';
reset_actions();
(new \NCG\Guard())->frontend_guard();
check(!in_array('litespeed_control_force_public', $GLOBALS['ncg_actions'], true), 'Commerce session never force public cache');
$_COOKIE = [];

config(['allowlist' => '203.0.113.0/24', 'default_policy' => 'force_cache']);
reset_actions();
(new \NCG\Guard())->frontend_guard();
check(!in_array('litespeed_control_force_public', $GLOBALS['ncg_actions'], true), 'IP allowlist never forces public cache');

config(['default_policy' => 'safe_shared']);
reset_actions();
(new \NCG\Guard())->frontend_guard();
check(!in_array('litespeed_control_set_cacheable', $GLOBALS['ncg_actions'], true), 'Safe Shared Cache defers to LSCWP eligibility');

config(['search_limit'=>999,'dry_run'=>1]);
check(\NCG\Settings::get('search_limit') === 3, 'Saved search rate limit cannot exceed 3');
check(\NCG\Settings::get('search_window') === 60, 'Search fallback window is locked to 60 seconds');
$_SERVER['REQUEST_URI'] = '/?s=word';
$_GET = ['s'=>'word'];
for ($i = 0; $i < 4; $i++) (new \NCG\Guard())->early_scanner_guard();
check(\NCG\Counter::stat_value('would_block') === 1, 'Fourth search gets rate-limited before main query');
$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/search/?s=word';
for ($i = 0; $i < 5; $i++) (new \NCG\Guard())->early_scanner_guard();
check(\NCG\Counter::stat_value('would_block') === 1, 'REST endpoint is not mistaken for a search');
$_SERVER['REQUEST_URI'] = '/?s=word';
define('NCG_MU_SEARCH_GUARD_HANDLED', true);
(new \NCG\Guard())->early_scanner_guard();
$GLOBALS['is_search'] = true;
(new \NCG\Guard())->frontend_guard();
$GLOBALS['is_search'] = false;
check(\NCG\Counter::stat_value('would_block') === 1, 'MU-handled search is not counted during either regular-plugin hook');

config(['rest_limit'=>1, 'dry_run'=>1]);
$_GET = [];
$g = new \NCG\Guard();
$g->rest_guard(null, new WP_REST_Server(), new WP_REST_Request());
$g->rest_guard(null, new WP_REST_Server(), new WP_REST_Request());
check(\NCG\Counter::stat_value('would_block') === 1, 'REST dispatch counted at most once per PHP request');
config(['xmlrpc_mode'=>'disable']);
check(!(new \NCG\Guard())->xmlrpc_enabled(true), 'XML-RPC mode disables authenticated methods');
config(['xmlrpc_mode'=>'disable','enabled'=>0]);
check((new \NCG\Guard())->xmlrpc_enabled(true), 'Global protection toggle disables XML-RPC enforcement');

echo "All regression checks passed.\n";
