# Security and deployment notes

This plugin is designed to protect origin resources, not to replace a WAF or DDoS service.

1. Keep **Dry Run** enabled until real traffic has been observed.
2. Use a persistent Redis/object cache. Without it, enforcement intentionally fails open.
3. Confirm LiteSpeed public cache is functioning before enabling enforcement.
4. Keep trusted Cloudflare IP ranges current when Cloudflare real-IP restoration is enabled.
5. Review protected query parameters for site-specific preview, personalization, commerce, membership, language, or nonce parameters.
6. Do not use Force Cache for personalized pages.
7. Test publish/update workflows and analytics attribution on staging.

## Important architecture limitations

- These limits execute only when WordPress/PHP receives a request. LiteSpeed cache hits, static files, and requests rejected by the web server are outside this plugin's scope. Put high-volume flood controls at the edge (Cloudflare/WAF) or web server.
- `wp_using_ext_object_cache()` does not prove that a backend is shared, available or atomic. Verify persistence across PHP workers and check Redis connectivity independently before enabling enforcement.
- `Force Cache` overrides LiteSpeed's native exclusion rules and is inherently risky. Use only after verifying the site has no unlisted personalization mechanisms or session cookies; otherwise keep `Safe Shared Cache`.
- WordPress's `xmlrpc_enabled` filter alone does not disable unauthenticated pingbacks. Version 1.0.2 also rejects requests targeting `xmlrpc.php` when mode is Disable.
- Query-collapse rules are applied at the LiteSpeed layer to all matching requests; never add a key that changes page content. Test any custom wildcard thoroughly.
- The plugin cannot guarantee rate limits when PHP is reached through alternate entry points, or when the external object cache is unhealthy.

## Search Guard MU security boundary (v1.0.3)

`wp-search-guard.php` executes at `muplugins_loaded`. Do not use `is_user_logged_in()` here; WordPress pluggable authentication functions may not yet be available. This guard applies to eligible front-end searches whether or not the user is logged in. It trusts `CF-Connecting-IP` only when the socket's `REMOTE_ADDR` matches the stored trusted proxy CIDRs (Cloudflare defaults when unset), never any untrusted `X-Forwarded-For`. Keep Cloudflare ranges current and ensure Cloudways sets `REMOTE_ADDR` correctly behind additional proxies. Search counters require a persistent object cache whose `add` and `incr` are atomic across workers. The presence of an object-cache drop-in alone is insufficient proof. A fixed window is not a sliding-window upper bound, and origin PHP cannot protect requests served from an edge or page cache.
