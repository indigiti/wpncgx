<?php
/**
 * Real WordPress integration for opt-in newsroom upload and category policies.
 * Requires wp eval-file and fresh WP-CLI bootstrap AFTER enabling switches.
 */
if (!defined('ABSPATH')) exit;

function nrg_wp_policy_check($ok, $label): void {
    if (!$ok) {WP_CLI::error('FAIL: '.$label);}
    WP_CLI::log('PASS: '.$label);
}
nrg_wp_policy_check(\NCG\Modules::on('media_uploads')
    && \NCG\Modules::on('category_creation')
    && \NCG\Modules::on('featured_alt'), 'Newsroom policies enabled in fresh runtime');

if (!function_exists('wp_handle_sideload')) require_once ABSPATH . 'wp-admin/includes/file.php';
$admin=get_user_by('login','admin');
$editor=get_user_by('login','ncg-editor-a');
nrg_wp_policy_check((bool)$admin && (bool)$editor,'Test roles available');
wp_set_current_user($admin->ID);
$jpeg_base64='/9j/4AAQSkZJRgABAQAAAAAAAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAAQABADAREAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAABwj/xAAZEAABBQAAAAAAAAAAAAAAAAAABgckMqH/xAAXAQEBAQEAAAAAAAAAAAAAAAAGCAQJ/8QAGhEAAgIDAAAAAAAAAAAAAAAAAAYFISIxMv/aAAwDAQACEQMRAD8AkxKNTSNg7kGDdmVOaechmSbU0jYBZBg3ZVac085DGk2ppGwDyDBuzmKnNPOQzJNqaRsA0gwbsqtOaecj/9k=';
$webp_base64='UklGRjoAAABXRUJQVlA4IC4AAADQAQCdASoCAAIAAgA0JaACdLoB+AADsAD+84d//unr2nr2nr+YP/4hqccfSwAA';
$png_base64='iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8f0KOgYGBiYGBgYGBAQAXxwHpKkRUiQAAAABJRU5ErkJggg==';

function nrg_wp_tmp($base64): string {
    $tmp=tempnam(sys_get_temp_dir(),'nrg-policy');
    file_put_contents($tmp,base64_decode($base64));
    return $tmp;
}
function nrg_wp_sideload($name,$base64): array {
    $tmp=nrg_wp_tmp($base64);
    $result=wp_handle_sideload(
        ['name'=>$name,'type'=>'application/octet-stream','tmp_name'=>$tmp,
         'size'=>filesize($tmp),'error'=>0],
        ['test_form'=>false]
    );
    if (file_exists($tmp)) @unlink($tmp);
    return $result;
}
$jpg=nrg_wp_sideload('newsroom-allowed.jpeg',$jpeg_base64);
nrg_wp_policy_check(!isset($jpg['error']) && ($jpg['type']??'')==='image/jpeg',
    'Real WordPress JPEG sideload accepted under strict policy');
$webp=nrg_wp_sideload('newsroom-allowed.webp',$webp_base64);
nrg_wp_policy_check(!isset($webp['error']) && ($webp['type']??'')==='image/webp',
    'Real WordPress WebP sideload accepted under strict policy');
$png=nrg_wp_sideload('newsroom-blocked.png',$png_base64);
nrg_wp_policy_check(!empty($png['error']) && strpos($png['error'],'Newsroom Guard:')!==false,
    'Real WordPress PNG sideload denied with clear policy message');
$spoof=nrg_wp_sideload('newsroom-fake.jpg',$png_base64);
nrg_wp_policy_check(!empty($spoof['error']),
    'Real WordPress rejects PNG disguised as JPEG');
$largeTmp=tempnam(sys_get_temp_dir(),'nrg-large');
file_put_contents($largeTmp,str_repeat('X',\NCG\MediaPolicy::MAX_UPLOAD_BYTES+1));
$large=wp_handle_sideload(
    ['name'=>'oversized.jpg','type'=>'image/jpeg','tmp_name'=>$largeTmp,
     'size'=>filesize($largeTmp),'error'=>0],['test_form'=>false]);
if (file_exists($largeTmp)) @unlink($largeTmp);
nrg_wp_policy_check(!empty($large['error']), 'Real WordPress upload blocks files over 500 KB');

wp_set_current_user($editor->ID);
$block=wp_insert_term('NCG Editor Cannot Create '.wp_generate_password(6,false),'category');
nrg_wp_policy_check(is_wp_error($block) && $block->get_error_code()==='nrg_category_creation_blocked',
    'Editor category creation prevented at the WordPress term-insert boundary');
$tag=wp_insert_term('NCG Editorial Tag '.wp_generate_password(6,false),'post_tag');
nrg_wp_policy_check(!is_wp_error($tag),'Unrelated post-tag insertion still functions');

wp_set_current_user($admin->ID);
$category=wp_insert_term('NCG Admin Allowed '.wp_generate_password(6,false),'category');
nrg_wp_policy_check(!is_wp_error($category),'Administrator category creation allowed');
$post_id=wp_insert_post(['post_title'=>'Newsroom Alt Image Headline','post_type'=>'post',
    'post_status'=>'draft','post_author'=>$admin->ID],true);
nrg_wp_policy_check(!is_wp_error($post_id),'Draft post created for featured image test');
$image=wp_insert_attachment(['post_title'=>'Newsroom test image','post_mime_type'=>'image/jpeg',
    'post_status'=>'inherit','post_author'=>$admin->ID], $jpg['file'], $post_id, true);
nrg_wp_policy_check(!is_wp_error($image),'Image attachment exists');
add_post_meta($post_id,'_thumbnail_id',$image,true);
nrg_wp_policy_check(get_post_meta($image,'_wp_attachment_image_alt',true)==='Newsroom Alt Image Headline',
    'Thumbnail metadata update fills empty alt from post title');
update_post_meta($image,'_wp_attachment_image_alt','Custom human alt text');
wp_update_post(['ID'=>$post_id,'post_title'=>'Changed Title']);
nrg_wp_policy_check(get_post_meta($image,'_wp_attachment_image_alt',true)==='Custom human alt text',
    'Post update never overwrites human alt metadata');

WP_CLI::success('Real WordPress upload, category and featured-image policies passed.');
