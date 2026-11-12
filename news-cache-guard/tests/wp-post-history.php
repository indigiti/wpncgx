<?php
/**
 * Real WordPress + MariaDB end-to-end post history test.
 * Runs via wp-cli eval-file only against the ephemeral CI WordPress instance.
 */
if (!defined('ABSPATH') || !class_exists('\NCG\PostHistory')) {
    throw new RuntimeException('Post History plugin must be active.');
}
function ncg_history_assert(bool $ok, string $label): void {
    if (!$ok) WP_CLI::error($label);
    WP_CLI::log('PASS: ' . $label);
}
global $wpdb;
$table=\NCG\PostHistory::table();
ncg_history_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table,
    'Post History table created on activation');
$previous=\NCG\Editorial::settings();
$previous['master']=0;
update_option(\NCG\Editorial::OPTION,\NCG\Editorial::clean($previous),false);
$modules=\NCG\Modules::all();
$modules['post_history']=1;
update_option(\NCG\Modules::OPTION,\NCG\Modules::sanitize($modules),false);

$editorA=wp_create_user('ncg-history-editor-a',wp_generate_password(), 'history-editor-a@example.invalid');
$editorB=wp_create_user('ncg-history-editor-b',wp_generate_password(), 'history-editor-b@example.invalid');
$reporterA=wp_create_user('ncg-history-reporter-a',wp_generate_password(),'history-reporter-a@example.invalid');
$reporterB=wp_create_user('ncg-history-reporter-b',wp_generate_password(),'history-reporter-b@example.invalid');
foreach ([$editorA,$editorB,$reporterA,$reporterB] as $id) {
    ncg_history_assert(is_int($id) && $id>0,'Fixture WordPress users created');
}
(new \WP_User($editorA))->set_role('editor');
(new \WP_User($editorB))->set_role('editor');
(new \WP_User($reporterA))->set_role('author');
(new \WP_User($reporterB))->set_role('author');

wp_set_current_user($editorA);
$postId=wp_insert_post([
    'post_type'=>'post', 'post_status'=>'draft',
    'post_title'=>'NCG history attribution CI story',
    'post_content'=>'Reporter story from the field.',
    'post_author'=>$reporterA,
],true);
ncg_history_assert(is_int($postId) && $postId>0,'Editor creates a post credited to another user');
ncg_history_assert((int)get_post($postId)->post_author===$reporterA,'Public WordPress author remains reporter');
$created=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE post_id=%d AND event='created' ORDER BY id ASC LIMIT 1",$postId));
ncg_history_assert($created && (int)$created->actor_id===$editorA &&
    (int)$created->author_id===$reporterA,'Creator and credited reporter separated');

$published=wp_update_post(['ID'=>$postId,'post_status'=>'publish'],true);
ncg_history_assert(!is_wp_error($published) && $published===$postId,'Publish article');
$publication=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE post_id=%d AND event='published' ORDER BY id ASC LIMIT 1",$postId));
ncg_history_assert($publication && (int)$publication->actor_id===$editorA &&
    (int)$publication->author_id===$reporterA,'Publisher stored separately from public byline');

wp_set_current_user($editorB);
$changed=wp_update_post(['ID'=>$postId,'post_title'=>'NCG updated by second editor','post_author'=>$reporterB],true);
ncg_history_assert(!is_wp_error($changed),'Second editor updates article and reporter');
ncg_history_assert((int)get_post($postId)->post_author===$reporterB,'Theme-visible author is standard WordPress author');
$authorChange=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE post_id=%d AND event='author_changed' ORDER BY id DESC LIMIT 1",$postId));
ncg_history_assert($authorChange && (int)$authorChange->actor_id===$editorB &&
    (int)$authorChange->old_author_id===$reporterA &&
    (int)$authorChange->author_id===$reporterB,'Author change captures staff, old author and new author');
$edit=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE post_id=%d AND event='edited' ORDER BY id DESC LIMIT 1",$postId));
ncg_history_assert($edit && (int)$edit->actor_id===$editorB,'Last editor identified');

wp_set_current_user($editorB);
// Count only Post History SQL round-trips: author/meta lookups use normal
// WordPress caches and may legitimately vary across environments.
$history_queries = 0;
$history_query_filter = static function($sql) use (&$history_queries, $table) {
    if (strpos($sql, 'FROM ' . $table) !== false) ++$history_queries;
    return $sql;
};
add_filter('query', $history_query_filter, 999);
ob_start(); \NCG\PostHistory::render_meta_box(get_post($postId)); $html=ob_get_clean();
remove_filter('query', $history_query_filter, 999);
ncg_history_assert(strpos($html,'Public author')!==false &&
    strpos($html,'First published by')!==false &&
    strpos($html,'Created by')!==false,'Editor Post History panel renders real events');
ncg_history_assert($history_queries === 3,
    'Recent edits reuse loaded history rows instead of querying last editor again');
wp_set_current_user($reporterB);
ob_start(); \NCG\PostHistory::render_meta_box(get_post($postId)); $restricted=ob_get_clean();
ncg_history_assert($restricted==='', 'Ordinary author cannot view private operator details');

wp_set_current_user(0);
$system=wp_update_post(['ID'=>$postId,'post_title'=>'Automated title fix'],true);
ncg_history_assert(!is_wp_error($system),'Unauthenticated system update saved');
$systemEvent=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE post_id=%d AND event='edited' ORDER BY id DESC LIMIT 1",$postId));
ncg_history_assert($systemEvent && (int)$systemEvent->actor_id===0,'System updates never attributed to a staff member');

$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE post_id=%d",$postId));
$modules['post_history']=0;
update_option(\NCG\Modules::OPTION,\NCG\Modules::sanitize($modules),false);
wp_set_current_user($editorA);
wp_update_post(['ID'=>$postId,'post_title'=>'Disabled logging'],true);
$countAfter=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE post_id=%d",$postId));
ncg_history_assert($countAfter===$count, 'Independent module switch stops new events');
ncg_history_assert((int)get_post($postId)->post_author===$reporterB,
    'Disabling journal never alters public reporter');
WP_CLI::success('Post History WordPress integration tests passed.');
