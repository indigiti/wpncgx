=== Newsroom Guard ===
Contributors: dcn
Tags: litespeed, cache, redis, rate-limit, news, bots
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.2.0-rc7
License: GPLv2 or later

Integrated newsroom publishing governance, audit and security controls, alongside LiteSpeed cache and origin protection. Editorial enforcement is optional and off by default.

== Core design ==
* LiteSpeed public cache remains the first line of defense. Cache HITs do not execute this plugin.
* Anonymous humans and crawlers use the same public cache.
* Safe tracking query parameters are dropped from the LiteSpeed cache key without browser redirects.
* Persistent object cache (typically Redis) stores rate counters; no live request counters are written to MySQL.
* If persistent object cache is unavailable, enforcement fails open instead of blocking visitors.
* IPv4 and IPv6 subnet bucketing, with IPv6 /64 by default.
* Cloudflare client IP is trusted only when REMOTE_ADDR belongs to an explicitly trusted Cloudflare CIDR.
* Origin threshold, protection duration, MISS budget, unique-URL budget, 404/search/REST limits, allowlist, denylist, and CIDR-specific policies.
* Targeted LiteSpeed purge hooks on editorial updates plus a one-use regeneration permission.
* Scanner path fast rejection and XML-RPC controls.
* Dry Run is ON by default.

== Important LiteSpeed behavior ==
LiteSpeed cache lookup happens before WordPress/PHP. For that reason, cache-key query normalization is written to .htaccess with LiteSpeed CacheKeyModify rules. The visible browser URL is not redirected or changed.

The plugin intentionally ships with a safe list of tracking parameters (utm*, gclid, fbclid, etc.). Arbitrary "ignore every possible query parameter only after WordPress has confirmed this is a post" cannot be safely implemented at PHP time because the cache key has already been looked up. Add additional site-specific content-invariant keys to the Ignored Query Keys setting.

== Recommended rollout ==
1. Install LiteSpeed Cache for WordPress and a persistent Redis object cache.
2. Install and activate Newsroom Guard.
3. Keep Dry Run enabled for initial observation.
4. Verify .htaccess is writable and use Settings > Newsroom Guard > Sync .htaccess.
5. Confirm a public article returns X-LiteSpeed-Cache: miss, then hit on refresh.
6. Test UTM URLs and confirm they reuse the same LiteSpeed cache object.
7. Review counters, then disable Dry Run to enforce limits.
8. Enable LiteSpeed Serve Stale at server/plugin level as appropriate for your environment.

== CIDR rule syntax ==
One rule per line:
CIDR|policy|threshold|window|duration|miss|unique|404

Example:
203.0.113.0/24|cache_only|5|60|300|2|3|2
2401:db8:1234::/48|monitor|20|60|300|5|10|5

Policy values:
monitor
force_cache (legacy only; normalized to safe_shared)
safe_shared
cache_only

More-specific CIDRs win.

== Security notes ==
* Do not force-cache authenticated, preview, nonce/action, Authorization, admin, POST, search, or REST responses into the public article cache.
* Keep Cloudflare CIDRs current if using Cloudflare real-IP restoration.
* Use Dry Run first.
* Test on staging before production deployment.

== Changelog ==

= 1.2.0-rc7 =
* Reuse settings, module options and safe cache-key patterns per PHP request, isolated across Multisite blogs.
* Replace duplicate post-status/save cache invalidation hooks with a single post-save-complete event.
* Add read-only cache environment diagnostics; no automatic Varnish/Cloudflare changes.

= 1.2.0-rc6 =
* Track real staff creator, editing operator and publisher independently of public WordPress Author.
* Add private Post History metabox and searchable Editorial Activity with module switch.
* No changes to theme, author byline or front-end URLs.

= 1.2.0-rc5 =
* Make maximum upload size (1 KB–20 MB) configurable under Newsroom Guard Module Manager.
* Allow optional JPG/JPEG, WebP, PNG, GIF and PDF with real MIME/content validation.
* Keep existing 500 KB JPEG/WebP defaults and independent upload policy OFF/ON control.
* Keep MediaFlow as a separate plugin; server-side PHP upload limits remain authoritative.


= 1.2.0-rc4 =
* Enable WordPress-native GitHub Releases update detection through the Update URI header.
* Add Stable/Testing channel selection and manual Check Now under Newsroom Guard Module Manager.
* Verify GitHub release ZIP SHA-256 and package identity before WordPress installation.
* Automatic installation remains controlled by WordPress, not enabled by this plugin.
* See GITHUB-UPDATES.md; one manual upgrade from pre-updater versions is required.


= 1.2.0-rc3 =
* Add optional strict JPEG/WebP uploads up to 500 KB, including WordPress sideload validation.
* Add optional administrator-only category creation without modifying saved Editor role capabilities.
* Add optional featured-image alt fallback for empty metadata with post and attachment permission checks.
* All policies OFF by default; document MediaFlow and newspaper PDF ingestion compatibility.

= 1.2.0-rc2 =
* Single Newsroom Guard administration interface, with advanced controls fully moved into the dashboard.
* Old Settings page and duplicate Module Manager shortcut redirect to the corresponding dashboard views.
* Keep trusted proxy, CIDR, scanner, XML-RPC, LiteSpeed and cache diagnostic controls available under Module Manager > Advanced Controls.
* Preserve existing options and editorial master OFF by default. Test on staging before enabling enforcement.


= 1.2.0-rc1 =
* Ten-section responsive WordPress administration workspace with accessible navigation and compact typography.
* Real audit/activity metrics, security reporting, bounded filtered CSV exports and paginated independent review queue.
* Independent runtime Bot/Worker/Reports toggles alongside existing editorial controls. MU search remains separate.
* Preserves legacy settings and LiteSpeed marker; requires Cloudways staging verification before production.


= 1.1.0-rc1 =
* Optional Editorial & Publishing settings tab (master OFF on upgrade).
* Independent Audit / Security / Approvals / Four-Hour Lock controls; exempt ID 529 affects lock only.
* Editor-to-Editor approval and protected live-article revisions with SEO field review.
* Real WordPress REST, cron, metadata and permissions integration checks in CI.
* Staging Cloudways/LiteSpeed validation required before production merge; see EDITORIAL-MODULE.md.


= 1.0.6 =
* Optional opt-in LiteSpeed `CacheIgnoreCacheControl On` for reuse of eligible cached articles despite client request no-cache headers. Default OFF, request-wide, not bot-specific; no response-side Force Public override.
* Updated staging probe for `Cache-Control: no-cache`/`Pragma: no-cache` and safety documentation.

= 1.0.5 =
* Integrate PM Worker Guard (Action Scheduler 5/batch, 1 concurrent batch, Rank Math 200 and Yoast 250 sitemap entries).
* Optional async runner shutdown only after WP-CLI cron verification; preserves existing more restrictive settings.

= 1.0.3 =
* Add standalone must-use search guard for 3 searches per fixed 60-second window; manual MU installation required.
* Make legacy search fallback 3/min and avoid double-counting MU-handled requests.
* Add tested Cloudflare IP trust handling and MU status dashboard.

= 1.0.2 =
* Restrict explicit Force Cache to public content without personalization cookies or unknown query parameters.
* Allowlisted clients no longer force public caching; safe-shared policies defer to native LiteSpeed exclusions.
* Reject disabled XML-RPC endpoints, including pingbacks, and rate-limit search before SQL execution.
* Prevent wildcard query-key exclusions from overlapping protected parameters; match LiteSpeed case-sensitivity in diagnostics.
* Avoid double counting REST dispatches and correct dry-run counters.

= 1.0.1 =
* Fix admin dashboard fatal TypeError caused by PHP converting numeric-string counter keys (429/403) to integers under strict_types.

= 1.0.0 =
* Initial production-oriented architecture.


== Performance hardening 1.0.5 ==
Removed unsafe forced-public-cache policy, failed open safely for invalid trusted-proxy headers and failed atomic Redis counters. Added manual atomic object cache test and staging-only cache/load probe scripts. See PRODUCTION-READINESS.md.
