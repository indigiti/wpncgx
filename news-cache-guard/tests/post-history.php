<?php
/**
 * Independent post history regression tests, no WordPress install required.
 * A full WordPress/Cloudways test is still required before production.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__);
$GLOBALS['opts']=[];
$GLOBALS['events']=[];
$GLOBALS['hooks']=[];
function get_option($name,$fallback=null){return $GLOBALS['opts'][$name]??$fallback;}
function update_option($k,$v,$autoload=false){$GLOBALS['opts'][$k]=$v;return true;}
function add_action($n,$cb,$priority=10,$args=1){$GLOBALS['hooks'][$n][]=$cb;}
function is_admin(){return true;}
function current_time($format,$gmt=false){return '2026-11-12 00:00:00';}
function get_current_user_id(){return $GLOBALS['actor']??2;}
function get_userdata($id){return (object)['display_name'=>'Editor Two'];}
function wp_json_encode($a){return json_encode($a);}
function wp_is_post_revision($id){return false;}
function wp_is_post_autosave($id){return false;}
function wp_doing_cron(){return false;}
function get_post_status($id){return 'publish';}
function current_user_can($cap,...$a){return true;}
function get_the_author_meta($field,$id){return 'User #'.$id;}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);}
class HistoryDb {
    public $prefix='wp_';
    public $insert_id=0;
    public function insert($table,$data,$format=[]){$GLOBALS['events'][]=$data;return 1;}
}
$GLOBALS['wpdb']=new HistoryDb();
require_once __DIR__.'/../includes/class-ncg-modules.php';
require_once __DIR__.'/../includes/class-ncg-post-history.php';
function check($bool,$message){if(!$bool){fwrite(STDERR,"FAIL: $message\n");exit(1);}echo "PASS: $message\n";}
use NCG\Modules;
use NCG\PostHistory;
$GLOBALS['opts']['ncg_post_history_db_version']='1';
check(Modules::on('post_history'), 'Passive post history is independent and enabled by default');
check(!Modules::on('missing_module'), 'Missing module is never enabled');
PostHistory::boot();
check(isset($GLOBALS['hooks']['transition_post_status']), 'Status changes tracked');
check(isset($GLOBALS['hooks']['post_updated']), 'Article edits tracked');
check(isset($GLOBALS['hooks']['wp_after_insert_post']), 'Article creation tracked');
$post=(object)['ID'=>100,'post_type'=>'post','post_status'=>'draft','post_author'=>31,'post_title'=>'Example','post_content'=>'A','post_excerpt'=>'','post_name'=>'test'];
PostHistory::inserted(100,$post,false,null);
check(count($GLOBALS['events'])===1 && $GLOBALS['events'][0]['event']==='created','Post creator captured');
check($GLOBALS['events'][0]['actor_id']===2 && $GLOBALS['events'][0]['author_id']===31,'Operator separate from public author');
$old=clone $post;$post->post_author=32;
PostHistory::updated(100,$post,$old);
check(count($GLOBALS['events'])===2 && $GLOBALS['events'][1]['event']==='author_changed','Byline change captured');
check($GLOBALS['events'][1]['old_author_id']===31 && $GLOBALS['events'][1]['author_id']===32,'Previous and new author retained');
$post->post_status='publish';
PostHistory::transition('publish','draft',$post);
check(count($GLOBALS['events'])===3 && $GLOBALS['events'][2]['event']==='published','Publishing operator captured');
check($GLOBALS['events'][2]['actor_id']===2,'Publishing actor is not public author');
PostHistory::transition('publish','publish',$post);
check(count($GLOBALS['events'])===3,'Unchanged status does not duplicate publish');
$auto=clone $post;$auto->post_status='auto-draft';
PostHistory::inserted(100,$auto,false,null);
check(count($GLOBALS['events'])===3,'Blank auto-draft not treated as article');
$other=clone $post;$other->post_type='page';
PostHistory::inserted(100,$other,false,null);
check(count($GLOBALS['events'])===3,'Pages excluded');
$GLOBALS['opts'][Modules::OPTION]=['post_history'=>0];
// The standalone mock mutates an array directly; real WordPress option updates
// invoke updated_option and invalidate the request-local module cache.
Modules::invalidate();
PostHistory::inserted(100,$post,false,null);
check(count($GLOBALS['events'])===3,'Module switch stops tracking');
check(Modules::sanitize(['post_history'=>0])['post_history']===0, 'Module setting can be disabled');
echo "Post History unit regression complete.\n";
