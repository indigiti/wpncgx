# Changelog

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
