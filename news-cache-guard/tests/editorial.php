<?php
/**
 * Standalone editorial settings and permission checks.
 * These do not replace WordPress REST, cron and browser integration tests.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['ncg_ed_opts'] = [];
function get_option($key, $default = null) { return $GLOBALS['ncg_ed_opts'][$key] ?? $default; }
function absint($n) { return abs((int)$n); }
function user_can($id, $cap) { return $id === 1 && $cap === 'manage_options'; }
function get_userdata($id) { return (object)['roles'=> $id === 2 ? ['editor'] : ['author']]; }
function get_post($id) { return $GLOBALS['ncg_ed_post'] ?? null; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['ncg_ed_meta'][$key] ?? ''; }
function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function add_action(...$args) {}
function add_filter(...$args) {}
function wp_next_scheduled($hook) { return true; }
function is_admin() { return false; }
class NCG_IpStub { public static function client_ip() { return null; } }
require_once __DIR__ . '/../includes/class-ncg-editorial.php';
function verify(bool $condition, string $name): void {
    if (!$condition) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
}
use NCG\Editorial;
verify(!Editorial::on('approvals'), 'Master off by default');
verify(Editorial::clean([])['master'] === 0, 'Missing toggle disables master');
verify(Editorial::clean(['master'=>1,'approvals'=>1])['approvals'] === 1, 'Approvals toggle sanitizes');
verify(Editorial::clean(['lock_hours'=>9999])['lock_hours'] === 720, 'Lock duration bounded');
verify(Editorial::clean(['bypass_ids'=>'529, 529, -2, 4, hello'])['bypass_ids'] === '529,4', 'Bypass IDs sanitized');
$GLOBALS['ncg_ed_opts'][Editorial::OPTION] = ['master'=>1,'audit'=>1,'security'=>0,'approvals'=>1,'lock'=>1,'bypass_ids'=>'529','lock_hours'=>4];
verify(Editorial::on('approvals') && !Editorial::on('security'), 'Independent feature toggles');
verify(Editorial::bypass(529), 'Explicit exempt user');
verify(Editorial::bypass(1), 'Administrator exemption');
verify(!Editorial::bypass(2), 'Editor not exempt');
$GLOBALS['ncg_ed_post'] = (object)['ID'=>99,'post_type'=>'post','post_status'=>'publish','post_date_gmt'=>gmdate('Y-m-d H:i:s',time()-18000)];
verify(Editorial::locked(99), 'Published post locked after four hours');
verify(Editorial::map_meta_cap(['edit_posts'],'edit_post',2,[99]) === ['do_not_allow'], 'Editor cannot edit published post');
verify(Editorial::map_meta_cap(['edit_posts'],'edit_post',1,[99]) === ['edit_posts'], 'Admin retains capability');
$GLOBALS['ncg_ed_opts'][Editorial::OPTION]['master'] = 0;
verify(!Editorial::locked(99), 'Master off disables lock without affecting posts');
require_once __DIR__ . '/../includes/class-ncg-editorial-workflow.php';
$GLOBALS['ncg_ed_opts'][Editorial::OPTION]['master'] = 1;
$GLOBALS['ncg_ed_opts'][Editorial::OPTION]['approvals'] = 1;
$actor = (object)['ID'=>2];
$caps = \NCG\EditorialWorkflow::publish_cap(['publish_posts'=>true,'manage_options'=>false], [], [], $actor);
verify($caps['publish_posts'] === false, 'Editor cannot directly publish while approval module is on');
$admin_caps = \NCG\EditorialWorkflow::publish_cap(['publish_posts'=>true,'manage_options'=>true], [], [], (object)['ID'=>1]);
verify($admin_caps['publish_posts'] === true, 'Administrator emergency publish cap preserved');
$GLOBALS['ncg_ed_opts'][Editorial::OPTION]['master'] = 1;
$GLOBALS['ncg_ed_opts'][Editorial::OPTION]['approvals'] = 1;
$allowed_caps = \NCG\EditorialWorkflow::publish_cap(['publish_posts'=>true,'manage_options'=>false], [], [], $actor);
verify($allowed_caps['publish_posts'] === false, 'Approvals hide Publish button for Editors');
verify(Editorial::map_meta_cap(['edit_posts'], 'edit_post', 529, [99]) === ['do_not_allow'],
    'Allowlisted lock user does not bypass independent approval');
echo "All editorial unit checks passed.\n";
