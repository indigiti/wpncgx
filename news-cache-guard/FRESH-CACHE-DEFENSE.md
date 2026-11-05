# Fresh-request bot cache defense (optional) — v1.0.6

## Why the original Force Public Cache approach was not enough

Some crawlers and monitoring clients request fresh copies using `Cache-Control: no-cache` and/or `Pragma: no-cache`. LiteSpeed normally bypasses **lookup of an existing** cache object on these requests. A WordPress action such as `litespeed_control_force_public` is evaluated only **after the request has reached PHP**. It therefore cannot prevent the initial cache-lookup bypass, and it can override important privacy safeguards.

LiteSpeed documents the request-side directive `CacheIgnoreCacheControl On`, which tells the web server to *attempt* a cache lookup even when clients request a fresh copy. It does **not** make uncacheable WordPress responses publicly cacheable. See [LiteSpeed Cache Developer Guide](https://docs.litespeedtech.com/lscache/devguide/controls/#cacheignorecachecontrol) and [LSCache WordPress API](https://docs.litespeedtech.com/lscache/lscwp/api/).

## How the opt-in works

- **Default: OFF**, so upgrading does not change request semantics.
- In **Settings → News Cache Guard → Bot fresh-request cache defense**, enable *Ignore client no-cache request headers during LiteSpeed cache lookup*. Save and confirm the marked block was successfully synchronized into root `.htaccess`.
- The plugin writes `CacheIgnoreCacheControl On` inside `<IfModule LiteSpeed>` in its **News Cache Guard managed block**. It is independent of query-key collapse, and the block is removed when disabled and synchronized again.
- **Scope: all client requests in the WordPress `.htaccess` directory, not only bots or articles.** This is a web-server directive, so the PHP plugin cannot select individual verified crawlers on a cache HIT. Requests for private/uncacheable responses MUST still be excluded by LiteSpeed, Cloudflare, cookies and WordPress.
- It does **not** block bots, remove random content-changing query parameters, create missing cached articles, or ensure Cloudflare edge HTML caching. A bot that changes URLs still generates new cache keys unless the parameter is explicitly and safely normalized.
- It may affect a legitimate hard refresh: a browser-requested `Cache-Control: no-cache` may no longer force regeneration. Verify editorial purge behavior when stories are corrected.

## Staging acceptance test

1. Back up `.htaccess` and check any host-level Nginx/Varnish/Cloudflare cache overrides.
2. Verify anonymous published articles become `X-LiteSpeed-Cache: hit` on the second same-URL request.
3. Test `Cache-Control: no-cache` and `Pragma: no-cache` against the **same** article after the cache has been warmed. With a supported LiteSpeed server and the opt-in active, expect an origin HIT unless Cloudflare hides the origin result.
4. Confirm WordPress admin, login, REST, search, previews, commerce/cart, and all authenticated/personalized pages remain correctly uncached. Test with real cookies. Do not rely only on the WordPress plugin code.
5. Publish/update a staging article and verify its old cache entry is invalidated and its content refreshes. Test home, categories and sitemaps.
6. Confirm repeated access with non-content query strings (e.g. `utm_source`) reuses the correct entry; NEVER blindly drop `preview`, `s`, `rest_route`, unknown query parameters or nonces.
7. Read LiteSpeed and Cloudflare headers on actual crawler-like requests; distinguish an origin `X-LiteSpeed-Cache: hit` from Cloudflare `CF-Cache-Status: HIT`. Check the actual server supports this directive in `.htaccess`.

Low-volume command (staging by default):

```sh
bash news-cache-guard/tools/cache-probe.sh 'https://stage.example.com/a-published-article/' 'https://stage.example.com/?s=test'
```

For a site you control where the staging hostname does not contain `stage`, use `NCG_ALLOW_LIVE_PROBE=I_APPROVE_5_REQUESTS` **only after** assessing the production impact.

## Safety and rollback

If a logged-in or private page is cacheable, or the directive is unsupported, **turn the setting OFF and save** (then inspect `.htaccess` to confirm marker removal); purge any unintended public cache objects and investigate the underlying cache-exclusion rules. If WordPress cannot modify `.htaccess`, manually remove the marked line. No Cloudflare account configuration is performed by this feature.

**Recommendation:** Use this only if response headers and LiteSpeed logs confirm bots' no-cache request headers are causing bypasses. First diagnose why ordinary published articles are not cached, whether Cloudflare responds with `DYNAMIC/BYPASS`, and whether URL query variation or post-publish purge is generating cache MISS traffic.
