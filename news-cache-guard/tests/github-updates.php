<?php
/**
 * Standalone security/unit coverage for Newsroom Guard's GitHub release updater.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('NCG_VERSION', '1.2.0-rc4');
define('NCG_BASENAME', 'news-cache-guard/news-cache-guard.php');
define('MINUTE_IN_SECONDS',60);
define('HOUR_IN_SECONDS',3600);
$GLOBALS['nrg_sites']=[];
$GLOBALS['nrg_transients']=[];
$GLOBALS['nrg_requests']=0;
$GLOBALS['nrg_response']=[];
function get_site_option($name,$default=[]){return $GLOBALS['nrg_sites'][$name]??$default;}
function get_site_transient($name){return $GLOBALS['nrg_transients'][$name]??false;}
function set_site_transient($name,$value,$duration){$GLOBALS['nrg_transients'][$name]=$value;return true;}
function wp_remote_get($url,$params=[]){$GLOBALS['nrg_requests']++;return $GLOBALS['nrg_response'][$url]??['response'=>['code'=>404],'body'=>''];}
function wp_remote_retrieve_response_code($response){return $response['response']['code']??0;}
function wp_remote_retrieve_body($response){return $response['body']??'';}
function is_wp_error($x){return $x instanceof WP_Error;}
function esc_url($v){return $v;}
class WP_Error {
    private $code;
    public function __construct($code,$message){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function nrg_assert($assert,$label){
    if(!$assert){fwrite(STDERR,"FAIL: ".$label."\n");exit(1);}
    echo "PASS: ".$label."\n";
}
require_once __DIR__.'/../includes/class-ncg-github-updates.php';
use NCG\GitHubUpdates as Updater;

function gh_release(string $version,bool $prerelease=false,bool $draft=false): array {
    $tag='v'.$version;
    $prefix='https://github.com/indigiti/wpncgx/releases/download/'.$tag.'/';
    return ['tag_name'=>$tag,'prerelease'=>$prerelease,'draft'=>$draft,
      'published_at'=>'2026-11-12T08:00:00Z','body'=>'Security and feature maintenance',
      'assets'=>[
        ['name'=>'newsroom-guard-'.$version.'.zip','size'=>130000,
         'browser_download_url'=>$prefix.'newsroom-guard-'.$version.'.zip'],
        ['name'=>'SHA256SUMS.txt','size'=>95,
         'browser_download_url'=>$prefix.'SHA256SUMS.txt'],
      ]];
}
$releases=[
  gh_release('1.2.0-rc3',true),
  gh_release('1.2.1'),
  gh_release('1.3.0-rc1',true),
  gh_release('1.2.2'),
];
$stable=Updater::select_release($releases,'stable');
$testing=Updater::select_release($releases,'testing');
nrg_assert($stable['version']==='1.2.2', 'Stable channel selects highest stable version');
nrg_assert($testing['version']==='1.3.0-rc1', 'Testing channel includes prereleases');
nrg_assert(Updater::select_release([gh_release('1.2.0-rc3',true)],'stable')===null,
    'Stable channel excludes all release candidates');

$bad=gh_release('9.0.0');
$bad['assets'][0]['browser_download_url']='https://attacker.invalid/evil.zip';
nrg_assert(Updater::select_release([$bad],'testing')===null,'Rejects third-party download hosts');
$bad=gh_release('9.0.0');
$bad['assets'][1]['browser_download_url']='https://attacker.invalid/SHA256SUMS.txt';
nrg_assert(Updater::select_release([$bad],'testing')===null,'Rejects third-party checksum URLs');
$bad=gh_release('9.0.0');unset($bad['assets'][1]);
nrg_assert(Updater::select_release([$bad],'testing')===null,'Rejects missing checksums');
$bad=gh_release('9.0.0');$bad['assets'][0]['size']=20971521;
nrg_assert(Updater::select_release([$bad],'testing')===null,'Oversized plugin ZIP metadata fails validation');
nrg_assert(Updater::select_release([gh_release('9.0.0',false,true)],'testing')===null,'Draft releases excluded');
nrg_assert(Updater::select_release([gh_release('9.0.0')],'stable')['version']==='9.0.0',
    'Stable release with exact package is accepted');

$GLOBALS['nrg_response'][Updater::API]=['response'=>['code'=>200],'body'=>json_encode($releases)];
$release=Updater::release_status('stable');
nrg_assert($release['status']==='ok'&&$release['release']['version']==='1.2.2','Release API is parsed and cached');
$req=$GLOBALS['nrg_requests'];
Updater::release_status('stable');
nrg_assert($GLOBALS['nrg_requests']===$req,'Repeat release checks use cached metadata');
$GLOBALS['nrg_sites'][Updater::OPTION]=['channel'=>'testing'];
nrg_assert(Updater::channel()==='testing','Testing channel is an explicit stored selection');
$offer=Updater::offer_update(false,['UpdateURI'=>Updater::UPDATE_URI],NCG_BASENAME,[]);
nrg_assert(is_array($offer)&&$offer['version']==='1.3.0-rc1'
    && strpos($offer['package'],'/releases/download/v1.3.0-rc1/')!==false,
    'WordPress update receives only vetted newest release package');
$foreign=Updater::offer_update('unchanged',['UpdateURI'=>'https://github.com/elsewhere/repo'],
    'elsewhere/plugin.php',[]);
nrg_assert($foreign==='unchanged','Does not interfere with other GitHub plugins');
$GLOBALS['nrg_sites'][Updater::OPTION]=['channel'=>'stable'];
$stableOffer=Updater::offer_update(false,['UpdateURI'=>Updater::UPDATE_URI],NCG_BASENAME,[]);
nrg_assert(is_array($stableOffer)&&$stableOffer['version']==='1.2.2','Stable channel only offers stable releases');

$payload=Updater::plugin_information(false,'plugin_information',(object)['slug'=>'news-cache-guard']);
nrg_assert(is_object($payload) && $payload->version==='1.2.2',
    'Native WordPress plugin details are available');
nrg_assert(Updater::plugin_information('old','query_plugins',(object)['slug'=>'news-cache-guard'])==='old',
    'Other plugins API routes remain intact');

$GLOBALS['nrg_sites'][Updater::OPTION]=['channel'=>'testing'];
$selected=Updater::release_status('testing')['release'];
$meta=['type'=>'plugin','action'=>'update','plugin'=>NCG_BASENAME];
$err=Updater::verify_download(false,'https://evil.example/malware.zip',null,$meta);
nrg_assert($err instanceof WP_Error && $err->get_error_code()==='ncg_invalid_update',
    'Cannot install an attacker-controlled ZIP from untrusted URL');
nrg_assert(Updater::verify_download(false,'https://evil.example/malware.zip',null,
  ['type'=>'plugin','action'=>'update','plugin'=>'another/plugin.php'])===false,
    'Other plugin upgrade packages are never intercepted');

$fake=tempnam(sys_get_temp_dir(),'nrg-gh-test-');
file_put_contents($fake,'This is not a plugin ZIP');
$checksum=str_repeat('f',64).'  '.$selected['filename']."\n";
$GLOBALS['nrg_response'][$selected['checksum_url']]=['response'=>['code'=>200],'body'=>$checksum];
$err=Updater::verify_download($fake,$selected['package'],null,$meta);
nrg_assert($err instanceof WP_Error && $err->get_error_code()==='ncg_checksum_mismatch',
    'Corrupted release ZIP blocked before WordPress files can change');
@unlink($fake);

if(class_exists('ZipArchive')){
    $file=tempnam(sys_get_temp_dir(),'nrg-zip-');
    $zip=new ZipArchive();
    $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('wrong-plugin/plugin.php','<?php echo "wrong";');
    $zip->close();
    $err=Updater::check_zip_structure($file,$selected['version']);
    nrg_assert($err instanceof WP_Error && $err->get_error_code()==='ncg_zip_layout',
        'Incorrect plugin root is rejected before unzip');

    $zip=new ZipArchive();$zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->deleteName('wrong-plugin/plugin.php');
    $zip->addFromString('news-cache-guard/news-cache-guard.php',
        '<?php /** Plugin Name: Newsroom Guard Version: '.$selected['version'].
        ' Update URI: https://github.com/indigiti/wpncgx */');
    $zip->close();
    nrg_assert(Updater::check_zip_structure($file,$selected['version'])===true,
        'Valid plugin root, expected version and Update URI accepted');

    $validChecksum=hash_file('sha256',$file).'  '.$selected['filename']."\n";
    $GLOBALS['nrg_response'][$selected['checksum_url']]=['response'=>['code'=>200],'body'=>$validChecksum];
    nrg_assert(Updater::verify_download($file,$selected['package'],null,$meta)===$file,
        'SHA-256 verified release archive passes to native WordPress updater');

    $GLOBALS['nrg_response'][$selected['checksum_url']]=['response'=>['code'=>404],'body'=>''];
    $err=Updater::verify_download($file,$selected['package'],null,$meta);
    nrg_assert($err instanceof WP_Error && $err->get_error_code()==='ncg_missing_checksum',
        'Missing checksum manifest fails closed');
    @unlink($file);
}
echo "Newsroom Guard GitHub updater tests passed.\n";
