# Acceptance tests

## 1. Basic public cache

- Logged-out request to a published article: first request should be MISS, later request HIT.
- Logged-in request must not be forced into the anonymous public-cache policy.
- POST, preview, Authorization, admin, search, and protected nonce/action requests must not be forced into the shared article cache.

## 2. Query collapse

Test the same published URL with:

- no query
- `?utm_source=facebook`
- `?utm_source=google`
- `?gclid=test`
- `?fbclid=test`

The browser URL must remain unchanged. On compatible LiteSpeed Enterprise versions, configured dropped keys should not create distinct cache keys.

Then test a protected parameter such as `?preview=1`; it must remain part of normal request handling and must not be treated as an ignored tracking key.

## 3. Redis fail-open

- With persistent object cache connected: dashboard should show CONNECTED and counters should increment.
- Temporarily disable the persistent object cache on staging: dashboard should show FAIL-OPEN and rate enforcement should not block public traffic.

## 4. IPv4/IPv6

Use the IP diagnostic tester to verify:

- IPv4 defaults to /32.
- IPv6 addresses sharing the first /64 normalize to the same bucket.
- More-specific CIDR policy rules override less-specific matching rules.

## 5. Cloudflare

- Through Cloudflare, verify `REMOTE_ADDR` is inside a trusted Cloudflare CIDR and `CF-Connecting-IP` becomes the client identity.
- Direct-to-origin test with spoofed `CF-Connecting-IP`: the header must be ignored if `REMOTE_ADDR` is not a trusted proxy.

## 6. Protection

Keep Dry Run ON first. Generate origin requests that exceed the threshold and confirm the dashboard increments `Would block` without affecting responses.

Then on staging disable Dry Run and verify excess protected origin requests receive 429 with `Retry-After`.

## 7. Editorial refresh

- Publish a new article.
- Update an existing article.
- Verify post URL, configured home/category/feed/sitemap URLs are purged through LiteSpeed hooks.
- Verify the first post-purge request gets one regeneration permission even if its source network is already protected.

## 8. Scanner paths

Request configured scanner paths such as `/.env` or `/.git/`.

- Dry Run ON: request is not rejected by News Cache Guard, but `Would block` increments.
- Dry Run OFF: configured 404/403 is returned before normal theme rendering.

## 9. MU Search Guard: strict 3-search control

- Copy `mu-plugins/wp-search-guard.php` to the **top level** of `wp-content/mu-plugins/`; confirm the status card reports MU ACTIVE.
- With Redis functional, make four *uncached* anonymous searches in one fixed UTC 60-second window. The fourth request must receive HTTP 429 with a positive `Retry-After`, `Cache-Control: no-store`, and a plain-text response.
- Repeat for standard pretty `/search/` URLs and confirm `?s[]=` / >160-byte inputs return HTTP 400.
- Check that MU-handled requests are not incremented again by the ordinary plugin at `plugins_loaded` / `template_redirect`.
- Verify two distinct IPv4 clients have separate budgets, while two IPv6 addresses in a /64 share the budget.
- Test with a genuine trusted Cloudflare edge and with a spoofed `CF-Connecting-IP` header from an untrusted source; verify only trusted edge addresses can supply visitor identity.
- Test when Redis is disabled: fail-open is intended and must be visible to operators.
- Turn the normal plugin's Dry Run ON while MU is active: MU must **still** reject the fourth search. Test rollback by removing the MU file.
- Repeat with LiteSpeed and Cloudflare search-page caching configurations; requests served without PHP cannot be counted.

Local logic checks: `php tests/mu-search-guard.php`.
