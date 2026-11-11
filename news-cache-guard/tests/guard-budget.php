<?php
/**
 * P2 budget-regression harness. Exercise Guard with deterministic stubbed
 * counters so no actual HTTP/Redis/database calls are made.
 *
 * Usage: php tests/guard-budget.php dry
 *        php tests/guard-budget.php enforce
 */
declare(strict_types=1);
namespace NCG;
define('ABSPATH', __DIR__);
$GLOBALS['budget_unique'] = 0;
$GLOBALS['budget_would'] = 0;
$GLOBALS['budget_blocked'] = 0;
$GLOBALS['budget_status'] = 0;
$GLOBALS['budget_mode'] = (string)($argv[1] ?? '');

final class Settings {
    public static function get(string $key, $default = null) {
        $settings = [
            'enabled'=>1, 'default_policy'=>'safe_shared',
            'dry_run'=>$GLOBALS['budget_mode']==='dry' ? 1 : 0,
            'origin_threshold'=>5, 'origin_window'=>60,
            'miss_limit'=>2, 'miss_window'=>60,
            'unique_url_limit'=>3, 'unique_url_window'=>60,
            'excess_action'=>'429'
        ];
        return $settings[$key] ?? $default;
    }
    public static function lines(string $key): array { return []; }
}
final class Counter {
    public static function available(): bool { return true; }
    public static function increment(string $name, string $identity, int $window): int {
        if ($name==='origin') return 6;
        if ($name==='miss') return 3;
        return 0;
    }
    public static function increment_unique(string $name, string $id, string $url, int $window): int {
        ++$GLOBALS['budget_unique'];
        return 1;
    }
    public static function has_flag(string $name,string $identity): bool { return false; }
    public static function set_flag(string $name,string $identity,int $ttl): void {}
    public static function delete_flag(string $name,string $identity): void {}
    public static function stat(string $name, int $ttl=86400): int {
        if ($name==='would_block') ++$GLOBALS['budget_would'];
        if ($name==='429') ++$GLOBALS['budget_blocked'];
        return 1;
    }
}
final class IP {
    public static function client_ip(): ?string { return '203.0.113.50'; }
    public static function bucket(string $ip): string { return $ip; }
    public static function in_any_cidr(string $ip,array $cidrs): bool { return false; }
}
final class LiteSpeed {
    public static function no_cache(): void {}
}
function is_admin(): bool { return false; }
function wp_doing_cron(): bool { return false; }
function is_user_logged_in(): bool { return false; }
function is_search(): bool { return false; }
function is_404(): bool { return false; }
function is_singular(): bool { return false; }
function is_home(): bool { return true; }
function is_front_page(): bool { return false; }
function is_category(): bool { return false; }
function is_tag(): bool { return false; }
function is_archive(): bool { return false; }
function is_feed(): bool { return false; }
function is_preview(): bool { return false; }
function status_header(int $status): void { $GLOBALS['budget_status'] = $status; }
function nocache_headers(): void {}
function budget_check(bool $ok, string $msg): void {
    if (!$ok) { \fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    echo "PASS: $msg\n";
}
$_SERVER['REQUEST_URI'] = '/news/story/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];
$_COOKIE = [];
require_once __DIR__.'/../includes/class-ncg-guard.php';

if ($GLOBALS['budget_mode']==='dry') {
    (new Guard())->frontend_guard();
    budget_check($GLOBALS['budget_unique']===1,
        'Dry-run still accounts for unique URLs after miss-budget threshold');
    budget_check($GLOBALS['budget_would']===1 && $GLOBALS['budget_status']===0,
        'Dry-run logs would-block without returning HTTP 429');
    exit(0);
}
if ($GLOBALS['budget_mode']!=='enforce') {
    \fwrite(STDERR, "Usage: php guard-budget.php dry|enforce\n");
    exit(1);
}

// The real Guard emits the HTTP 429 and exits. Verify the counters and
// response after that exit without changing production response code.
\ob_start();
\register_shutdown_function(static function(): void {
    $output = (string)\ob_get_contents();
    \ob_end_clean();
    if ($GLOBALS['budget_unique'] !== 0 || $GLOBALS['budget_blocked'] !== 1 ||
        $GLOBALS['budget_status'] !== 429 || \strpos($output, 'Too Many Requests') === false) {
        \fwrite(STDERR, "FAIL: Enforced miss budget changed the block result or made extra unique calls.\n");
        exit(1);
    }
    echo "PASS: Enforced over-budget request returned HTTP 429 and skipped unique Redis bookkeeping.\n";
});
(new Guard())->frontend_guard();
\ob_end_clean();
\fwrite(STDERR, "FAIL: Enforced over-budget request did not exit.\n");
exit(1);
