<?php
/** Standalone rc7 performance behavior tests; no WordPress install required. */
declare(strict_types=1);
define('ABSPATH', __DIR__);
$GLOBALS['perf_blog'] = 1;
$GLOBALS['perf_options'] = [1=>[],2=>[]];
$GLOBALS['perf_reads'] = [];
$GLOBALS['perf_hooks'] = [];
$GLOBALS['perf_actions'] = [];
function get_current_blog_id() { return $GLOBALS['perf_blog']; }
function get_option($name, $fallback = null) {
    $key=$GLOBALS['perf_blog'].':'.$name;
    $GLOBALS['perf_reads'][$key]=($GLOBALS['perf_reads'][$key]??0)+1;
    return $GLOBALS['perf_options'][$GLOBALS['perf_blog']][$name] ?? $fallback;
}
function add_action($hook,$callback,$priority=10,$args=1) { $GLOBALS['perf_hooks'][$hook][]=$callback; }
function do_action($hook,...$args) { $GLOBALS['perf_actions'][]=[$hook,...$args]; }
function fire_option_hook($hook,$key) { foreach(($GLOBALS['perf_hooks'][$hook]??[]) as $cb) $cb($key); }
function wp_is_post_revision($id) {return false;}
function wp_is_post_autosave($id) {return false;}
function get_permalink($id) {return 'https://example.invalid/news/'.$id.'/';}
function home_url($path='/') {return 'https://example.invalid'.$path;}
function wp_get_post_categories($id) {return [3,3];}
function get_category_link($id) {return 'https://example.invalid/category/'.$id.'/';}
function is_wp_error($value) {return false;}
function get_feed_link() {return 'https://example.invalid/feed/';}
function wp_using_ext_object_cache() {return false;}
function has_action($name) {return false;}
function is_multisite() {return false;}
function is_admin() {return false;}
function wp_next_scheduled($hook) { ++$GLOBALS['perf_schedule_reads']; return false; }
function wp_schedule_event(...$args) { ++$GLOBALS['perf_schedule_writes']; return true; }
class WP_Post {
    public int $ID;
    public string $post_status;
    public string $post_type='post';
    public function __construct(int $id,string $status){$this->ID=$id;$this->post_status=$status;}
}
function perf_check(bool $yes,string $name): void {
    if (!$yes) {fwrite(STDERR,"FAIL: $name\n");exit(1);}
    echo "PASS: $name\n";
}
require_once __DIR__.'/../includes/class-ncg-settings.php';
require_once __DIR__.'/../includes/class-ncg-modules.php';
require_once __DIR__.'/../includes/class-ncg-htaccess.php';
require_once __DIR__.'/../includes/class-ncg-counter.php';
require_once __DIR__.'/../includes/class-ncg-litespeed.php';
require_once __DIR__.'/../includes/class-ncg-cache-environment.php';
require_once __DIR__.'/../includes/class-ncg-purger.php';
require_once __DIR__.'/../includes/class-ncg-editorial.php';
require_once __DIR__.'/../includes/class-ncg-post-history.php';
\NCG\Settings::boot();
\NCG\Modules::boot();
\NCG\Settings::get('origin_threshold');
\NCG\Settings::get('origin_window');
\NCG\Settings::lines('drop_query_keys');
\NCG\Settings::lines('drop_query_keys');
perf_check(($GLOBALS['perf_reads']['1:ncg_settings']??0)===1,'Multiple setting reads require just one option read per site/request');
perf_check(\NCG\Htaccess::matches_drop_key('utm_campaign'),'Compiled query-key match works');
perf_check(!\NCG\Htaccess::matches_drop_key('preview_nonce'),'Protected cache keys remain uncollapsed');
$GLOBALS['perf_options'][1][\NCG\Settings::OPTION]=['origin_threshold'=>22,'drop_query_keys'=>"custom*\nutm*", 'protected_query_keys'=>"preview\npreview_nonce"];
fire_option_hook('updated_option',\NCG\Settings::OPTION);
perf_check(\NCG\Settings::get('origin_threshold')===22, 'Option update invalidates cached setting');
perf_check(\NCG\Htaccess::matches_drop_key('custom_source'),'Compiled rules refresh when setting changes');
perf_check(!\NCG\Htaccess::matches_drop_key('gclid'),'Old compiled rules removed after settings save');
$GLOBALS['perf_options'][1][\NCG\Modules::OPTION]=['bot'=>0];
perf_check(!\NCG\Modules::on('bot'), 'Module values read from WordPress option');
$GLOBALS['perf_options'][1][\NCG\Modules::OPTION]=['bot'=>1];
fire_option_hook('updated_option',\NCG\Modules::OPTION);
perf_check(\NCG\Modules::on('bot'), 'Module changes take effect within same PHP request');
$GLOBALS['perf_blog']=2;
$GLOBALS['perf_options'][2][\NCG\Settings::OPTION]=['origin_threshold'=>75,'drop_query_keys'=>'fbclid'];
$GLOBALS['perf_options'][2][\NCG\Modules::OPTION]=['bot'=>0];
perf_check(\NCG\Settings::get('origin_threshold')===75 && !\NCG\Modules::on('bot'),'Multisite configuration isolated by blog ID');
perf_check(\NCG\Htaccess::matches_drop_key('fbclid') && !\NCG\Htaccess::matches_drop_key('custom_source'),'Multisite query-key patterns isolated');
$GLOBALS['perf_blog']=1;
perf_check(\NCG\Settings::get('origin_threshold')===22 && \NCG\Modules::on('bot'),'Switching back restores original blog configuration');
$GLOBALS['perf_options'][1][\NCG\Settings::OPTION]=[];
fire_option_hook('updated_option',\NCG\Settings::OPTION);
$purger=new \NCG\Purger();
$purger->boot();
perf_check(isset($GLOBALS['perf_hooks']['wp_after_insert_post']) &&
    !isset($GLOBALS['perf_hooks']['save_post']) &&
    !isset($GLOBALS['perf_hooks']['transition_post_status']),
    'One post-save-complete hook replaces duplicate purge hooks');
$post=new WP_Post(123,'publish');
$purger->on_after_insert(123,$post,false,null);
$urls=array_values(array_map(static fn($a)=>$a[1],array_filter(
    $GLOBALS['perf_actions'],static fn($a)=>$a[0]==='litespeed_purge_url')));
perf_check(count($urls)===count(array_unique($urls)),'Each cache URL purged at most once per save');
perf_check(in_array('https://example.invalid/news/123/',$urls,true) &&
    in_array('https://example.invalid/category/3/',$urls,true),
    'Article URL and category invalidation preserved');
$GLOBALS['perf_actions']=[];
$purger->on_after_insert(123,new WP_Post(123,'draft'),true,new WP_Post(123,'draft'));
perf_check(count($GLOBALS['perf_actions'])===0, 'Draft-only edits do not purge');
$purger->on_after_insert(123,new WP_Post(123,'draft'),true,new WP_Post(123,'publish'));
perf_check(count(array_filter($GLOBALS['perf_actions'],static fn($a)=>$a[0]==='litespeed_purge_url'))>0,
    'Unpublishing still invalidates cached article');
$GLOBALS['perf_actions']=[];
$purger->on_after_insert(123,new WP_Post(123,'publish'),true,new WP_Post(123,'future'));
perf_check(count(array_filter($GLOBALS['perf_actions'],static fn($a)=>$a[0]==='litespeed_purge_url'))>0,
    'Scheduled article publication still invalidates');
$GLOBALS['perf_options'][1]['active_plugins']=['breeze/breeze.php'];
$signals=\NCG\CacheEnvironment::signals();
perf_check($signals['breeze']==='Breeze is listed as active','Breeze detection read-only and correctly labeled');
perf_check(strpos($signals['varnish'],'Unverified')===0 &&
    strpos($signals['edge'],'Unverified')===0,'PHP hints do not assert Varnish/Cloudflare cache availability');
$GLOBALS['perf_schedule_reads']=0;
$GLOBALS['perf_schedule_writes']=0;
$GLOBALS['perf_options'][1][\NCG\Editorial::OPTION]=['master'=>0,'audit'=>1];
\NCG\Editorial::boot();
perf_check($GLOBALS['perf_schedule_reads']===0 && $GLOBALS['perf_schedule_writes']===0,
    'Disabled editorial master does not query or schedule maintenance on public requests');
$GLOBALS['perf_options'][1]['ncg_post_history_db_version']='1';
\NCG\PostHistory::boot();
perf_check(!isset($GLOBALS['perf_hooks']['init']),
    'Already installed Post History does not register migration checks on every request');
echo "All rc7 performance behavior checks passed.\n";
