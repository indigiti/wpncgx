
## v1.0.6 — request-side fresh-copy protection

To address aggressive crawlers requesting fresh copies, an OFF-by-default advanced LiteSpeed `.htaccess` option `CacheIgnoreCacheControl On` is available. This encourages use of already-eligible cached articles despite request no-cache headers, without restoring the unsafe Force Public response hooks. **It applies to all visitors**, not just bots, and requires site-level cache privacy and purge validation. See [FRESH-CACHE-DEFENSE.md](news-cache-guard/FRESH-CACHE-DEFENSE.md).
