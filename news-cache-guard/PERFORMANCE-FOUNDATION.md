# Newsroom Guard rc7 — Performance & Resource Foundation

## Delivery scope

This is a **source-code optimization milestone**, not a claim that your Cloudways server now uses less CPU/RAM at an audited traffic load.

Implemented in v1.2.0-rc7:
1. **Request-local configuration caching** for core settings, module switches and sanitized cache-key patterns. WordPress option-update hooks invalidate caches; Multisite reads are isolated by blog ID. No persistent cache keys or Redis operations are introduced.
2. **Single-pass cache invalidation:** Purger listens to wp_after_insert_post only rather than both transition_post_status and save_post. The callback runs after WordPress persists post terms and metadata. It still purges on first publication, published edits, unpublishing and direct wp_publish_post() scheduled publishing. Identical URLs in one purge set are deduplicated.
3. **Read-only cache environment hints:** the admin-only Cache & Performance page describes PHP server header hints and the presence of Breeze/LiteSpeed integrations. It does not prove an actual cache HIT and does not call remote APIs, change Varnish settings, introduce page cache or automatically purge Cloudflare.
4. **Regression gates:** tests/performance.php plus real WordPress/MariaDB tests/wp-performance.php exercise option invalidation and publishing transitions.

## Explicitly NOT in rc7

- No assumption that Cloudways Varnish, LiteSpeed or Cloudflare is actually serving full-page HTML.
- No new edge cache rule, global purge, automatic Cloudflare purge or HTTP request from public views.
- No change to public theme, WordPress author attribution, editorial approval policy or existing Post History evidence.
- No promise of 99% cache HIT, zero RAM/CPU or specific speedup. Only benchmarks from the actual staging stack can prove improvements.
- No changes to Redis counter thresholds or atomic fail-open semantics; request-side security gates must not be weakened for speed.

## Cache backend matrix

| Signal in admin | What it proves | What it does not prove |
|---|---|---|
| SERVER_SOFTWARE mentions nginx, Apache or LiteSpeed | Server identification available to PHP | Actual HTML page cache or cache HIT |
| LiteSpeed integration signal | WordPress hook or plugin signal | LiteSpeed HTML cache is active or correctly purging |
| Breeze listed as active | Breeze plugin appears in active plugin options | Varnish is enabled or invalidation is configured |
| Cloudflare and Varnish marked unverified | No unsafe inference from PHP alone | Not evidence either cache is absent |

Cloudways standard WordPress stacks may use Breeze with Varnish. Preserve Breeze's existing Varnish auto-purge settings and avoid competing cache plugins and overlapping purge systems. Verify the real hosting stack before developing additional drivers.

## Performance baseline and acceptance

Before enabling rc7 on a live newsroom:
- Capture representative staging before/after samples for **PHP-FPM CPU %, RSS/process and peak memory, request execution times (p50/p95/p99), MySQL query counts and slow queries, Redis ops/request, cache HIT/MISS ratios, HTTP 429/5xx and background queue backlog**.
- Use identical content, configuration, cache warming protocol and worker concurrency. Distinguish warm-page cache results from uncached PHP and remote CDN hits. Run repeated trials.
- Replay published article, correction, title change, media update, category reassignment, publish/unpublish, draft, scheduled cron publish, WP REST, Classic/Gutenberg and Multisite scenarios.
- Verify the public reporter/byline stays unchanged, the Post History events are still written and no stale page is served for correction-sensitive URLs.
- Test Redis outage, delayed MySQL and unavailable cache plugin. Existing fail-open behavior must remain.

### Merge/deployment gate

1. PHP lint on 7.4, 8.3 and 8.4; standalone regression and WordPress/MariaDB checks pass.
2. Single-pass purge tests pass with direct wp_publish_post(), publish, edit and unpublish.
3. Cloudways staging verifies the real web cache, WP editor workflow and no increased staleness before production rollout.
4. Version/tag/ZIP manifest must use unique v1.2.0-rc7 identity. Never move a published release tag.

Rollback: install preceding verified v1.2.0-rc6 ZIP using WordPress/DigiOps. Settings and audit tables are unchanged by rc7. Check the cache layer again after rollback.

## Subsequent milestones

- **P1.1 hosting-aware integration**: verify actual Cloudways cache first. Prefer existing Breeze/hosting integration instead of adding competing Varnish/Cloudflare purge calls. Test correction propagation across layers.
- **P2 Redis/MySQL**: use profiles to prioritize hot-path Redis requests, audit event queries and expensive admin reports without breaking security or retention.
- **P3 Workers/CDN**: only after queue monitoring, cron validation and CDN HTML eligibility audit; never cache logged-in/private/preview pages at edge.
