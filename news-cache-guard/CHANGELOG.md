# v1.2.0-rc9 — P3 background work reliability (staging)

- Add on-demand, read-only WP-Cron and Action Scheduler diagnostics to the existing Tools → PM Worker Guard admin page.
- Use one bounded oldest-pending lookup, without full queue counts, log traversal, polling, or jobs created by the diagnostics.
- Distinguish future jobs, stale pending actions, unavailable Action Scheduler, and unknown external cron health.
- Warn when both WP-Cron page triggering and Action Scheduler async loopbacks are disabled. No cron settings or worker limits are changed.
- Include standalone tests for queue/cron states and a real WordPress test enforcing zero SQL writes during a snapshot. Production throughput and server cron execution remain unverified.

# v1.2.0-rc8 — P2 resource efficiency (staging)

- Preserve Redis counter ADD-first initialization and expiry across object-cache backends; reject an unsafe INCR-first strategy that can create immortal keys on missing counters.
- Avoid redundant unique-URL Redis accounting once an enforced miss budget already dictates HTTP 429/403; preserve full dry-run observability.
- Preserve fail-open behavior on Redis failure, no non-atomic read-modify-write, no new Redis cache namespace and no changes to rate thresholds.
- Avoid the separate last-editor SQL query in Post History when the last edit is already among the 20 recent events, with the old query as a fallback for long histories.
- Provide read-only administrator PHP request snapshots: elapsed so far, current/peak memory, database queries and external object cache availability. These are not server CPU/RAM or cache-hit metrics.
- Add unit and WordPress integration checks; do not claim performance improvements without before/after production-like staging benchmarks.

# v1.2.0-rc7 — Performance foundation (staging)

- Request-local Settings and Modules caches, invalidated after WordPress option changes and keyed by blog for Multisite isolation.
- Reuse validated tracking query-key patterns until settings are changed.
- Use a single `wp_after_insert_post` hook to invalidate relevant LiteSpeed URLs after metadata/terms are saved; preserve direct `wp_publish_post()` cron and unpublication invalidation.
- Introduce administrator-only, read-only cache environment signals without remote requests or cache configuration changes.
- Add focused standalone and real WordPress/MariaDB regressions; performance gains remain unmeasured until staging profiling.

# v1.2.0-rc6 — Post History and Editorial Activity

- Independently capture post creation, editing, author changes, scheduling and publishing operator in a private WordPress admin journal.
- Add per-post Post History and administrator Editorial Activity search without changing WordPress Author, themes, URLs, or existing publishing permissions.
- Separate, non-enforcing module switch, isolated journal table, and regression/integration tests.
- Rebuild the prerelease as rc6 rather than reusing the existing signed rc5 release.

# Change history

## v1.0.6 — optional request-side no-cache reuse (2026-11-05)

- Address the original aggressive-crawler use case without restoring response-side `force_public`: add an **OFF-by-default** advanced setting for LiteSpeed `CacheIgnoreCacheControl On` in the managed `.htaccess` marker.
- Keep Safe Shared Cache and normal privacy/cookie/non-cache exclusions. Client no-cache directives and response public/private cacheability are different layers; this setting affects all eligible requests, not just bots.
- Extend read-only cache probes with an anonymous `Cache-Control: no-cache`/`Pragma: no-cache` request and add explicit staging acceptance / rollback guidance.
- Test generated rules, independent query-collapse behavior and safe defaults.


## v1.0.5 — production hardening / staging evidence (2026-11-05)

- Retired Force Public Cache to avoid bypassing LSCache privacy exclusions. Existing stored and CIDR policies normalize to Safe Shared Cache; no WordPress database migration required.
- Fail open on missing/invalid visitor identity behind trusted Cloudflare proxies, rather than combining unrelated users under a Cloudflare edge IP.
- Removed non-atomic object-cache read/write fallback in request counters. When Redis increment fails, a request is allowed rather than making unreliable protection decisions.
- Added manually triggered atomic object-cache diagnostic to the Settings dashboard; do not confuse this with a continuous Redis health monitor.
- Added low-impact cache-header probe, staging-only load-check script, Cloudflare Free bot-protection guidance and deployment acceptance checklist.
- Added regression tests for proxy, Redis-failure, and cache privacy scenarios.

# Changelog

## 1.0.4 — 2026-11-05

- Integrate PM Worker Guard directly into News Cache Guard, with Tools → PM Worker Guard status and safe cron guidance.
- Cap Action Scheduler queue claims at 5 actions/batch and one concurrent batch by default; preserve lower existing filter values.
- Cap Yoast at 250 entries/sitemap and Rank Math at 200 entries/sitemap, preserving existing lower and missing settings.
- Default to Rank Math’s own sitemap cache decision instead of forcibly overriding explicit disable filters; optional force enable after freshness validation.
- Keep async loopback runner enabled unless an explicit wp-config.php constant is set after successful WP-CLI cron verification.
- Add isolated regression checks for caps, filters, admin protection and async-runner opt-in.


## 1.0.3 — 2026-11-05

- Add standalone MU Search Guard for front-end `?s=` and standard `/search/` URLs, with an enforced three-search limit per client IPv4/IPv6 /64 in fixed 60-second windows.
- Use validated Cloudflare client IPs only through configured trusted proxy CIDRs; fail open without atomic persistent object cache, or when a trusted edge lacks a valid visitor IP.
- Reject oversized/array search terms early; return `429` with a window-aware `Retry-After` and `Cache-Control: no-store`.
- Exclude WordPress login, admin, REST, AJAX, XML-RPC and cron routes that incidentally carry search parameters.
- Prevent double counting when the MU guard handles a request; lock the ordinary plugin fallback to 3/60 even for sites with legacy saved limits.
- Show MU installation status in the admin dashboard and add Cloudways-oriented installation, limitations, rollback and regression guidance.


## 1.0.2 — 2026-11-05

- Audited v1.0.1 before first publication to GitHub.
- Fixed unsafe public-cache forcing by IP allowlists and default safe-shared/cache-only policies. Only the explicit Force Cache policy can override LSCWP exclusions, and only on anonymous, non-personalized, publicly eligible requests without unknown query arguments.
- Count search requests during early bootstrap, before WordPress performs its search query; avoid double counting after routing.
- Disable the entire XML-RPC endpoint, including unauthenticated pingbacks, when XML-RPC mode is Disable.
- Prevent wildcard query-key normalization from shadowing protected keys; use LiteSpeed's case-sensitive rule semantics in the URL diagnostic tester.
- Count each REST dispatch only once per PHP request and include dry-run REST violations in monitoring totals.
- Canonicalize exact IPv6 CIDR entries before comparison and exclude additional authorization header variants from public-cache forcing.
- Correct admin health indicator and do not double-encode the manual sync notice.
- Add self-contained regression tests and clarify operational limitations.


## 1.0.1 — 2026-10-01

- Fixed admin dashboard fatal `TypeError` when reading numeric HTTP-status counters (`429`, `403`) under `strict_types=1`. PHP converts numeric-string array keys to integers, so the dashboard now explicitly casts the counter key back to string before calling `Counter::stat_value()`.

## 1.0.0 — 2026-10-01

- Shared LiteSpeed public-cache architecture for anonymous traffic.
- LiteSpeed `CacheKeyModify` query normalization without redirecting tracking URLs.
- Redis/persistent-object-cache origin counters with fail-open behavior.
- IPv4/IPv6 bucketing and trusted Cloudflare proxy validation.
- Monitor, Force Cache, Safe Shared Cache, and Cache-Only policies.
- Origin, MISS, unique-URL, 404, search, REST, XML-RPC, and scanner-path controls.
- CIDR-specific policy overrides, allowlist, denylist, emergency mode, dry-run mode.
- Targeted editorial purge and regeneration allowance.
- WordPress admin health dashboard, diagnostics, and `.htaccess` rule synchronization.
