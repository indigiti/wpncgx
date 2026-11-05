<?php
/** Standalone MU Search Guard tests using a simulated atomic object cache. */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$GLOBALS['mu_actions'] = [];
$GLOBALS['mu_cache'] = [];
$GLOBALS['mu_settings'] = [];
$GLOBALS['mu_cache_enabled'] = true;
function add_action($hook, $callback, $priority=10) { $GLOBALS['mu_actions'][$hook] = $callback; }
function is_admin() { return false; }
function wp_doing_cron() { return false; }
function get_option($name, $fallback=[]) { return $GLOBALS['mu_settings']; }
function wp_using_ext_object_cache() { return $GLOBALS['mu_cache_enabled']; }
function wp_cache_add($key, $value, $group, $ttl) {
    if (isset($GLOBALS['mu_cache'][$group][$key])) return false;
    $GLOBALS['mu_cache'][$group][$key] = $value;
    return true;
}
function wp_cache_incr($key, $by, $group) {
    if (!isset($GLOBALS['mu_cache'][$group][$key])) return false;
    return $GLOBALS['mu_cache'][$group][$key] += $by;
}
require_once __DIR__ . '/../mu-plugins/wp-search-guard.php';
function check(bool $ok, string $description): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$description}\n"); exit(1); }
    echo "PASS: {$description}\n";
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['s' => 'test'];
check(defined('NCG_MU_SEARCH_GUARD_LOADED'), 'MU module loads independently of ordinary plugin');
check(isset($GLOBALS['mu_actions']['muplugins_loaded']), 'MU guard registered at muplugins_loaded');
check(\NCG\SearchMU\is_search_route($_GET, '/?s=test'), 'Query-string search detected');
check(\NCG\SearchMU\is_search_route([], '/search/test/'), 'Pretty permalink search detected');
check(!\NCG\SearchMU\is_search_route([], '/news/test/'), 'Ordinary article not treated as search');
check(\NCG\SearchMU\is_non_frontend_route(['s'=>'foo'], '/wp-login.php?s=foo'), 'Login endpoint excluded from search limiting');
check(\NCG\SearchMU\is_non_frontend_route(['s'=>'foo'], '/wp-json/wp/v2/search/?s=foo'), 'REST pretty route excluded from search limiting');
check(\NCG\SearchMU\is_non_frontend_route(['s'=>'foo', 'rest_route'=>'/wp/v2/search'], '/?s=foo'), 'REST query route excluded from search limiting');
check(!\NCG\SearchMU\is_non_frontend_route(['s'=>'foo'], '/?s=foo'), 'Ordinary search not excluded');
$now = 1770000000;
for ($n = 1; $n <= 3; $n++) {
    check(\NCG\SearchMU\evaluate($now)['status'] === 0, "Search {$n} accepted");
}
$r = \NCG\SearchMU\evaluate($now);
check($r['status'] === 429 && $r['retry_after'] >= 1 && $r['retry_after'] <= 60, 'Fourth search rejected with dynamic Retry-After');
check(\NCG\SearchMU\evaluate((intdiv($now, 60) + 1) * 60)['status'] === 0, 'Next fixed window resets counter');
$_SERVER['REMOTE_ADDR'] = '203.0.113.6';
check(\NCG\SearchMU\evaluate($now)['status'] === 0, 'Independent IPv4 client allowed');
$_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678::1';
for ($n = 1; $n <= 3; $n++) \NCG\SearchMU\evaluate($now);
$_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678::99';
check(\NCG\SearchMU\evaluate($now)['status'] === 429, 'IPv6 addresses share /64 bucket');
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_GET = ['s'=> ['injected']];
check(\NCG\SearchMU\evaluate($now)['status'] === 400, 'Array query rejected');
$_GET = ['s'=>str_repeat('x', 161)];
check(\NCG\SearchMU\evaluate($now)['status'] === 400, 'Oversize search rejected');
$_GET = ['s'=>'ok'];
$GLOBALS['mu_cache_enabled'] = false;
check(\NCG\SearchMU\evaluate($now)['status'] === 0, 'No persistent cache fails open');
$GLOBALS['mu_cache_enabled'] = true;
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.40';
check(\NCG\SearchMU\client_ip() === '203.0.113.8', 'Untrusted remote cannot spoof CF-Connecting-IP');
$_SERVER['REMOTE_ADDR'] = '173.245.48.10';
check(\NCG\SearchMU\client_ip() === '192.0.2.40', 'Verified Cloudflare proxy resolves visitor');
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'bad';
check(\NCG\SearchMU\client_ip() === null, 'Invalid CF visitor identity fails open');
unset($_SERVER['HTTP_CF_CONNECTING_IP']);
check(\NCG\SearchMU\client_ip() === null, 'Missing CF visitor identity fails open');
check(\NCG\SearchMU\in_cidr('2001:db8::1','2001:db8::/32'), 'IPv6 proxy CIDR matcher');
echo "All MU Search Guard checks passed.\n";
