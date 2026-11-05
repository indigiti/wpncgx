# News Cache Guard for LiteSpeed

Lightweight WordPress origin request protection and LiteSpeed cache-key normalization, designed for high-traffic news publishers. Version 1.0.5 incorporates an audit of the supplied 1.0.1 hotfix. WordPress 6.2+ and PHP 7.4+.

## Install

Upload a ZIP containing the `news-cache-guard/` directory using **Plugins → Add New → Upload Plugin**, then visit **Settings → News Cache Guard**. Keep Dry Run enabled until origin-limit thresholds are proven on staging. Read [INSTALL.md](INSTALL.md), [SECURITY.md](SECURITY.md), and [TESTING.md](TESTING.md) before enforcement.

## Early 3-per-minute search protection

This package includes the standalone `mu-plugins/wp-search-guard.php`. Copy that file to `wp-content/mu-plugins/wp-search-guard.php` using SSH/SFTP (the WordPress uploader **does not** enable a nested MU file). It enforces 3 searches per IP/IPv6 `/64` per fixed 60-second window, independently of Dry Run, when Redis is operational. The regular plugin skips duplicate counting and provides a three-search fallback where MU is absent. See [MU-SEARCH-GUARD.md](MU-SEARCH-GUARD.md).

## Operational caveats

The plugin runs after the LiteSpeed cache lookup and cannot rate-limit requests that never reach PHP. It uses the persistent WordPress object cache for counters; `wp_using_ext_object_cache()` alone is not a service-health check. Force Cache has been retired for privacy; Safe Shared Cache respects LiteSpeed exclusions.

## Validation

Run `php tests/regression.php` and `php tests/mu-search-guard.php` and `php tests/worker-guard.php` for self-contained logic checks and `find . -name '*.php' -print0 | xargs -0 -n 1 php -l` for syntax validation. These do not replace integration testing with WordPress + LiteSpeed + Redis on a staging deployment.

License: GPL-2.0-or-later.

## PM Worker Guard

Built-in queue and SEO sitemap workload caps are active with the regular plugin. View **Tools → PM Worker Guard** and see [WORKER-GUARD.md](WORKER-GUARD.md). Do not activate the previous standalone PM Worker Guard simultaneously.

## Performance hardening v1.0.5

See [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) for Cloudflare Free edge rules, cache-header probes, bounded staging load tests and the deployment acceptance checklist. Force Public Cache is retired; legacy policy values normalize to Safe Shared Cache. Atomic counters fail open when Redis increment fails; missing Cloudflare visitor IP also fails open to avoid blocking shared proxy traffic.
