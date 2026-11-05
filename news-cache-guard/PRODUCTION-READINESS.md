# v1.0.5 — performance and crawler-safe deployment checks

## What this release changes

- **Safer shared cache:** the optional Force Cache policy is retired. Existing saved `force_cache` and CIDR `force_cache` rules are normalized to `safe_shared`, avoiding forced public caching of personalized pages. LSCache remains responsible for public cache eligibility.
- **Correct proxy identities:** when `REMOTE_ADDR` belongs to a configured trusted Cloudflare network but `CF-Connecting-IP` is absent/invalid, rate limiting fails open rather than placing unrelated visitors in one Cloudflare IP bucket. Configure the Cloudways/Cloudflare proxy chain correctly; private/loopback proxy hops also fail open rather than becoming shared visitor buckets. See `SECURITY.md`.
- **Reliable failure semantics:** requests are allowed if the external object cache cannot perform an atomic increment. The plugin does not emulate an atomic Redis counter with racy `get`/`set` writes.
- **Operator diagnostic:** **Settings → News Cache Guard → Test Atomic Object Cache** runs a manual, temporary key add/incr/read and reports success/failure. This is a one-request smoke test, **not** a cross-process Redis health proof.
- **Repeatable staging probes:** script `tools/cache-probe.sh` checks anonymous article caching and search no-cache behavior. Optional k6 script `tools/staging-load.js` runs bounded traffic against URLs containing `stage` or `staging` only.

## Production acceptance checklist (must all pass)

1. **Exclusions:** logged-in dashboards, preview URLs, password-protected posts, sessions, cart/checkout, nonce-bearing URLs, search and REST are never publicly cached. Ensure the actual LSCache server rules implement these exclusions. Unit tests cannot prove server caching behavior.
2. **Anonymous article:** use `bash tools/cache-probe.sh 'https://stage.example.com/article/' 'https://stage.example.com/?s=test'` and require anonymous public pages to show `X-LiteSpeed-Cache: miss` then `hit` (or document an intervening Cloudflare/QUIC.cloud HIT). If all requests return `CF-Cache-Status: HIT`, origin HIT rate is **not** measured.
3. **Search:** confirm 4 same-client uncached searches in one fixed UTC minute return `200, 200, 200, 429` with `Retry-After` *only when the MU plugin is installed and the atomic object cache works*. Search pages must not be served as public LSCache/Cloudflare hits. Time-window boundaries may require repeating the test.
4. **Known crawlers:** use **Cloudflare verified-bot classification**, `cf.client.bot`, for edge exceptions, not spoofable user-agent substrings in PHP. Verify Google Search Console URL Inspection and Bing Webmaster Tools plus access logs. Decide separately which AI crawlers to permit for discoverability.
5. **Origin bypass:** ensure the origin is reachable only through authorized Cloudflare/Cloudways routes where infrastructure permits; deny untrusted direct-to-origin requests. Ensure preview/cron/webhook integrations still reach origin.
6. **Publishing:** create/update a staging story and check permalink, homepage, categories, feed and sitemap invalidation. Validate invalidation does not purge the entire site or cause a cold-cache thundering herd.
7. **Availability:** verify Redis atomic checks, PHP-FPM active/idle/max-children telemetry in Cloudways, DB slow-query log and Action Scheduler queue backlog. The plugin does not measure real PHP-FPM concurrency.
8. **Load:** use bounded `k6 run tools/staging-load.js -e NCG_STAGING_URL=https://stage.example.com/article/ -e NCG_LOAD_APPROVED=STAGING_ONLY` with the staging site owner's permission. Define an agreed latency/error target for real hardware; report p50/p95, HTTP error rate, cache HIT/MISS and server load *before* production cutover.
9. **Enforcement:** review 24–48 hours of Dry Run counts and adjust origin thresholds; the original `5 / 60s` is very restrictive and can challenge ordinary visitors at cold origin. Turn enforcement on only after measuring false positives. MU search enforcement is independent of Dry Run.

## Cloudflare Free setup (manual, no API keys needed)

- Free allows **five custom WAF rules** in total; availability of query-string rate-limiting dimensions depends on the specific plan. Do not copy Enterprise bot-score rules into Free.
- If any later custom rules might challenge known crawlers, place an optional first rule matching `(cf.client.bot)` with **Skip → remaining custom rules**. If every challenge already includes `not cf.client.bot`, this separate Skip rule is unnecessary. Skip does not override Free's non-customizable Bot Fight Mode.
- Add one narrowly scoped **Managed Challenge** for suspicious non-bot scanner routes (not all article reads or all WordPress searches). For example:

  ```text
  not cf.client.bot and
  (
    http.request.uri.path contains "/.git/" or
    http.request.uri.path contains "/.env" or
    http.request.uri.path contains "/vendor/phpunit/"
  )
  ```

  Confirm Cloudflare accepts the expression in your actual account. Avoid challenging publication assets, publisher APIs, WP-Cron, sitemap files and legitimate verified bots.
- Evaluate Bot Fight Mode in **Security Events**: on Free it is a global switch without per-path exceptions; disable it if it breaks trusted automation. For AI discovery, do not enable blanket **Block AI Bots** unless that is your explicit content policy.
- Cloudflare Free cannot provide Enterprise Bot Management `cf.bot_management.score` or `cf.bot_management.verified_bot`. Use `cf.client.bot` for known crawlers if available in the rule editor. A crawler can spoof a `User-Agent`, but cannot spoof Cloudflare's server-side classification.
- Cloudflare WAF and rate-limiting configuration must be reviewed and applied in the actual Cloudflare account; nothing in this repository changes it automatically.

## Limits of this milestone

We cannot verify production caching, crawler classification, Redis cross-worker reliability or PHP-FPM performance from GitHub CI. The staging probes provide reproducible evidence only after the site owner runs them. A cache HIT reduces server pressure but does **not** stop a scraper from copying publicly available articles. Protect sensitive or licensed content separately.

## Rollback

Reinstall the prior v1.0.4 plugin ZIP if needed; be aware it contains the removed Force Cache capability. Restore previous `ncg_settings` after verifying privacy. Do not use old Force Cache settings on personalized pages. The MU search file in `wp-content/mu-plugins/` is separate and must be removed/renamed independently for rollback.

## v1.0.6: client-request cache bypass diagnostics

If the origin shows repeated misses when crawlers send `Cache-Control: no-cache` or `Pragma: no-cache`, investigate the [opt-in fresh-request cache defense](FRESH-CACHE-DEFENSE.md). `CacheIgnoreCacheControl On` influences LiteSpeed lookup **before PHP runs** and may reuse an existing eligible public article response. Do not assume a response-side `force_public` hook solves request-side cache bypass. Verify auth/private/search exclusion, editorial purges, query key variation and the origin headers before activation.
