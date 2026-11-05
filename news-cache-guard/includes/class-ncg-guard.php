<?php

declare(strict_types=1);

namespace NCG;

if (!defined('ABSPATH')) exit;

final class Guard {
    private string $policy = 'safe_shared';
    private string $identity = '';
    private string $clientIp = '';
    private bool $wouldBlock = false;
    private string $state = 'normal';
    private bool $earlySearchChecked = false;
    private bool $restChecked = false;

    public function boot(): void {
        add_action('plugins_loaded', [$this, 'early_scanner_guard'], 2);
        add_action('template_redirect', [$this, 'frontend_guard'], 0);
        add_filter('rest_pre_dispatch', [$this, 'rest_guard'], 1, 3);
        add_filter('xmlrpc_enabled', [$this, 'xmlrpc_enabled']);
        add_action('xmlrpc_call', [$this, 'xmlrpc_call_guard'], 0, 1);
        add_action('send_headers', [$this, 'debug_headers'], PHP_INT_MAX);
    }

    public function early_scanner_guard(): void {
        if (!$this->enabled()) return;
        $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

        // The xmlrpc_enabled filter only disables authenticated XML-RPC methods;
        // reject the endpoint itself to also prevent pingback methods running.
        if ((string)Settings::get('xmlrpc_mode', 'rate_limit') === 'disable'
            && preg_match('~(?:^|/)xmlrpc\.php$~i', $path)) {
            Counter::stat('xmlrpc_rejected');
            $this->respond(403, 'Forbidden');
        }

        // Search SQL executes before template_redirect. Count here instead of
        // waiting for a costly query to finish. This is origin-side protection;
        // cache hits never reach PHP.
        if ($this->is_frontend_candidate() && $this->is_search_uri($path)
            && !defined('NCG_MU_SEARCH_GUARD_HANDLED')) {
            if ($this->init_identity() && !$this->is_allowed()) {
                $this->earlySearchChecked = true;
                $count = Counter::increment('search', $this->identity, (int)Settings::get('search_window', 60));
                if ($count > (int)Settings::get('search_limit', 10)) {
                    $this->block_or_dry('search-rate');
                }
            }
        }
        foreach (Settings::lines('scanner_paths') as $needle) {
            if ($needle !== '' && stripos($path, $needle) !== false) {
                if ((int)Settings::get('dry_run', 1) === 1) {
                    $this->state = 'limit:scanner-path';
                    $this->wouldBlock = true;
                    Counter::stat('would_block');
                    return;
                }
                Counter::stat('scanner_rejected');
                $this->respond((string)Settings::get('scanner_action', '404') === '403' ? 403 : 404, 'Not Found');
            }
        }
    }

    public function frontend_guard(): void {
        if (!$this->enabled() || !$this->is_frontend_candidate()) return;
        if (!$this->init_identity()) {
            // Preserve no-cache instructions even when rate limiting fails open.
            if (is_search() || is_404()) LiteSpeed::no_cache();
            return;
        }
        if ($this->is_denied()) { $this->block_or_dry('denylist'); if ($this->wouldBlock) return; }
        if ($this->is_allowed()) {
            // Allowlisting bypasses rate limits, not WordPress privacy rules.
            $this->policy = 'monitor';
            return;
        }

        $rule = $this->matched_rule();
        $this->policy = $rule['policy'] ?? (string)Settings::get('default_policy', 'safe_shared');
        if ($this->policy === 'force_cache') $this->policy = 'safe_shared';

        if (is_search()) {
            LiteSpeed::no_cache();
            if (!$this->earlySearchChecked && !defined('NCG_MU_SEARCH_GUARD_HANDLED')) {
                $count = Counter::increment('search', $this->identity, (int)Settings::get('search_window', 60));
                if ($count > (int)Settings::get('search_limit', 10)) $this->block_or_dry('search-rate');
            }
            return;
        }

        if (is_404()) {
            LiteSpeed::no_cache();
            $count = Counter::increment('404', $this->identity, (int)Settings::get('window_404', 60));
            Counter::stat('origin_404');
            if ($count > (int)($rule['limit_404'] ?? Settings::get('limit_404', 2))) $this->block_or_dry('404-rate');
            return;
        }

        if (!$this->is_safe_public_request()) return;

        // Preserve LSCWP's native cache exclusions. Forcing public caching
        // at PHP level can leak dynamically personalized responses.

        Counter::stat('origin_requests');
        $window = (int)($rule['window'] ?? Settings::get('origin_window', 60));
        $threshold = (int)($rule['threshold'] ?? Settings::get('origin_threshold', 5));
        if ((int)Settings::get('emergency_mode', 0) === 1) $threshold = max(1, (int)floor($threshold / 2));
        $originCount = Counter::increment('origin', $this->identity, $window);

        if ($this->policy === 'monitor' || !Counter::available()) return;

        $postId = is_singular() ? (int)get_queried_object_id() : 0;
        if ($postId > 0 && Counter::has_flag('regen_post_' . $postId, 'global')) {
            Counter::delete_flag('regen_post_' . $postId, 'global');
            $this->state = 'editorial-regeneration';
            return;
        }

        $protected = Counter::has_flag('protected', $this->identity);
        if ($this->policy === 'cache_only') $protected = true;
        if (!$protected && $originCount > $threshold) {
            Counter::set_flag('protected', $this->identity, (int)($rule['duration'] ?? Settings::get('protection_duration', 300)));
            Counter::stat('protected_origins');
            $protected = true;
        }
        if (!$protected) return;

        $this->state = 'protected';
        $missWindow = (int)Settings::get('miss_window', 60);
        $missLimit = (int)($rule['miss'] ?? Settings::get('miss_limit', 2));
        $missCount = Counter::increment('miss', $this->identity, $missWindow);

        $uri = $this->canonical_origin_uri();
        $uniqueCount = Counter::increment_unique('url', $this->identity, $uri, (int)Settings::get('unique_url_window', 60));
        $uniqueLimit = (int)($rule['unique'] ?? Settings::get('unique_url_limit', 3));

        if ($missCount > $missLimit || $uniqueCount > $uniqueLimit) {
            $this->block_or_dry($missCount > $missLimit ? 'miss-budget' : 'unique-url-budget');
        }
    }

    public function rest_guard($result, \WP_REST_Server $server, \WP_REST_Request $request) {
        if (!$this->enabled() || is_user_logged_in() || $this->restChecked) return $result;
        $this->restChecked = true;
        LiteSpeed::no_cache();
        if (!$this->init_identity()) return $result;
        if ($this->is_allowed()) return $result;
        if (!Counter::available()) return $result;
        $count = Counter::increment('rest', $this->identity, (int)Settings::get('rest_window', 60));
        if ($count > (int)Settings::get('rest_limit', 30)) {
            if ((int)Settings::get('dry_run', 1) === 1) {
                $this->wouldBlock = true;
                Counter::stat('would_block');
                return $result;
            }
            Counter::stat('429');
            return new \WP_Error('ncg_rest_rate_limited', 'Too Many Requests', ['status' => 429]);
        }
        return $result;
    }

    public function xmlrpc_enabled(bool $enabled): bool {
        if ($this->enabled() && (string)Settings::get('xmlrpc_mode', 'rate_limit') === 'disable') return false;
        return $enabled;
    }

    public function xmlrpc_call_guard(string $method): void {
        if (!$this->enabled() || (string)Settings::get('xmlrpc_mode', 'rate_limit') !== 'rate_limit') return;
        if (!$this->init_identity()) return;
        if ($this->is_allowed() || !Counter::available()) return;
        $count = Counter::increment('xmlrpc', $this->identity, 60);
        if ($count > (int)Settings::get('xmlrpc_limit', 10)) $this->block_or_dry('xmlrpc-rate');
    }

    private function enabled(): bool {
        return (int)Settings::get('enabled', 1) === 1;
    }

    private function init_identity(): bool {
        if ($this->identity !== '') return true;
        $ip = IP::client_ip();
        if ($ip === null) return false;
        $this->clientIp = $ip;
        $this->identity = IP::bucket($ip);
        return true;
    }

    private function is_search_uri(string $path): bool {
        return array_key_exists('s', $_GET)
            || (bool)preg_match('~(?:^|/)search(?:/|$)~i', rawurldecode($path));
    }

    private function has_personalization_cookie(): bool {
        // Opt out when common WordPress commerce, password and visitor-session
        // cookies indicate that a page might depend on the current visitor.
        foreach (array_keys($_COOKIE) as $name) {
            foreach (['wordpress_logged_in_', 'wp-postpass_', 'comment_author_',
                      'woocommerce_items_in_cart', 'woocommerce_cart_hash',
                      'wp_woocommerce_session_', 'edd_items_in_cart',
                      'PHPSESSID'] as $prefix) {
                if (strpos((string)$name, $prefix) === 0) return true;
            }
        }
        return false;
    }

    private function is_frontend_candidate(): bool {
        if (is_admin() || wp_doing_cron()) return false;
        // Do not confuse REST, login or infrastructure routes with front-end
        // searches merely because they include an `s` query parameter.
        if (array_key_exists('rest_route', $_GET)) return false;
        $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        if (preg_match('~(?:^|/)(?:wp-admin|wp-json)(?:/|$)|(?:^|/)(?:wp-login\.php|xmlrpc\.php|wp-cron\.php|admin-ajax\.php)(?:/|$)~i', rawurldecode($path))) return false;
        if (defined('WP_CLI') && WP_CLI) return false;
        if (is_user_logged_in()) return false;
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET','HEAD'], true)) return false;
        if (!empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
            || !empty($_SERVER['PHP_AUTH_USER']) || !empty($_SERVER['HTTP_X_API_KEY'])) return false;
        return true;
    }

    private function is_safe_public_request(): bool {
        if (is_preview() || $this->has_personalization_cookie()) return false;
        if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) return false;
        // Unknown query keys can represent content-changing personalization.
        // Do not override LSCWP's exclusion logic with Force Cache.
        foreach (array_keys($_GET) as $key) {
            if (!Htaccess::matches_drop_key((string)$key)) return false;
        }
        foreach (Settings::lines('protected_query_keys') as $key) {
            if ($key !== '' && array_key_exists($key, $_GET)) return false;
        }
        if (is_singular()) {
            $post = get_queried_object();
            return $post instanceof \WP_Post && $post->post_status === 'publish';
        }
        return is_home() || is_front_page() || is_category() || is_tag() || is_archive() || is_feed();
    }

    private function is_allowed(): bool {
        return IP::in_any_cidr($this->clientIp, Settings::lines('allowlist'));
    }

    private function is_denied(): bool {
        return IP::in_any_cidr($this->clientIp, Settings::lines('denylist'));
    }

    private function matched_rule(): array {
        $best = [];
        $bestPrefix = -1;
        foreach (Settings::lines('rules') as $line) {
            $parts = array_map('trim', explode('|', $line));
            $cidr = $parts[0] ?? '';
            if ($cidr === '' || !IP::in_cidr($this->clientIp, $cidr)) continue;
            $prefix = strpos($cidr, '/') !== false ? (int)substr(strrchr($cidr, '/'), 1) : (strpos($cidr, ':') !== false ? 128 : 32);
            if ($prefix < $bestPrefix) continue;
            $policy = $parts[1] ?? '';
            if (!in_array($policy, ['monitor','safe_shared','cache_only'], true)) $policy = (string)Settings::get('default_policy', 'safe_shared');
            $best = [
                'cidr' => $cidr,
                'policy' => $policy,
                'threshold' => isset($parts[2]) && is_numeric($parts[2]) ? max(1,(int)$parts[2]) : null,
                'window' => isset($parts[3]) && is_numeric($parts[3]) ? max(5,(int)$parts[3]) : null,
                'duration' => isset($parts[4]) && is_numeric($parts[4]) ? max(10,(int)$parts[4]) : null,
                'miss' => isset($parts[5]) && is_numeric($parts[5]) ? max(1,(int)$parts[5]) : null,
                'unique' => isset($parts[6]) && is_numeric($parts[6]) ? max(1,(int)$parts[6]) : null,
                'limit_404' => isset($parts[7]) && is_numeric($parts[7]) ? max(1,(int)$parts[7]) : null,
            ];
            $bestPrefix = $prefix;
        }
        return array_filter($best, static fn($v) => $v !== null);
    }

    private function canonical_origin_uri(): string {
        $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        return rawurldecode($path);
    }

    private function block_or_dry(string $reason): void {
        $this->state = 'limit:' . $reason;
        if ((int)Settings::get('dry_run', 1) === 1) {
            $this->wouldBlock = true;
            Counter::stat('would_block');
            return;
        }
        $status = (string)Settings::get('excess_action', '429') === '403' ? 403 : 429;
        Counter::stat((string)$status);
        $this->respond($status, $status === 429 ? 'Too Many Requests' : 'Forbidden');
    }

    private function respond(int $status, string $message): void {
        if ((int)Settings::get('dry_run', 1) === 1 && $status !== 404 && $status !== 403) {
            $this->wouldBlock = true;
            return;
        }
        status_header($status);
        nocache_headers();
        if ($status === 429 && !headers_sent()) header('Retry-After: ' . (int)Settings::get('retry_after', 60));
        if (!headers_sent()) header('Content-Type: text/plain; charset=UTF-8');
        echo $message . "\n";
        exit;
    }

    public function debug_headers(): void {
        if ((int)Settings::get('debug_headers', 0) !== 1 || headers_sent()) return;
        if ($this->identity === '') $this->init_identity();
        header('X-NCG-Policy: ' . $this->policy);
        header('X-NCG-State: ' . $this->state);
        header('X-NCG-Redis: ' . (Counter::available() ? 'available' : 'fail-open'));
        if ($this->wouldBlock) header('X-NCG-Would-Block: 1');
    }
}
