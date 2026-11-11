<?php
/** Real WordPress integration: GitHub Update URI, filter, WP dashboard and options. */
if (!defined('ABSPATH')) exit;
function nrg_wp_gh_assert($ok, $message) {
    if (!$ok) WP_CLI::error('FAIL: '.$message);
    WP_CLI::log('PASS: '.$message);
}
if (!function_exists('get_plugin_data')) require_once ABSPATH.'wp-admin/includes/plugin.php';
$admin=get_user_by('login','admin');
wp_set_current_user($admin->ID);
$data=get_plugin_data(WP_PLUGIN_DIR.'/'.NCG_BASENAME,false,false);
nrg_wp_gh_assert(($data['UpdateURI'] ?? '')===\NCG\GitHubUpdates::UPDATE_URI,
    'WordPress sees the official GitHub Update URI');
nrg_wp_gh_assert(has_filter('update_plugins_github.com',[\NCG\GitHubUpdates::class,'offer_update'])!==false,
    'The native GitHub host update hook is registered');

$version=((int)explode('.', NCG_VERSION)[0] + 1).'.0.0-rc1';
$base='https://github.com/indigiti/wpncgx/releases/download/v'.$version.'/';
$items=[[
    'tag_name'=>'v'.$version,
    'prerelease'=>true,'draft'=>false,
    'assets'=>[
        ['name'=>'newsroom-guard-'.$version.'.zip','size'=>100000,'browser_download_url'=>$base.'newsroom-guard-'.$version.'.zip'],
        ['name'=>'SHA256SUMS.txt','size'=>95,'browser_download_url'=>$base.'SHA256SUMS.txt'],
    ],
]];
$candidate=\NCG\GitHubUpdates::select_release($items,'testing');
nrg_wp_gh_assert($candidate && $candidate['version']===$version,
    'Release candidate parsed in actual WordPress');
update_site_option(\NCG\GitHubUpdates::OPTION,['channel'=>'testing']);
set_site_transient('ncg_gh_release_test',
    ['status'=>'ok','release'=>$candidate,'checked'=>time(),'message'=>''],600);
$r=apply_filters('update_plugins_github.com',false,$data,NCG_BASENAME,[]);
nrg_wp_gh_assert(is_array($r) && $r['version']===$version &&
    $r['package']===$base.'newsroom-guard-'.$version.'.zip',
    'Native WordPress updater receives correct release');
nrg_wp_gh_assert(apply_filters('update_plugins_github.com','other',
    ['UpdateURI'=>'https://github.com/someone/else'],'other/plugin.php',[])==='other',
    'Other GitHub plugins are unaffected');
$_GET=['page'=>'newsroom-guard','tab'=>'settings','panel'=>'updates'];
ob_start();\NCG\Dashboard::render();$html=ob_get_clean();
foreach(['GitHub Releases','v'.$version,'Release channel',
         'Check GitHub releases now','Stable','Testing'] as $needle) {
    nrg_wp_gh_assert(strpos($html,$needle)!==false,'Admin updater view contains '.$needle);
}
update_site_option(\NCG\GitHubUpdates::OPTION,['channel'=>'stable']);
$r=apply_filters('update_plugins_github.com',false,$data,NCG_BASENAME,[]);
nrg_wp_gh_assert($r===false,'Stable channel refuses release-candidate update');
delete_site_transient('ncg_gh_release_test');
delete_site_transient('ncg_gh_release_stable');
WP_CLI::success('WordPress native GitHub update integration passed.');
