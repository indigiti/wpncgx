# Change history

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
