<?php
/**
 * Real WordPress/MariaDB rc7 integration: request-local options and
 * once-per-save purge while keeping scheduled/manual publishing intact.
 * Run via WP-CLI eval-file on an isolated database.
 */
if (!defined('ABSPATH') || !class_exists('\NCG\Purger')) {
    WP_CLI::error('The Newsroom Guard plugin is not active.');
}
function ncg_perf_assert($ok, $label) {
    if (!$ok) WP_CLI::error($label);
    WP_CLI::log('PASS: '.$label);
}
wp_set_current_user(get_user_by('login','admin')->ID);
$original=\NCG\Settings::get_all();
$change=$original;
$change['origin_threshold']=37;
update_option(\NCG\Settings::OPTION,\NCG\Settings::sanitize($change),false);
ncg_perf_assert(\NCG\Settings::get('origin_threshold')===37,'Updated settings are visible within one PHP request');
update_option(\NCG\Settings::OPTION,\NCG\Settings::sanitize($original),false);
ncg_perf_assert(\NCG\Settings::get('origin_threshold')===$original['origin_threshold'],
    'Original WordPress settings restored without stale request cache');

$initialModules=\NCG\Modules::all();
$modifiedModules=$initialModules;
$modifiedModules['worker']=0;
update_option(\NCG\Modules::OPTION,\NCG\Modules::sanitize($modifiedModules),false);
ncg_perf_assert(!\NCG\Modules::on('worker'),'Module option change takes effect without a second request');
update_option(\NCG\Modules::OPTION,\NCG\Modules::sanitize($initialModules),false);
ncg_perf_assert(\NCG\Modules::on('worker')===(bool)$initialModules['worker'],
    'Module switch restored without stale value');
$urls=[];
add_action('litespeed_purge_url', static function($url) use (&$urls) {
    $urls[]=$url;
}, 999, 1);
$postId=wp_insert_post(['post_type'=>'post','post_status'=>'draft',
    'post_author'=>get_current_user_id(),'post_title'=>'NCG rc7 cache invalidation test',
    'post_content'=>'Draft test story.'],true);
ncg_perf_assert(!is_wp_error($postId)&&$postId>0, 'Published-article fixture created as draft');
ncg_perf_assert(count($urls)===0,'Draft creation does not purge public cache');
$publish=wp_update_post(['ID'=>$postId,'post_status'=>'publish'],true);
ncg_perf_assert(!is_wp_error($publish) && $publish===$postId, 'Publication succeeds');
ncg_perf_assert(count($urls)>0 && count($urls)===count(array_unique($urls)),
    'One publication causes no duplicate Newsroom Guard URL purges');
ncg_perf_assert(in_array(get_permalink($postId),$urls,true),'Article URL is invalidated');
$urls=[];
$edit=wp_update_post(['ID'=>$postId,'post_title'=>'NCG rc7 corrected story'],true);
ncg_perf_assert(!is_wp_error($edit) && count($urls)>0 &&
    count($urls)===count(array_unique($urls)), 'Published correction invalidates each URL once');
$urls=[];
$unpublish=wp_update_post(['ID'=>$postId,'post_status'=>'draft'],true);
ncg_perf_assert(!is_wp_error($unpublish) && count($urls)>0,
    'Unpublishing invalidates old public article');
$urls=[];
wp_update_post(['ID'=>$postId,'post_title'=>'Draft headline revised'],true);
ncg_perf_assert(count($urls)===0,'Draft edits do not cause purge');
$urls=[];
wp_publish_post($postId);
ncg_perf_assert(get_post_status($postId)==='publish' && count($urls)>0 &&
    count($urls)===count(array_unique($urls)), 'Direct wp_publish_post path still purges exactly once');
remove_all_actions('litespeed_purge_url');
WP_CLI::success('Newsroom Guard rc7 WordPress performance regression passed.');
