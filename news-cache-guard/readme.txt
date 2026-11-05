=== News Cache Guard for LiteSpeed ===
Contributors: dcn
Tags: litespeed, cache, redis, rate-limit, news, bots
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later

Origin protection and shared LiteSpeed cache controls for high-traffic WordPress news sites.

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
2. Install and activate News Cache Guard.
3. Keep Dry Run enabled for initial observation.
4. Verify .htaccess is writable and use Settings > News Cache Guard > Sync .htaccess.
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
force_cache
safe_shared
cache_only

More-specific CIDRs win.

== Security notes ==
* Do not force-cache authenticated, preview, nonce/action, Authorization, admin, POST, search, or REST responses into the public article cache.
* Keep Cloudflare CIDRs current if using Cloudflare real-IP restoration.
* Use Dry Run first.
* Test on staging before production deployment.

== Changelog ==
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
