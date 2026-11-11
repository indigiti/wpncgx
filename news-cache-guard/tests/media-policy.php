<?php
/**
 * Standalone media-policy logic and safety tests. No WordPress installation needed.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$GLOBALS['nrg_test_opts'] = [];
$GLOBALS['nrg_test_hooks'] = [];
$GLOBALS['nrg_test_meta'] = [];
$GLOBALS['nrg_test_user_admin'] = false;
$GLOBALS['nrg_test_editable'] = [11 => true, 21 => true];
function get_option($key, $fallback = null) {return $GLOBALS['nrg_test_opts'][$key] ?? $fallback;}
function add_filter($hook, $callback, $priority = 10, $args = 1) {$GLOBALS['nrg_test_hooks'][] = $hook;}
function add_action($hook, $callback, $priority = 10, $args = 1) {$GLOBALS['nrg_test_hooks'][] = $hook;}
function wp_check_filetype_and_ext($path, $name, $types = []) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        $ok = substr((string)file_get_contents($path),0,5)==='%PDF-' && isset($types['pdf']);
        return ['ext'=>$ok ? 'pdf' : false,'type'=>$ok ? 'application/pdf' : false];
    }
    $img = @getimagesize($path);
    if (!$img) return ['ext'=>false,'type'=>false];
    $expected = in_array($ext,['jpg','jpeg'],true) ? 'image/jpeg' :
        ($ext==='webp' ? 'image/webp' : ($ext==='png' ? 'image/png' : ($ext==='gif' ? 'image/gif' : '')));
    $allowed = false;
    foreach ($types as $key=>$mime) if ($mime === $expected) $allowed = true;
    $ok = $allowed && $img['mime']===$expected;
    return ['ext'=>$ok ? $ext : false,'type'=>$ok ? $expected : false];
}
class WP_Error {
    private $code;
    public function __construct($code,$message) {$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function current_user_can($cap,$id=null) {
    if ($cap==='manage_options') return $GLOBALS['nrg_test_user_admin'];
    if ($cap==='edit_post') return $GLOBALS['nrg_test_editable'][$id] ?? false;
    return false;
}
function get_post($id){return $GLOBALS['nrg_test_posts'][$id] ?? null;}
function wp_is_post_revision($id){return false;}
function wp_is_post_autosave($id){return false;}
function get_post_thumbnail_id($id){return (int)($GLOBALS['nrg_test_meta'][$id]['_thumbnail_id']??0);}
function wp_attachment_is_image($id){return $id === 21;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['nrg_test_meta'][$id][$key]??'';}
function update_post_meta($id,$key,$value){$GLOBALS['nrg_test_meta'][$id][$key]=$value;return true;}
function get_the_title($id){return $GLOBALS['nrg_test_posts'][$id]->post_title??'';}
function wp_strip_all_tags($s){return strip_tags($s);}
function nrg_expect($result,$label){if(!$result){fwrite(STDERR,"FAIL: ".$label."\n");exit(1);}echo "PASS: ".$label."\n";}

require_once __DIR__ . '/../includes/class-ncg-modules.php';
require_once __DIR__ . '/../includes/class-ncg-media-policy.php';

use NCG\Modules;
use NCG\MediaPolicy;

MediaPolicy::boot();
nrg_expect(!$GLOBALS['nrg_test_hooks'], 'Policies default OFF and do not register enforcement hooks');
$opts = Modules::defaults();
$opts['media_uploads']=1;
$opts['category_creation']=1;
$opts['featured_alt']=1;
$GLOBALS['nrg_test_opts'][Modules::OPTION]=$opts;
MediaPolicy::boot();
nrg_expect(in_array('wp_handle_upload_prefilter',$GLOBALS['nrg_test_hooks'],true), 'Browser uploader is protected');
nrg_expect(in_array('wp_handle_sideload_prefilter',$GLOBALS['nrg_test_hooks'],true), 'WordPress sideload path is protected');
nrg_expect(in_array('pre_insert_term',$GLOBALS['nrg_test_hooks'],true), 'Category creation is blocked before insert');
nrg_expect(!in_array('gettext',$GLOBALS['nrg_test_hooks'],true), 'Unrelated WordPress errors are not overwritten');

$mime=MediaPolicy::allowed_mimes(['png'=>'image/png','pdf'=>'application/pdf']);
nrg_expect(count($mime)===2 && isset($mime['jpg|jpeg'],$mime['webp'])
    && !isset($mime['pdf']), 'Strict opt-in MIME policy blocks PDFs and PNG sources');

function nrg_fixture(string $base64): string {
    $file = tempnam(sys_get_temp_dir(),'nrg-');
    file_put_contents($file,base64_decode($base64));
    return $file;
}
$jpeg=nrg_fixture('/9j/4AAQSkZJRgABAQAAAAAAAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAAQABADAREAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAABwj/xAAZEAABBQAAAAAAAAAAAAAAAAAABgckMqH/xAAXAQEBAQEAAAAAAAAAAAAAAAAGCAQJ/8QAGhEAAgIDAAAAAAAAAAAAAAAAAAYFISIxMv/aAAwDAQACEQMRAD8AkxKNTSNg7kGDdmVOaechmSbU0jYBZBg3ZVac085DGk2ppGwDyDBuzmKnNPOQzJNqaRsA0gwbsqtOaecj/9k=');
$webp=nrg_fixture('UklGRjoAAABXRUJQVlA4IC4AAADQAQCdASoCAAIAAgA0JaACdLoB+AADsAD+84d//unr2nr2nr+YP/4hqccfSwAA');
$png=nrg_fixture('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8f0KOgYGBiYGBgYGBAQAXxwHpKkRUiQAAAABJRU5ErkJggg==');
foreach([[$jpeg,'test.jpeg'],[$webp,'test.webp'],[$jpeg,'image.jpg']] as [$path,$name]){
    $f=MediaPolicy::check_upload(['name'=>$name,'tmp_name'=>$path,'size'=>filesize($path)]);
    nrg_expect(empty($f['error']), 'Valid real image accepted: '.$name);
}
foreach([[$png,'fake.jpg'],[$jpeg,'fake.webp'],[$png,'file.png'],[$jpeg,'file.pdf']] as [$path,$name]){
    $f=MediaPolicy::check_upload(['name'=>$name,'tmp_name'=>$path,'size'=>filesize($path)]);
    nrg_expect(!empty($f['error']), 'Content/extension mismatch rejected: '.$name);
}
$oversize=nrg_fixture(base64_encode(str_repeat('A',MediaPolicy::MAX_UPLOAD_BYTES+1)));
$f=MediaPolicy::check_upload(['name'=>'large.jpg','tmp_name'=>$oversize,'size'=>1]);
nrg_expect(!empty($f['error']),'Actual file size above 500 KB is rejected even with forged client size');
$f=MediaPolicy::check_upload(['name'=>'test.jpg','tmp_name'=>$jpeg,'error'=>'Earlier upload error']);
nrg_expect($f['error']==='Earlier upload error','Existing server-side upload error is preserved');

$blocked=MediaPolicy::block_category_creation('Breaking News','category');
nrg_expect($blocked instanceof WP_Error && $blocked->get_error_code()==='nrg_category_creation_blocked',
    'Nonadministrator category creation rejected without modifying roles');
nrg_expect(MediaPolicy::block_category_creation('A new tag','post_tag') === 'A new tag',
    'Tag insertion unaffected by category rule');
$GLOBALS['nrg_test_user_admin']=true;
nrg_expect(MediaPolicy::block_category_creation('Breaking News','category')==='Breaking News',
    'Administrator may create categories');
$GLOBALS['nrg_test_user_admin']=false;

$GLOBALS['nrg_test_posts'][11]=(object)['ID'=>11,'post_type'=>'post','post_title'=>'<b>Original News Headline</b>'];
$GLOBALS['nrg_test_meta'][11]['_thumbnail_id']=21;
MediaPolicy::ensure_featured_alt(11);
nrg_expect(get_post_meta(21,'_wp_attachment_image_alt')==='Original News Headline',
    'Empty attachment alt is populated from sanitized post headline');
$GLOBALS['nrg_test_posts'][11]->post_title='Updated News Headline';
MediaPolicy::ensure_featured_alt(11);
nrg_expect(get_post_meta(21,'_wp_attachment_image_alt')==='Original News Headline',
    'Existing alt text is never overwritten by a later title');
$GLOBALS['nrg_test_meta'][21]['_wp_attachment_image_alt']='';
$GLOBALS['nrg_test_editable'][21]=false;
MediaPolicy::ensure_featured_alt(11);
nrg_expect(get_post_meta(21,'_wp_attachment_image_alt')==='',
    'Cannot edit another author’s attachment metadata without permission');
$GLOBALS['nrg_test_editable'][21]=true;
$GLOBALS['nrg_test_opts'][Modules::OPTION]['featured_alt']=0;
MediaPolicy::ensure_featured_alt(11);
nrg_expect(get_post_meta(21,'_wp_attachment_image_alt')==='',
    'Featured alt automation stops immediately when switched OFF');


nrg_expect(MediaPolicy::settings()===['max_kb'=>500,'types'=>['jpeg','webp']],
    'Existing 500 KB JPEG/WebP policy is preserved as the upgrade default');
nrg_expect(MediaPolicy::clean(['max_kb'=>30000,'types'=>['pdf','png','bad']]) ===
    ['max_kb'=>20480,'types'=>['png','pdf']], 'Configured upload size is capped and unsupported MIME types rejected');
nrg_expect(MediaPolicy::clean(['max_kb'=>0,'types'=>[]]) ===
    ['max_kb'=>1,'types'=>['jpeg','webp']], 'Malformed stored settings fail closed to a safe allowlist');

$GLOBALS['nrg_test_opts'][MediaPolicy::OPTION] = ['max_kb'=>4000,'types'=>['jpeg','webp','png','pdf']];
$config = MediaPolicy::settings();
nrg_expect($config['max_kb']===4000 && in_array('pdf',$config['types'],true),
    'Administrators can set a 4 MB policy and explicitly enable PDFs');
$mime=MediaPolicy::allowed_mimes([]);
nrg_expect(isset($mime['png'],$mime['pdf']) && !isset($mime['gif']),
    'Dynamic upload MIME allowlist enforces selected formats only');
$n = MediaPolicy::check_upload(['name'=>'newspage.png','tmp_name'=>$png,'size'=>1]);
nrg_expect(empty($n['error']), 'PNG accepted after explicitly enabling it');
$pdf=nrg_fixture(base64_encode("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"));
$n=MediaPolicy::check_upload(['name'=>'edition.pdf','tmp_name'=>$pdf,'size'=>1]);
nrg_expect(empty($n['error']), 'PDF accepted with valid header and MIME when explicitly allowed');
$n=MediaPolicy::check_upload(['name'=>'fake-edition.pdf','tmp_name'=>$jpeg,'size'=>1]);
nrg_expect(!empty($n['error']), 'Fake PDF rejected despite permitted filename extension');
$n=MediaPolicy::check_upload(['name'=>'motion.gif','tmp_name'=>$png,'size'=>1]);
nrg_expect(!empty($n['error']), 'Non-selected GIF format remains blocked');
nrg_expect(strpos(MediaPolicy::error_message(),'4,000 KB')!==false &&
    strpos(MediaPolicy::error_message(),'PDF')!==false, 'Media errors reflect current administrator configuration');
$GLOBALS['nrg_test_opts'][MediaPolicy::OPTION]=['max_kb'=>1,'types'=>['jpeg']];
$n=MediaPolicy::check_upload(['name'=>'edition.pdf','tmp_name'=>$pdf,'size'=>1]);
nrg_expect(!empty($n['error']), 'Turning off PDF explicitly rejects it without losing image rules');
@unlink($pdf);

foreach([$jpeg,$webp,$png,$oversize] as $path) @unlink($path);
echo "All Media Policy tests passed.\n";
