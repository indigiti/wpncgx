# Newsroom Guard

Lightweight WordPress origin request protection and LiteSpeed cache-key normalization, designed for high-traffic news publishers. Version 1.0.6 incorporates an audit of the supplied 1.0.1 hotfix. WordPress 6.2+ and PHP 7.4+.

## Install

Upload a ZIP containing the `news-cache-guard/` directory using **Plugins → Add New → Upload Plugin**, then open the top-level **Newsroom Guard** menu. Keep Dry Run enabled until origin-limit thresholds are proven on staging. Read [INSTALL.md](INSTALL.md), [SECURITY.md](SECURITY.md), and [TESTING.md](TESTING.md) before enforcement.

## Early 3-per-minute search protection

This package includes the standalone `mu-plugins/wp-search-guard.php`. Copy that file to `wp-content/mu-plugins/wp-search-guard.php` using SSH/SFTP (the WordPress uploader **does not** enable a nested MU file). It enforces 3 searches per IP/IPv6 `/64` per fixed 60-second window, independently of Dry Run, when Redis is operational. The regular plugin skips duplicate counting and provides a three-search fallback where MU is absent. See [MU-SEARCH-GUARD.md](MU-SEARCH-GUARD.md).

## Operational caveats

The plugin runs after the LiteSpeed cache lookup and cannot rate-limit requests that never reach PHP. It uses the persistent WordPress object cache for counters; `wp_using_ext_object_cache()` alone is not a service-health check. Response-side Force Cache has been retired for privacy; Safe Shared Cache respects LiteSpeed exclusions. Request-side fresh-copy cache reuse is an explicit opt-in and is not the same as forcing a response into public cache.

## Validation

Run `php tests/regression.php` and `php tests/mu-search-guard.php` and `php tests/worker-guard.php` for self-contained logic checks and `find . -name '*.php' -print0 | xargs -0 -n 1 php -l` for syntax validation. These do not replace integration testing with WordPress + LiteSpeed + Redis on a staging deployment.

License: GPL-2.0-or-later.

## PM Worker Guard

Built-in queue and SEO sitemap workload caps are active with the regular plugin. View **Tools → PM Worker Guard** and see [WORKER-GUARD.md](WORKER-GUARD.md). Do not activate the previous standalone PM Worker Guard simultaneously.

## Performance hardening v1.0.5

See [PRODUCTION-READINESS.md](PRODUCTION-READINESS.md) for Cloudflare Free edge rules, cache-header probes, bounded staging load tests and the deployment acceptance checklist. Force Public Cache is retired; legacy policy values normalize to Safe Shared Cache. Atomic counters fail open when Redis increment fails; missing Cloudflare visitor IP also fails open to avoid blocking shared proxy traffic.

## Fresh-request cache defense (optional)

If bots repeatedly request `Cache-Control: no-cache`, the opt-in **Ignore client no-cache during LiteSpeed lookup** setting writes the LiteSpeed request-side `CacheIgnoreCacheControl On` directive. It is **OFF by default** and applies across the WordPress directory, not only to bots. It does not force public responses or bypass normal LSCWP privacy checks. Run the [fresh-request cache defense staging checklist](FRESH-CACHE-DEFENSE.md) before enabling.

## Editorial & Publishing (v1.1.0-rc1, staged behind master OFF)

The integrated **Newsroom Guard → Module Manager** provides an independent master switch and group toggles for Publishing Audit, Security Intelligence, Two-Person Approval and Post-Publication Lock. The Master switch is **OFF by default** on both fresh installs and upgrades; all LiteSpeed cache, Cloudflare, Redis, request protection and worker policies remain independent.

For an enabled approval workflow, Authors and Editors submit posts to a review queue. Editor-originated articles require approval by a different Editor. Published-article changes use pending revision proposals, while the four-hour post-publication lock blocks unauthorised direct edits and deletions. Administrator emergency publishing is audited. User ID 529 is exempt from **the lock only**, never from independent approval.

This is a **release candidate on a draft pull request, not an approved production release**. The GitHub integration job tests real WordPress APIs against an ephemeral MariaDB instance, but must not substitute for Cloudways staging, Gutenberg browser, cache-purge and proxy testing. See [EDITORIAL-MODULE.md](EDITORIAL-MODULE.md).

## Unified admin workspace (v1.2.0-rc2, staging release candidate)

The new top-level **Newsroom Guard** menu provides ten operational sections: Overview, Cache & Performance, Bot Protection, Worker Guard, Editorial Approvals, Publishing Audit, Security Intelligence, Post Lock, Reports and Module Manager. It displays real WordPress/Redis data with an audit activity chart, and supports nonce- and role-protected module settings and bounded CSV exports. Public news pages load no extra CSS or JavaScript. Former advanced origin/proxy, scanner, XML-RPC and cache controls now reside inside **Module Manager → Advanced Controls**. Old Settings and duplicate Module Manager bookmarks redirect to their unified equivalents. Dedicated Worker Guard Tools diagnostics remain accessible.

Review [ADMIN-WORKSPACE.md](ADMIN-WORKSPACE.md) for implemented features, scope limits, screenshot-inspired typography, module dependencies and staging acceptance. This implementation is in [draft PR #2](https://github.com/indigiti/wpncgx/pull/2) stacked on [editorial PR #1](https://github.com/indigiti/wpncgx/pull/1). Neither PR has been merged or validated on the actual Cloudways staging stack.

## Optional media and editorial policies (v1.2.0-rc3)

Newsroom Guard → **Module Manager → Media and content policies** now includes three independently switchable rules: **JPG/JPEG and WebP only (500 KB maximum)**, **Administrator-only category creation**, and **fill empty featured-image alt text from the article title**. All default OFF. The strict upload rule also blocks PDFs and PNG files; do not enable it before checking your epaper and MediaFlow workflows. See [MEDIA-POLICIES.md](MEDIA-POLICIES.md) for validation, permission, accessibility and compatibility details.

## GitHub release updates (v1.2.0-rc4)

Newsroom Guard now integrates with native **WordPress Plugins → Update now** for releases from [indigiti/wpncgx](https://github.com/indigiti/wpncgx/releases). Configure **Stable** (default) or **Testing** (includes prereleases) under **Newsroom Guard → Module Manager → GitHub Release Updates**. The updater validates the exact GitHub ZIP URL and SHA-256 checksum before installation. WordPress administrators control automatic updates in the normal Plugins screen; nothing auto-installs by default. Existing `1.2.0-rc3` installs must receive this new updater code once through DigiOps or a manual ZIP. See [GITHUB-UPDATES.md](GITHUB-UPDATES.md) for setup, verification and limitations.

## Configurable media upload policies (v1.2.0-rc5)

The upload guard now supports an administrator-defined **1–20,480 KB** size limit and allowed file types: **JPG/JPEG, WebP, PNG, GIF or PDF**. Defaults remain 500 KB and JPG/JPEG + WebP for existing installations. Its ON/OFF control remains separate, OFF by default. Configure under **Newsroom Guard → Module Manager → Configure file types & maximum size**. The uploaded MediaFlow 0.2.5 archive remains a separate image-delivery plugin, not bundled or installed with Newsroom Guard. See [MEDIA-POLICIES.md](MEDIA-POLICIES.md).

## Post History and Editorial Activity (v1.2.0-rc6; staging)

An independent, passive staff attribution log records the real WordPress account that creates, edits, publishes, or changes the public author of a news article. The existing theme and WordPress Author field are untouched. Editors with sufficient permissions see **Newsroom Guard — Post History** on each post; Administrators can search **Newsroom Guard → Editorial Activity**. Switch under **Module Manager**; approvals and locks are not enabled by this feature. See [POST-HISTORY.md](POST-HISTORY.md) for limitations, privacy and staging checks.

## Future work and module roadmap

The tracked [Newsroom Guard Future Work roadmap](FUTURE-WORK.md) covers release gates (M1), newsroom security and corrections (M2), article health (M3), editorial operations (M4), performance intelligence (M5), AI and distribution (M6), and multi-publication governance (M7). These are planned modules, **not completed features**. The current draft PR cannot merge until actual Cloudways staging acceptance passes.
