<?php

declare(strict_types=1);

namespace NCG;

if (!defined('ABSPATH')) exit;

final class Admin {
    public function boot(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register']);
        add_action('admin_post_ncg_sync_htaccess', [$this, 'sync_htaccess']);
        add_action('admin_post_ncg_cache_probe', [$this, 'cache_probe']);
    }

    public function menu(): void {
        add_options_page('News Cache Guard', 'News Cache Guard', 'manage_options', 'news-cache-guard', [$this, 'render']);
    }

    public function register(): void {
        register_setting('ncg', Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => static function ($value) {
                $clean = Settings::sanitize(is_array($value) ? $value : []);
                // Schedule sync after option has been persisted.
                add_action('shutdown', static function () { Htaccess::sync(); });
                return $clean;
            },
            'default' => Settings::defaults(),
        ]);
    }

    public function sync_htaccess(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('ncg_sync_htaccess');
        $result = Htaccess::sync();
        $url = add_query_arg(['page'=>'news-cache-guard','ncg_notice'=>$result['message']], admin_url('options-general.php'));
        wp_safe_redirect($url);
        exit;
    }

    public function cache_probe(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('ncg_cache_probe');
        $result = Counter::atomic_probe();
        $url = add_query_arg(['page' => 'news-cache-guard', 'ncg_cache_probe' => $result['ok'] ? 'passed' : 'failed'], admin_url('options-general.php'));
        wp_safe_redirect($url);
        exit;
    }

    public function render(): void {
        if (!current_user_can('manage_options')) return;
        $s = Settings::get_all();
        $notice = isset($_GET['ncg_notice']) ? sanitize_text_field(wp_unslash($_GET['ncg_notice'])) : '';
        $ls = LiteSpeed::detected();
        $redis = Counter::available();
        $muSearch = defined('NCG_MU_SEARCH_GUARD_LOADED');
        $rules = Htaccess::rules();
        $proxyIpValid = IP::client_ip() !== null;
        $probe = isset($_GET['ncg_cache_probe']) ? sanitize_key(wp_unslash($_GET['ncg_cache_probe'])) : '';
        ?>
        <div class="wrap">
            <h1>News Cache Guard for LiteSpeed <small style="font-size:13px">v<?php echo esc_html(NCG_VERSION); ?></small></h1>
            <?php if ($notice): ?><div class="notice notice-info"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <?php if (get_option(Settings::OPTION, []) && is_array(get_option(Settings::OPTION, [])) && (get_option(Settings::OPTION, [])['default_policy'] ?? '') === 'force_cache'): ?><div class="notice notice-warning"><p><strong>Safety upgrade:</strong> a previously saved Force Cache policy has been converted to Safe Shared Cache at runtime. Public cache exclusions are never overridden.</p></div><?php endif; ?>
            <?php if (!$proxyIpValid): ?><div class="notice notice-warning"><p><strong>Visitor identity unavailable:</strong> REMOTE_ADDR is invalid or a trusted Cloudflare proxy did not provide a valid CF-Connecting-IP. IP-based limits fail open to avoid blocking everyone behind one proxy.</p></div><?php endif; ?>
            <?php if ($probe === 'passed'): ?><div class="notice notice-success"><p>Object cache atomic add/increment/read check passed for this request. This does not certify cross-worker Redis reliability.</p></div><?php endif; ?>
            <?php if ($probe === 'failed'): ?><div class="notice notice-error"><p>Object cache atomic check failed. Do not enable strict origin limits until the Redis/object-cache backend is healthy.</p></div><?php endif; ?>
            <?php if (!$redis): ?><div class="notice notice-warning"><p><strong>Fail-open:</strong> no persistent object cache was detected. Rate limits are disabled so the plugin cannot block traffic due to missing Redis/object cache.</p></div><?php endif; ?>
            <?php if (!$muSearch): ?><div class="notice notice-warning"><p><strong>Search Guard MU not installed:</strong> the 3-search fallback obeys this plugin's Dry Run switch. For strict early enforcement independent of Dry Run, copy <code>news-cache-guard/mu-plugins/wp-search-guard.php</code> to <code>wp-content/mu-plugins/wp-search-guard.php</code>.</p></div><?php endif; ?>
            <?php if ($muSearch && $s['dry_run']): ?><div class="notice notice-info"><p><strong>Search Guard MU is active:</strong> its 3-per-60-second limit enforces even while News Cache Guard Dry Run is on. All other limits continue to follow Dry Run.</p></div><?php endif; ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;max-width:1000px;margin:16px 0">
                <?php $cards = [
                    ['LiteSpeed Cache', $ls ? 'DETECTED' : 'CHECK SERVER'],
                    ['Persistent Object Cache', $redis ? 'CONFIGURED (NOT HEALTH TESTED)' : 'FAIL-OPEN'],
                    ['Protection', !$s['enabled'] ? 'DISABLED' : ($s['dry_run'] ? 'DRY RUN' : 'ENFORCING')],
                    ['Default Policy', strtoupper(str_replace('_',' ',(string)$s['default_policy']))],
                    ['IPv6 Bucket', '/'.(int)$s['ipv6_prefix']],
                    ['Client IP confidence', $proxyIpValid ? 'RESOLVED' : 'UNKNOWN / FAIL-OPEN'],
                    ['Query Collapse', $s['query_collapse'] ? 'ON' : 'OFF'],
                    ['Client no-cache reuse', $s['ignore_request_no_cache'] ? 'OPT-IN / CHECK SERVER' : 'OFF'],
                    ['Search Guard (3/60s)', $muSearch ? 'MU ACTIVE' : 'FALLBACK'],
                    ['PM Worker Guard', 'ACTIVE (5 / 1 DEFAULT)'],
                ]; foreach ($cards as [$a,$b]): ?>
                    <div style="background:#fff;border:1px solid #dcdcde;padding:14px"><div style="font-size:12px;color:#646970"><?php echo esc_html($a); ?></div><strong><?php echo esc_html($b); ?></strong></div>
                <?php endforeach; ?>
            </div>

            <p><strong>Background work:</strong> PM Worker Guard limits Action Scheduler queue claims and SEO sitemap sizes. <a href="<?php echo esc_url(admin_url('tools.php?page=ncg-worker-guard')); ?>">View worker guard status and safe cron instructions</a>.</p>

            <h2>Today (Redis/object-cache counters)</h2>
            <table class="widefat striped" style="max-width:1000px"><tbody>
                <?php foreach ([
                    'origin_requests'=>'Origin requests', 'protected_origins'=>'Protection activations', 'would_block'=>'Would block (dry run)',
                    '429'=>'429 responses', '403'=>'403 responses', 'origin_404'=>'Origin 404s', 'scanner_rejected'=>'Scanner paths rejected',
                    'fresh_regeneration_permission'=>'Editorial regeneration permissions'
                ] as $k=>$label): ?>
                    <tr><td><?php echo esc_html($label); ?></td><td><strong><?php echo esc_html((string)Counter::stat_value((string)$k)); ?></strong></td></tr>
                <?php endforeach; ?>
            </tbody></table>

            <form method="post" action="options.php" style="max-width:1100px">
                <?php settings_fields('ncg'); ?>
                <h2>Protection</h2>
                <table class="form-table" role="presentation">
                    <?php $this->checkbox('enabled','Enable protection',$s); ?>
                    <?php $this->checkbox('dry_run','Dry Run (record decisions but do not rate-limit)',$s); ?>
                    <?php $this->checkbox('emergency_mode','Emergency mode (halves origin threshold)',$s); ?>
                    <tr><th>Default policy</th><td><select name="<?php echo esc_attr(Settings::OPTION); ?>[default_policy]">
                        <?php foreach (['monitor'=>'Monitor Only','safe_shared'=>'Safe Shared Cache','cache_only'=>'Cache-Only / PHP-Origin Limit'] as $k=>$v): ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($s['default_policy'],$k); ?>><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?></select></td></tr>
                    <?php $this->number('origin_threshold','Origin threshold',$s,1,10000,'Requests reaching PHP within the origin window before protected mode. Cached LSCache hits never execute this plugin.'); ?>
                    <?php $this->number('origin_window','Origin window (seconds)',$s,5,3600); ?>
                    <?php $this->number('protection_duration','Protection duration (seconds)',$s,10,86400); ?>
                    <?php $this->number('miss_limit','Protected MISS allowance',$s,1,10000); ?>
                    <?php $this->number('miss_window','MISS window (seconds)',$s,5,3600); ?>
                    <?php $this->number('unique_url_limit','Unique uncached URL allowance',$s,1,10000); ?>
                    <?php $this->number('unique_url_window','Unique URL window (seconds)',$s,5,3600); ?>
                    <?php $this->number('limit_404','404 origin limit',$s,1,10000); ?>
                    <tr><th>Search rate limit</th><td><strong>3 searches per IP/IPv6 /64 per fixed 60-second window</strong><p class="description">MU module enforces independently of Dry Run; without MU, the ordinary plugin falls back to the same cap but respects Dry Run. Requires persistent, atomic object cache.</p></td></tr>
                    <?php $this->number('rest_limit','REST limit',$s,1,10000); ?>
                </table>

                <h2>IP / Proxy</h2>
                <table class="form-table">
                    <?php $this->number('ipv4_prefix','IPv4 bucket prefix',$s,8,32); ?>
                    <?php $this->number('ipv6_prefix','IPv6 bucket prefix',$s,32,128); ?>
                    <?php $this->checkbox('trust_cloudflare','Trust CF-Connecting-IP only when REMOTE_ADDR matches a trusted Cloudflare CIDR',$s); ?>
                    <?php $this->textarea('trusted_proxy_cidrs','Trusted proxy CIDRs',$s,'Cloudflare ranges are preloaded. Keep these current if you use Cloudflare.'); ?>
                    <?php $this->textarea('allowlist','Allowlist IP/CIDR',$s,'One per line. Allowlisted traffic bypasses rate limits; it never forces public caching.'); ?>
                    <?php $this->textarea('denylist','Denylist IP/CIDR',$s,'One per line.'); ?>
                    <?php $this->textarea('rules','CIDR policy rules',$s,'Format: CIDR|policy|threshold|window|duration|miss|unique|404. Example: 203.0.113.0/24|cache_only|5|60|300|2|3|2'); ?>
                </table>

                <h2>Bot fresh-request cache defense (advanced, request-side)</h2>
                <p><strong>What this solves:</strong> bots and browsers sending <code>Cache-Control: no-cache</code> or <code>Pragma: no-cache</code> can cause repeated origin work despite an already populated LiteSpeed page cache. <code>CacheIgnoreCacheControl On</code> tells LiteSpeed to attempt to reuse an eligible cache entry anyway.</p>
                <p><strong>Scope and risk:</strong> this affects cache lookups across this entire WordPress directory, <em>not only bots</em>. It does not force response caching and must never replace authenticated/private/no-store exclusions. A normal hard refresh may no longer bypass cache. Keep OFF until you verify staging privacy, editorial purges, and existing LiteSpeed HIT/MISS behavior. A WordPress plugin cannot determine verified bot status on a cache HIT.</p>
                <table class="form-table">
                    <?php $this->checkbox('ignore_request_no_cache','Ignore client no-cache request headers during LiteSpeed cache lookup (advanced opt-in)',$s); ?>
                </table>
                <?php if ($s['ignore_request_no_cache']): ?><div class="notice notice-warning inline"><p><strong>Advanced cache-reuse directive enabled in generated rules.</strong> Verify the root .htaccess was successfully synchronized and test the result. Cloudflare may still serve, bypass, or revalidate independently.</p></div><?php endif; ?>

                <h2>LiteSpeed query collapse</h2>
                <p>These rules alter only the LiteSpeed cache key. They do not redirect the browser or remove UTM values from the visible URL.</p>
                <table class="form-table">
                    <?php $this->checkbox('query_collapse','Enable cache-key query collapse',$s); ?>
                    <?php $this->textarea('drop_query_keys','Ignored query keys',$s,'Supports exact names and LiteSpeed prefix wildcards such as utm*.'); ?>
                    <?php $this->textarea('protected_query_keys','Protected query keys',$s,'Protected keys are never removed from the cache key; they may identify content-changing requests.'); ?>
                </table>

                <h2>Scanner / XML-RPC</h2>
                <table class="form-table">
                    <?php $this->textarea('scanner_paths','Fast-reject scanner paths',$s,'Matched during plugin bootstrap before theme/query rendering.'); ?>
                    <tr><th>Scanner response</th><td><select name="<?php echo esc_attr(Settings::OPTION); ?>[scanner_action]"><option value="404" <?php selected($s['scanner_action'],'404'); ?>>404</option><option value="403" <?php selected($s['scanner_action'],'403'); ?>>403</option></select></td></tr>
                    <tr><th>XML-RPC</th><td><select name="<?php echo esc_attr(Settings::OPTION); ?>[xmlrpc_mode]"><option value="disable" <?php selected($s['xmlrpc_mode'],'disable'); ?>>Disable</option><option value="rate_limit" <?php selected($s['xmlrpc_mode'],'rate_limit'); ?>>Rate limit</option><option value="allow" <?php selected($s['xmlrpc_mode'],'allow'); ?>>Allow</option></select></td></tr>
                </table>

                <h2>Editorial invalidation</h2>
                <table class="form-table">
                    <?php $this->checkbox('purge_home','Purge homepage on publish/update',$s); ?>
                    <?php $this->checkbox('purge_categories','Purge affected categories',$s); ?>
                    <?php $this->checkbox('purge_feeds','Purge main feed',$s); ?>
                    <?php $this->checkbox('purge_sitemap','Purge common sitemap URLs',$s); ?>
                    <tr><th>Force Public Cache</th><td><strong>Retired; request-side cache reuse is available above.</strong><p class="description">The plugin does not override LiteSpeed's native public/private response eligibility rules. Legacy Force Cache values are treated as Safe Shared Cache.</p></td></tr>
                    <?php $this->checkbox('debug_headers','Emit X-NCG-* diagnostic headers',$s); ?>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <h2>Generated LiteSpeed rules</h2>
            <p>These rules are inserted in the root <code>.htaccess</code> between News Cache Guard markers.</p>
            <textarea readonly style="width:100%;max-width:1000px;height:180px;font-family:monospace"><?php echo esc_textarea(implode("\n", $rules)); ?></textarea>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                <input type="hidden" name="action" value="ncg_sync_htaccess"><?php wp_nonce_field('ncg_sync_htaccess'); ?>
                <?php submit_button('Sync .htaccess Now','secondary','submit',false); ?>
            </form>

            <h2>Manual object-cache diagnostic</h2>
            <p>Run an atomic add/increment/read check with a temporary object-cache key (15-second TTL). Does not verify server connectivity during bursts or measure page-cache HIT ratios.</p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="ncg_cache_probe">
                <?php wp_nonce_field('ncg_cache_probe'); ?>
                <?php submit_button('Test Atomic Object Cache', 'secondary', 'submit', false); ?>
            </form>
            <h2>Diagnostic testers</h2>
            <?php $this->diagnostics(); ?>
        </div>
        <?php
    }

    private function diagnostics(): void {
        $ip = isset($_GET['ncg_ip']) ? sanitize_text_field(wp_unslash($_GET['ncg_ip'])) : '';
        $url = isset($_GET['ncg_url']) ? esc_url_raw(wp_unslash($_GET['ncg_url'])) : '';
        ?>
        <form method="get" style="max-width:1000px;background:#fff;padding:14px;border:1px solid #dcdcde;margin-bottom:10px">
            <input type="hidden" name="page" value="news-cache-guard"><strong>IP tester</strong><br>
            <input type="text" name="ncg_ip" value="<?php echo esc_attr($ip); ?>" style="width:380px" placeholder="2401:db8:... or 203.0.113.25">
            <button class="button">Test</button>
            <?php if (($valid = IP::valid_ip($ip)) !== null): ?><p>Bucket: <code><?php echo esc_html(IP::bucket($valid)); ?></code></p><?php endif; ?>
        </form>
        <form method="get" style="max-width:1000px;background:#fff;padding:14px;border:1px solid #dcdcde">
            <input type="hidden" name="page" value="news-cache-guard"><strong>URL query-collapse tester</strong><br>
            <input type="url" name="ncg_url" value="<?php echo esc_attr($url); ?>" style="width:70%" placeholder="https://example.com/news/story/?utm_source=facebook&preview=1">
            <button class="button">Test</button>
            <?php if ($url): $parts = wp_parse_url($url); parse_str((string)($parts['query'] ?? ''), $q); $dropped=[];$kept=[]; foreach(array_keys($q) as $key){ if($this->matches_drop((string)$key))$dropped[]=$key;else$kept[]=$key;} ?>
                <p>Dropped from cache key: <code><?php echo esc_html(implode(', ', $dropped) ?: 'none'); ?></code><br>Kept in cache key: <code><?php echo esc_html(implode(', ', $kept) ?: 'none'); ?></code></p>
            <?php endif; ?>
        </form>
        <?php
    }

    private function matches_drop(string $key): bool {
        return Htaccess::matches_drop_key($key);
    }

    private function checkbox(string $key, string $label, array $s): void { ?>
        <tr><th><?php echo esc_html($label); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(Settings::OPTION); ?>[<?php echo esc_attr($key); ?>]" value="1" <?php checked((int)$s[$key],1); ?>> Enabled</label></td></tr>
    <?php }
    private function number(string $key, string $label, array $s, int $min, int $max, string $help=''): void { ?>
        <tr><th><?php echo esc_html($label); ?></th><td><input type="number" min="<?php echo esc_attr((string)$min); ?>" max="<?php echo esc_attr((string)$max); ?>" name="<?php echo esc_attr(Settings::OPTION); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string)$s[$key]); ?>"> <?php if($help): ?><p class="description"><?php echo esc_html($help); ?></p><?php endif; ?></td></tr>
    <?php }
    private function textarea(string $key, string $label, array $s, string $help=''): void { ?>
        <tr><th><?php echo esc_html($label); ?></th><td><textarea name="<?php echo esc_attr(Settings::OPTION); ?>[<?php echo esc_attr($key); ?>]" rows="6" style="width:100%;max-width:700px;font-family:monospace"><?php echo esc_textarea((string)$s[$key]); ?></textarea><?php if($help): ?><p class="description"><?php echo esc_html($help); ?></p><?php endif; ?></td></tr>
    <?php }
}
