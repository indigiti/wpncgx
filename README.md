# Newsroom Guard

**WordPress newsroom operations, editorial security and LiteSpeed origin protection.** This product was previously displayed as *News Cache Guard for LiteSpeed*. The existing WordPress plugin directory (`news-cache-guard`), settings keys, text domain and managed `.htaccess` markers intentionally remain unchanged for compatibility.

The editorial/security modules are currently in [draft PR #1](https://github.com/indigiti/wpncgx/pull/1). The editorial master switch is OFF by default. Do not merge or enable enforcement on production before Cloudways staging acceptance. See [plugin instructions](news-cache-guard/README.md) and [future roadmap](news-cache-guard/FUTURE-WORK.md).


## v1.0.6 — request-side fresh-copy protection

To address aggressive crawlers requesting fresh copies, an OFF-by-default advanced LiteSpeed `.htaccess` option `CacheIgnoreCacheControl On` is available. This encourages use of already-eligible cached articles despite request no-cache headers, without restoring the unsafe Force Public response hooks. **It applies to all visitors**, not just bots, and requires site-level cache privacy and purge validation. See [FRESH-CACHE-DEFENSE.md](news-cache-guard/FRESH-CACHE-DEFENSE.md).
