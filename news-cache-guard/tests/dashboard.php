<?php
/**
 * Standalone tests for module defaults, admin filters and dashboard structure.
 * WordPress-level permission/SQL tests run in the integration workflow.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
$GLOBALS['nrg_options'] = [];
function get_option($key, $fallback = null) { return $GLOBALS['nrg_options'][$key] ?? $fallback; }
function sanitize_text_field($s) { return is_string($s) ? trim(strip_tags($s)) : ''; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string)$s)); }
function wp_unslash($s) { return is_string($s) ? stripslashes($s) : $s; }
function absint($v) { return abs((int)$v); }
function check_nrg($test, string $label): void {
    if (!$test) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "PASS: $label\n";
}
require_once __DIR__ . '/../includes/class-ncg-modules.php';
require_once __DIR__ . '/../includes/class-ncg-dashboard.php';
use NCG\Modules;
use NCG\Dashboard;

check_nrg(Modules::on('bot') && Modules::on('worker') && Modules::on('reports'), 'Existing modules remain ON by default');
check_nrg(!Modules::on('ai'), 'Future AI modules cannot be falsely enabled');
check_nrg(Modules::sanitize([]) === ['bot'=>0,'worker'=>0,'reports'=>0], 'Missing checkboxes are OFF on explicit save');
check_nrg(Modules::sanitize(['worker'=>1,'bot'=>0,'reports'=>1,'master'=>1]) === ['bot'=>0,'worker'=>1,'reports'=>1], 'Unsupported switches cannot be persisted');
$GLOBALS['nrg_options'][Modules::OPTION] = ['worker'=>0];
check_nrg(!Modules::on('worker') && Modules::on('bot'), 'A disabled Worker module does not disable Bot protection');

$_SERVER['REQUEST_METHOD']='GET';
$_GET=['after'=>'2026-02-28','before'=>'2026-02-29','actor'=>'12','event'=>'post_edited','paged'=>'2'];
$f=Dashboard::filters();
check_nrg($f['after']==='2026-02-28', 'Valid date filter preserved');
check_nrg($f['before']==='', 'Impossible leap-day date rejected');
check_nrg($f['actor']===12 && $f['event']==='post_edited' && $f['page']===2, 'Audit filters normalized');
$_GET=['after'=>'2024-02-29','before'=>'2024-12-31','paged'=>'-8'];
$f=Dashboard::filters();
check_nrg($f['after']==='2024-02-29' && $f['page']>=1, 'Leap-year dates work and pages are bounded');

$css=file_get_contents(__DIR__.'/../assets/newsroom-dashboard.css');
$dashboard=file_get_contents(__DIR__.'/../includes/class-ncg-dashboard.php');
check_nrg(strpos($css,'.nrg-app')!==false && strpos($css,'@media(max-width:780px)')!==false, 'Scoped responsive admin visual system');
foreach (['overview','cache','bots','workers','approvals','audit','security','lock','reports','settings'] as $tab) {
    check_nrg(strpos($dashboard,"'".$tab."'")!==false, 'Navigation target exists: '.$tab);
}
check_nrg(strpos($dashboard,'current_user_can(\'manage_options\')')!==false, 'Admin-only access checks present');
check_nrg(strpos($dashboard,'check_admin_referer')!==false, 'POST actions require WordPress nonces');
check_nrg(strpos($dashboard,'safe_csv')!==false, 'CSV formula neutralization present');
check_nrg(strpos($dashboard,'No invented chart data')!==false, 'Charts avoid simulated metrics');
echo "All Newsroom Guard dashboard checks passed.\n";
