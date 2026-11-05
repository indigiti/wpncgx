# Search Guard MU: three searches per minute

**Version 1.0.3.** This is a separate, early WordPress Must-Use (MU) plugin. The regular News Cache Guard plugin remains installable using the WordPress plugin uploader. The two modules cooperate; only the MU component counts eligible requests that it handles.

## Install (Cloudways / WordPress)

1. Install/upgrade the regular `news-cache-guard/` plugin and keep its general **Dry Run** on until other traffic policies are verified.
2. Confirm a **persistent, shared object cache** (Redis/Object Cache Pro or a compatible atomic backend) is actually operational, not merely configured. Multiple PHP workers must share it.
3. Using SFTP/SSH, create `wp-content/mu-plugins/` if absent. Copy `wp-content/plugins/news-cache-guard/mu-plugins/wp-search-guard.php` to **`wp-content/mu-plugins/wp-search-guard.php`**. Must-use PHP files are not automatically loaded from nested directories.
4. In WordPress, check **Plugins → Must-Use**, and **Settings → News Cache Guard**. The dashboard should report `Search Guard (3/60s): MU ACTIVE` and `Persistent Object Cache: ENABLED`.
5. Test from a logged-out client against **uncached origin requests**: run four searches within the same UTC minute. Requests 1–3 should work; request 4 must return `429` with a `Retry-After` header. The exact boundary can reset between calls; repeat if necessary.
6. Check actual visitor IP handling behind Cloudflare/Cloudways. `REMOTE_ADDR` should contain the actual visitor IP or a trusted Cloudflare edge address with a valid `CF-Connecting-IP`; untrusted `X-Forwarded-For`/`CF-Connecting-IP` values are ignored. Do not blindly trust arbitrary proxies.
7. To disable the strict MU guard, rename or remove `wp-content/mu-plugins/wp-search-guard.php`; the ordinary plugin's fallback search limiter remains active and obeys the ordinary plugin's Dry Run mode.

MU plugins do not use activation hooks, do not appear among ordinary plugins, and are not auto-updated by the ordinary plugin. Re-copy the updated MU file when upgrading it.

## Behavior

- Handles front-end `GET`/`HEAD` with `?s=...` and standard pretty search paths `/search/`, excluding WordPress REST, admin, login, XML-RPC, AJAX and cron routes. It does not protect custom rewrite search slugs, AJAX/REST search APIs, or cached responses that bypass WordPress entirely.
- Counts **three searches per client IPv4 or IPv6 `/64` network in fixed wall-clock 60-second windows**. The fourth and subsequent requests receive HTTP 429. `Retry-After` is the remaining time to the next window. A fixed window can allow bursts of up to six at the minute boundary; it does **not** enforce a strict rolling 60-second cap.
- Rejects `?s[]=...` and search strings longer than 160 bytes with HTTP 400.
- Uses atomic WordPress object-cache `add` and `incr`, with a hashed identity and window key. If no persistent object cache is available, or the increment fails, **fails open** and allows the request. A reported external object cache is not proof of atomicity or availability under load.
- Never writes a rate-limit counter to `wp_options` and does not trust arbitrary visitor-controlled IP headers.
- The MU 3-search limit **always enforces** when its prerequisites are met, even if the ordinary plugin is disabled or in Dry Run. It does **not** inherit the ordinary plugin's allowlist and may affect logged-in front-end searches, legitimate shared-NAT users and crawlers. Test before production deployment.
- The ordinary plugin retains a three-per-60-second fallback for search routes the MU does not handle or when the MU file is absent. This fallback observes ordinary plugin Dry Run and needs Redis to enforce.

## Important edge limitation

**WordPress-level limiting occurs after any Cloudflare/LiteSpeed cache lookup.** Requests served entirely from the page cache never reach this code. Configure search results as **uncached** in LiteSpeed Cache and Cloudflare, and use a Cloudflare/WAF edge rule if you need coverage for every inbound request, including cached responses.

## Local checks

```bash
find news-cache-guard -name '*.php' -print0 | xargs -0 -n 1 php -l
php news-cache-guard/tests/regression.php
php news-cache-guard/tests/mu-search-guard.php
```

Integration tests using actual WordPress, Redis and an origin behind Cloudflare remain necessary.
