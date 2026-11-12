<?php
/** Standalone regression checks; no WordPress installation required. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$GLOBALS['ncg_config'] = [];
$GLOBALS['ncg_cache'] = [];
$GLOBALS['ncg_actions'] = [];
$GLOBALS['ncg_atomic_fail'] = false;
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
    if ($GLOBALS['ncg_atomic_fail'] || !isset($GLOBALS['ncg_cache'][$group][$key])) return false;
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
function config(array $overrides = []): void { $GLOBALS['ncg_config'] = $overrides + \NCG\Settings::defaults(); \NCG\Settings::invalidate(); }
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

// Request no-cache headers must never imply Force Public cache or response overrides.
config(['query_collapse' => 0, 'ignore_request_no_cache' => 0]);
check(\NCG\Htaccess::rules() === [], 'No rewrite rules when query collapse and client cache reuse are both off');
config(['query_collapse' => 0, 'ignore_request_no_cache' => 1]);
$revalidationRules = \NCG\Htaccess::rules();
check(in_array('CacheIgnoreCacheControl On', $revalidationRules, true), 'Advanced opt-in enables LiteSpeed client no-cache reuse even without query collapse');
check(!in_array('CacheEnable public /', $revalidationRules, true), 'Client no-cache reuse does not globally force public caching');
config(['query_collapse' => 1, 'ignore_request_no_cache' => 1]);
check(in_array('CacheKeyModify -qs:utm*', \NCG\Htaccess::rules(), true), 'Client no-cache reuse preserves query key normalization');
config(['query_collapse' => 1, 'ignore_request_no_cache' => 0]);
check(!in_array('CacheIgnoreCacheControl On', \NCG\Htaccess::rules(), true), 'Cache bypass request behavior remains default when opt-in is off');
check(\NCG\Settings::sanitize(['ignore_request_no_cache' => '1'])['ignore_request_no_cache'] === 1, 'Validated admin opt-in saves setting');
check(\NCG\Settings::sanitize([])['ignore_request_no_cache'] === 0, 'Opt-in is disabled when setting is omitted');

config(['default_policy' => 'force_cache']);
reset_actions();
(new \NCG\Guard())->frontend_guard();
check(!in_array('litespeed_control_force_public', $GLOBALS['ncg_actions'], true), 'Unsafe Force Cache policy cannot override LiteSpeed exclusions');
check(\NCG\Settings::get('default_policy') === 'safe_shared', 'Legacy Force Cache policy normalizes to Safe Shared Cache');
check(\NCG\Settings::sanitize(['default_policy' => 'force_cache'])['default_policy'] === 'safe_shared', 'New unsafe Force Cache settings are rejected');

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

// Unavailable proxy identity must fail open rather than grouping all visitors behind one edge.
config(['trust_cloudflare' => 1]);
$_SERVER['REMOTE_ADDR'] = '173.245.48.100';
unset($_SERVER['HTTP_CF_CONNECTING_IP']);
check(\NCG\IP::client_ip() === null, 'Cloudflare edge missing visitor header is untrusted');
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
check(\NCG\IP::client_ip() === null, 'Cloudflare edge invalid visitor header is untrusted');
$_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8::12';
check(\NCG\IP::client_ip() === '2001:db8::12', 'Cloudflare visitor IP accepted only for trusted edge');
$_SERVER['REMOTE_ADDR'] = '203.0.113.21';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '8.8.8.8';
check(\NCG\IP::client_ip() === '203.0.113.21', 'Untrusted peer cannot spoof a Cloudflare visitor IP');
unset($_SERVER['HTTP_CF_CONNECTING_IP']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
check(\NCG\IP::client_ip() === null, 'Local reverse proxy cannot become one shared visitor identity');
$_SERVER['REMOTE_ADDR'] = '10.0.0.4';
check(\NCG\IP::client_ip() === null, 'Private Cloudways hop does not become the visitor identity');
$_SERVER['REMOTE_ADDR'] = 'invalid-ip';
check(\NCG\IP::client_ip() === null, 'Invalid REMOTE_ADDR fails open');
$_SERVER['REMOTE_ADDR'] = '173.245.48.100';
$_SERVER['REQUEST_URI'] = '/?s=word';
$_GET = ['s' => 'word'];
$before = \NCG\Counter::stat_value('would_block');
for ($i = 0; $i < 6; $i++) (new \NCG\Guard())->early_scanner_guard();
check(\NCG\Counter::stat_value('would_block') === $before, 'Unknown proxy identity does not block all Cloudflare traffic');
$_SERVER['REMOTE_ADDR'] = '203.0.113.21';
$_GET = [];
$_SERVER['REQUEST_URI'] = '/article/';

// Atomic counter failures must not trigger non-atomic read/write increments.
$GLOBALS['ncg_cache'] = [];
check(\NCG\Counter::increment('atomic', 'visitor', 60) === 1, 'Atomic counter add');
$GLOBALS['ncg_atomic_fail'] = true;
check(\NCG\Counter::increment('atomic', 'visitor', 60) === 0, 'Atomic counter fails open on failed increment');
$GLOBALS['ncg_atomic_fail'] = false;
check(\NCG\Counter::increment('atomic', 'visitor', 60) === 2, 'Failed increment did not mutate the counter');
$check = \NCG\Counter::atomic_probe();
check($check['ok'], 'On-demand cache atomic probe passes for normal backend');
$GLOBALS['ncg_atomic_fail'] = true;
check(!\NCG\Counter::atomic_probe()['ok'], 'On-demand cache atomic probe detects failed increment');
$GLOBALS['ncg_atomic_fail'] = false;
$GLOBALS['ncg_cache'] = [];

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
