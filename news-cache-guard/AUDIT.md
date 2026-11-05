# Source audit — 2026-11-05

Scope: user-provided `news-cache-guard-v1.0.1-hotfix.zip`, tested and patched before repository initialization. No existing code was present in `indigiti/wpncgx` at the start of the audit.

## Fixed in 1.0.2

| Severity | Finding | Resolution |
| --- | --- | --- |
| Critical | An IP allowlist triggered `force_public_cache()` before public-content, query and session checks. | Allowlisting now bypasses rate limits only; it never enables public caching. |
| High | Default `safe_shared`/`cache_only` calls explicitly declared pages cacheable, possibly circumventing native LSCWP exclusion behavior. | These policies now defer to LiteSpeed Cache's native eligibility; only the explicit Force Cache policy overrides it. |
| High | Force Cache could override site content rendering on unknown query keys or common session cookies. | Force Cache now requires a published public candidate with no unknown query arguments, known personalization cookies, authorization, preview, or `DONOTCACHEPAGE`. |
| High | Search limiting ran at `template_redirect`, **after** WordPress had already executed the expensive search. | Count search queries on early plugin bootstrap before query execution; avoid duplicate counts later. |
| High | `xmlrpc_enabled` disables authenticated methods but does not disable unauthenticated pingback XML-RPC methods. | `xmlrpc_mode=disable` also rejects `/xmlrpc.php` on boot. |
| Medium | A wildcard drop key such as `s*` could shadow a protected query key (`s`) because only exact base matches were checked. | Reject any wildcard matching protected keys. |
| Medium | Query-drop diagnostic case-insensitively matched names, while LiteSpeed rules are case-sensitive. | Use one matching function with LiteSpeed case semantics. |
| Medium | A REST route could be counted more than once if dispatched repeatedly in the same PHP request. | Count at most once per PHP request and record dry-run rejections. |
| Low | Exact IPv6 allowlist entries compared raw text rather than normalized addresses. | Compare packed address bytes for exact IP entries. |
| Low | Admin synchronization notice was URL-encoded twice and status indicator ignored global enabled state. | Fixed. |

## Remaining risks / deployment validation (not claimed as fixed)

1. **Critical environment boundary:** WordPress-level protection runs only when PHP executes. A cache HIT or static file may bypass the plugin. Put large-scale floods, bot challenges and IP blocks at the CDN/WAF or web-server edge.
2. **High environment requirement:** `wp_using_ext_object_cache()` indicates a drop-in is enabled, not that Redis/Memcached is actually healthy, persistent across workers or atomic under concurrent traffic. Test a real shared cache backend, failure behavior and high-concurrency counters.
3. **High site-specific privacy requirement:** Explicit Force Cache cannot know every site's membership, commerce or personalized cookie/query mechanism. Use Safe Shared Cache unless custom templates, headers, cookies and third-party plugins have been audited.
4. **Medium algorithm limit:** Rate limits use fixed time buckets rather than rolling windows; bursts around a window boundary can temporarily double the advertised allowance. Not suitable as sole DDoS control.
5. **Medium hosting limitation:** `.htaccess` rules target WordPress's `ABSPATH`. Subdirectory installs, non-LiteSpeed web servers, protected `.htaccess` and multisite routing need specific review. Verify actual LiteSpeed cache-key behavior through response headers and logs.
6. **Medium purge overhead:** Post saves/status transitions can trigger overlapping purges. Benchmark newsroom bulk publishing and implement de-duplication or batched purges before high-volume deployments.
7. **Medium functional requirement:** Search throttling should be tested with regular permalinks, query-string search, Cloudflare proxy traffic, anonymous visitors, and multilingual/search plugins. REST and XML-RPC exemptions need operational review.
8. **Medium origin exposure:** Cloudflare header trust depends on a correct, current proxy CIDR list and a locked-down origin; no PHP code prevents direct-to-origin requests or mitigates origin flood traffic before PHP.

## Local checks

- `php -l` passed on all 12 PHP files (11 plugin files + one standalone regression suite).
- `php tests/regression.php` passed all 18 regression assertions (IP bucketing/CIDRs, wildcard filtering, cache safety, search, REST, XML-RPC).
- **Not completed:** WordPress integration tests, real Redis atomicity, LiteSpeed cache MISS/HIT/TTL/purge validation, Cloudways deployment, traffic load tests, and production security sign-off.

## Default rollout

Keep `dry_run=1`, `default_policy=safe_shared`, `query_collapse=1`; confirm server-side LSCache and Redis health on staging, backup `.htaccess`, then test the scenarios in `TESTING.md`. Never enable `force_cache` for unknown third-party WordPress stacks.

## 1.0.3 addendum — search protection

Added early Must-Use search guard with a 3-per-fixed-minute limit and proper coexistence with the original search limiter. The main plugin cannot guarantee an enforced cap while Dry Run is active; the MU file must be deployed separately for independent early enforcement. Cached responses and nonstandard search endpoints are outside the MU guard scope. Tests exercise counters, IPv4/IPv6, invalid inputs, Cloudflare trust, Redis fail-open, and duplicate-count prevention. No live Cloudways/Redis integration has been performed.
