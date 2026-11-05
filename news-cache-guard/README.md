# News Cache Guard for LiteSpeed

Lightweight WordPress origin request protection and LiteSpeed cache-key normalization, designed for high-traffic news publishers. Version 1.0.2 incorporates an audit of the supplied 1.0.1 hotfix. WordPress 6.2+ and PHP 7.4+.

## Install

Upload a ZIP containing the `news-cache-guard/` directory using **Plugins → Add New → Upload Plugin**, then visit **Settings → News Cache Guard**. Keep Dry Run enabled until origin-limit thresholds are proven on staging. Read [INSTALL.md](INSTALL.md), [SECURITY.md](SECURITY.md), and [TESTING.md](TESTING.md) before enforcement.

## Operational caveats

The plugin runs after the LiteSpeed cache lookup and cannot rate-limit requests that never reach PHP. It uses the persistent WordPress object cache for counters; `wp_using_ext_object_cache()` alone is not a service-health check. The LiteSpeed Force Cache policy may bypass cache exclusions; keep Safe Shared Cache unless custom rendering and cookies have been audited.

## Validation

Run `php tests/regression.php` for self-contained logic checks and `find . -name '*.php' -print0 | xargs -0 -n 1 php -l` for syntax validation. These do not replace integration testing with WordPress + LiteSpeed + Redis on a staging deployment.

License: GPL-2.0-or-later.
