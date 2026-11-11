<?php
/**
 * Real WordPress integration checks for Editorial & Publishing.
 * Run with: wp eval-file wp-content/plugins/news-cache-guard/tests/wp-integration.php
 */
if (!defined('ABSPATH')) exit;
use NCG\Editorial;
use NCG\EditorialWorkflow;

function ncg_assert($condition, $description) {
    if (!$condition) { fwrite(STDERR, "FAIL: " . $description . "\n"); exit(1); }
    echo "PASS: " . $description . "\n";
}
$admin = get_user_by('login', 'admin');
ncg_assert($admin && is_plugin_active('news-cache-guard/news-cache-guard.php'), 'WordPress and News Cache Guard active');

$editor_a = wp_create_user('ncg-editor-a', wp_generate_password(24), 'ea@example.invalid');
$editor_b = wp_create_user('ncg-editor-b', wp_generate_password(24), 'eb@example.invalid');
$author = wp_create_user('ncg-author', wp_generate_password(24), 'author@example.invalid');
$contributor = wp_create_user('ncg-contributor', wp_generate_password(24), 'contrib@example.invalid');
foreach ([$editor_a=>'editor',$editor_b=>'editor',$author=>'author',$contributor=>'contributor'] as $id=>$role) {
    (new WP_User($id))->set_role($role);
}
ncg_assert(Editorial::on('approvals') && Editorial::on('audit') && Editorial::on('lock'), 'Enforcement enabled for integration suite');

wp_set_current_user($author);
ncg_assert(!current_user_can('publish_posts'), 'Author loses direct publish button at runtime');
$id = wp_insert_post(['post_title'=>'Author draft approval','post_content'=>'Author copy',
    'post_author'=>$author,'post_type'=>'post','post_status'=>'publish'], true);
ncg_assert(!is_wp_error($id) && get_post_status($id) === 'pending', 'Author publish request stored only as pending');
global $wpdb;
$reviews = Editorial::table('reviews');
$req = $wpdb->get_row($wpdb->prepare("SELECT * FROM $reviews WHERE post_id=%d AND status='pending' ORDER BY id DESC LIMIT 1", $id));
ncg_assert($req !== null, 'Pending Author article has an approval record');
ncg_assert(!EditorialWorkflow::eligible($req, $author) && EditorialWorkflow::eligible($req, $editor_a),
    'Author cannot self approve; independent Editor can approve');

wp_set_current_user($editor_a);
$peer_id = wp_insert_post(['post_title'=>'Editor article','post_content'=>'Editor text',
    'post_author'=>$editor_a,'post_type'=>'post','post_status'=>'pending'], true);
ncg_assert(!is_wp_error($peer_id), 'Editor article submitted');
$peer = $wpdb->get_row($wpdb->prepare("SELECT * FROM $reviews WHERE post_id=%d AND status='pending' ORDER BY id DESC LIMIT 1", $peer_id));
ncg_assert($peer && $peer->policy === 'peer_editor', 'Editor article requires peer Editor');
ncg_assert(!EditorialWorkflow::eligible($peer, $editor_a) && EditorialWorkflow::eligible($peer, $editor_b),
    'Submitting Editor disqualified; other Editor eligible');

wp_set_current_user($admin->ID);
$published = wp_insert_post(['post_title'=>'Protected Published Article',
    'post_content'=>'Original words','post_type'=>'post','post_status'=>'publish',
    'post_author'=>$author], true);
ncg_assert(!is_wp_error($published) && get_post_status($published) === 'publish',
    'Administrator emergency publishing permitted');
$original = get_post_meta($published, Editorial::FIRST, true);
ncg_assert((bool)$original, 'Original publication timestamp captured');

wp_set_current_user($editor_a);
$before = get_post($published)->post_content;
ncg_assert(!current_user_can('edit_post', $published), 'Editor cannot directly edit published article');
ncg_assert(update_post_meta($published,'_yoast_wpseo_title','Injected SEO') === false,
    'Direct published SEO metadata write blocked');
ncg_assert(get_post_meta($published,'_yoast_wpseo_title',true) === '',
    'Blocked SEO metadata not persisted');
ncg_assert(update_post_meta($published, Editorial::FIRST, '2000-01-01 00:00:00') === false,
    'Editor cannot forge immutable first-publication timestamp');
ncg_assert(get_post_meta($published, Editorial::FIRST, true) === $original,
    'First publication timestamp unchanged');

$request = new WP_REST_Request('POST', '/wp/v2/posts/' . $published);
$request->set_param('title', 'REST injection');
$request->set_param('meta', ['_yoast_wpseo_title'=>'bad']);
$response = rest_do_request($request);
ncg_assert($response->get_status() === 403, 'REST update of published article rejected before save');
ncg_assert(get_the_title($published) === 'Protected Published Article', 'REST rejection preserves live content');

$new_request = new WP_REST_Request('POST', '/wp/v2/posts');
$new_request->set_param('title', 'REST direct publish');
$new_request->set_param('content', 'REST data');
$new_request->set_param('status', 'publish');
$new_response = rest_do_request($new_request);
ncg_assert($new_response->get_status() === 403, 'REST direct publish denied');

wp_set_current_user($admin->ID);
update_option(Editorial::OPTION, array_merge(Editorial::settings(), ['lock_hours'=>0]));
wp_set_current_user($editor_b);
ncg_assert(Editorial::locked($published), 'Zero-hour setting activates immediate lock');
ncg_assert(Editorial::map_meta_cap(['edit_posts'], 'delete_post', $editor_b, [$published]) === ['do_not_allow'],
    'Non-exempt Editor cannot delete locked article');
ncg_assert(wp_trash_post($published) === false, 'Trash API refuses locked article');

wp_set_current_user($admin->ID);
ncg_assert(Editorial::bypass($admin->ID), 'Administrator retains emergency lock bypass');
ncg_assert(Editorial::map_meta_cap(['edit_posts'],'edit_post',$admin->ID,[$published]) === ['edit_posts'],
    'Administrator keeps normal editing capability');

$future = wp_insert_post([
    'post_title'=>'Unapproved legacy schedule','post_content'=>'Should remain hidden',
    'post_type'=>'post','post_status'=>'future', 'post_author'=>$admin->ID,
    'post_date'=>gmdate('Y-m-d H:i:s', time()+86400),
    'post_date_gmt'=>gmdate('Y-m-d H:i:s', time()+86400),
], true);
ncg_assert(!is_wp_error($future) && get_post_status($future) === 'future', 'Admin can create scheduled article');
// Deliberately simulate an old/unapproved future event; remove the trusted token.
Editorial::$applying = true;
try { delete_post_meta($future, '_ncg_ed_schedule_authorized'); }
finally { Editorial::$applying = false; }
$wpdb->update($wpdb->posts,['post_date_gmt'=>gmdate('Y-m-d H:i:s',time()-60)],
    ['ID'=>$future],['%s'],['%d']);
clean_post_cache($future);
do_action('publish_future_post', $future);
ncg_assert(get_post_status($future) === 'future',
    'Unapproved scheduled publication blocked BEFORE core publish callback');

echo "WordPress editorial integration suite passed.\n";
