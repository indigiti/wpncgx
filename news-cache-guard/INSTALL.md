# Installation / rollout

## Prerequisites

- WordPress 6.2+
- PHP 7.4+
- LiteSpeed Web Server / LiteSpeed Enterprise with LSCache enabled
- LiteSpeed Cache for WordPress
- Persistent object cache, preferably Redis
- Writable root `.htaccess` if you want the plugin to sync query-collapse rules automatically

## Recommended rollout

1. Back up the database and `.htaccess`.
2. Upload `news-cache-guard.zip` from **Plugins → Add New → Upload Plugin**.
3. Activate **News Cache Guard for LiteSpeed**.
4. Open **Settings → News Cache Guard**.
5. Leave **Dry Run** enabled.
6. Confirm **Persistent Object Cache = CONNECTED**.
7. Click **Sync .htaccess Now**.
8. Inspect the generated `# BEGIN News Cache Guard` block.
9. From a logged-out browser, request a published article twice. Expect a LiteSpeed MISS followed by HIT.
10. Request the same article with `?utm_source=facebook`, `?utm_source=google`, and `?fbclid=test`. Confirm these tracking parameters do not create separate LiteSpeed cache objects.
11. Confirm the visible browser URL still contains the tracking query string and analytics attribution still works.
12. Publish/update a test story and verify the affected URL is purged and rebuilt.
13. Review Dry Run counters during normal crawler traffic.
14. Disable Dry Run only after thresholds are validated.

## MU Search Guard installation (strict search enforcement)

The WordPress plugin ZIP also contains `mu-plugins/wp-search-guard.php` but **WordPress does not automatically install nested MU files**. Copy it manually from the plugin into `wp-content/mu-plugins/wp-search-guard.php` using Cloudways SFTP/SSH. Confirm **Settings → News Cache Guard → Search Guard (3/60s) = MU ACTIVE**. This strict limit is active even with the normal plugin's Dry Run ON, and requires an atomic persistent object cache. The regular plugin fallback remains Dry Run-aware. Full details and rollback instructions: [MU-SEARCH-GUARD.md](MU-SEARCH-GUARD.md).

## Recommended starting defaults

- Default policy: Safe Shared Cache (respects LSCWP's own cache exclusions)
- Origin threshold: 5 / 60 seconds
- Protection duration: 300 seconds
- Protected PHP-origin allowance (not a measured LiteSpeed MISS): 2 / 60 seconds
- Unique uncached URLs: 3 / 60 seconds
- 404 origin limit: 2 / 60 seconds
- IPv4 bucket: /32
- IPv6 bucket: /64
- Excess action: 429
- Query collapse: ON
- Dry Run: ON for rollout, OFF after validation (**MU Search Guard still enforces** if installed)

## LiteSpeed Serve Stale

Enable LiteSpeed Serve Stale in the server/LSCache configuration appropriate to your environment. This plugin does not attempt to emulate server-side stale delivery in PHP.

## Query-collapse scope

`CacheKeyModify` runs at the LiteSpeed layer before WordPress. The plugin therefore ships with a conservative list of content-invariant tracking keys and supports custom exact/prefix keys. It does **not** blindly remove every unknown query parameter, because WordPress has not yet established whether an unknown parameter changes content at cache-lookup time.
