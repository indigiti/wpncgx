# News Cache Guard for LiteSpeed

Lightweight WordPress origin request protection and LiteSpeed cache-key normalization, designed for high-traffic news publishers. The installable plugin lives in [`news-cache-guard/`](news-cache-guard/). Version 1.0.5 incorporates an audit of the supplied 1.0.1 hotfix. WordPress 6.2+ and PHP 7.4+.

## Install

Upload a ZIP containing the `news-cache-guard/` directory using **Plugins → Add New → Upload Plugin**, then visit **Settings → News Cache Guard**. Keep Dry Run enabled until origin-limit thresholds are proven on staging. Read [INSTALL.md](news-cache-guard/INSTALL.md), [SECURITY.md](news-cache-guard/SECURITY.md), and [TESTING.md](news-cache-guard/TESTING.md) before enforcement.

## Strict search protection

The optional early **Search Guard MU** component enforces **3 searches per visitor per fixed 60-second window** on `?s=` and standard `/search/` URLs. For strict enforcement, copy `news-cache-guard/mu-plugins/wp-search-guard.php` to `wp-content/mu-plugins/wp-search-guard.php` and verify Redis. Without the MU file, the ordinary plugin has a three-per-minute fallback subject to Dry Run. See [MU installation / risks](news-cache-guard/MU-SEARCH-GUARD.md).

## PM Worker Guard (integrated)

News Cache Guard now includes PM Worker Guard: Action Scheduler queue claims (default 5 actions/batch, 1 concurrent batch), Yoast sitemap cap (250 links) and Rank Math cap (200 links). Tools → PM Worker Guard shows effective thresholds and safe WP-CLI instructions. Async loopback processing remains enabled unless `PMWG_DISABLE_ASYNC_RUNNER` is explicitly set after a server cron is proven. See [WORKER-GUARD.md](news-cache-guard/WORKER-GUARD.md). Do not install the earlier standalone PM Worker Guard alongside this integration.

## Operational caveats

The plugin runs after the LiteSpeed cache lookup and cannot rate-limit requests that never reach PHP. It uses the persistent WordPress object cache for counters; `wp_using_ext_object_cache()` alone is not a service-health check. Force Cache has been retired for privacy; Safe Shared Cache respects LiteSpeed exclusions.

## Validation

Run `php news-cache-guard/tests/regression.php` and `php news-cache-guard/tests/mu-search-guard.php`, and `php news-cache-guard/tests/worker-guard.php` for self-contained logic checks and `find news-cache-guard -name '*.php' -print0 | xargs -0 -n 1 php -l` for syntax validation. These do not replace integration testing with WordPress + LiteSpeed + Redis on a staging deployment.

License: GPL-2.0-or-later.

## Production hardening release 1.0.5

[Production readiness checklist](news-cache-guard/PRODUCTION-READINESS.md) and safe staging scripts `news-cache-guard/tools/cache-probe.sh`, `news-cache-guard/tools/staging-load.js` are now included. No Cloudflare or Cloudways live configuration was modified. Force Cache was retired for privacy and object-cache/proxy failures fail open without blocking shared readers.
