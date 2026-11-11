# P2 Resource Efficiency — rc8 (staged on rc7)

This milestone targets **origin requests that execute WordPress**. It cannot reduce work performed outside WordPress by Cloudflare, Varnish or a server-side HTML cache, and it does not claim a verified CPU/RAM speedup.

## Completed source changes

1. **Atomic persistent-object-cache counters.** For existing keys, `wp_cache_incr()` replaces the previous unconditional `wp_cache_add()` + `wp_cache_incr()` pair. When no key exists, `wp_cache_add()` creates one. If another worker wins the ADD race, a second atomic INCR retries. On persistent-cache failure, return zero (fail open), never use non-atomic GET+SET. Applied consistently to origin/search/REST/miss/daily stat keys and the unique-URL count (not to the unique URL marker).
2. **Post History read optimization.** The most recent edit is normally present in the 20 newest events already loaded for the side panel. Reuse the first such event; otherwise run the original precise last-editor query to preserve long-history accuracy. Public authors, timeline ordering and append-only event writes are unchanged.
3. **Admin resource snapshot.** The existing Cache & Performance admin screen displays memory currently allocated, memory peak, elapsed time and WordPress query count **so far in that PHP administrator request**, alongside external object-cache detection. This adds no database queries, Redis calls or network requests. It is NOT a server-level graph, frontend benchmark or proof that Redis is healthy.

## Expected savings (hypotheses, NOT benchmark results)

- Existing hot rate counters: **one persistent object-cache operation per counter instead of two** (assuming a working atomic INCR implementation). New keys and concurrent ADD races can require more operations. Unique markers still require an ADD.
- Post History side panel: **one less SQL query** when the most recent edit/author-change is in the recent-event slice. Old or unusual posts still run the fallback query.
- Admin measurements show existing values only; they do not reduce CPU/RAM.

## Security and compatibility constraints

- No changes to bot thresholds, source identity, REST/404/search budgets, Redis namespace, TTL, fail-open contract, or IP allow/deny logic.
- No additional frontend PHP/JS/CSS, new cron, background job, IP logging or audit event deletion.
- No changes to theme, `post_author`, frontend URLs or publishing permissions.
- A failing atomic persistent-cache backend still fails open, as in rc6/rc7. For fail-closed edge security, prefer independently configured Cloudflare/WAF rules, not non-atomic WordPress cache logic.
- No server/CDN cache setup changed. Standard Cloudways stacks may have Varnish/Breeze rather than LiteSpeed. Validate the actual cache integration before modifying purge behavior.

## Test gates

1. Standalone `tests/counter-efficiency.php` checks hot/cold operation counts, concurrent-creation race, repeated unique URLs, persistent-cache failures and disabled object cache.
2. Existing `tests/regression.php` checks Search Guard, origin limits, API and atomic-failure behavior.
3. Existing WordPress/MariaDB `tests/wp-post-history.php` also asserts at most **three** Post History-specific SQL queries for the recent-edit side panel, preserving the editor/reporting attribution and permissions.
4. PHP syntax/lint on 7.4, 8.3 and 8.4 and all existing editorial, plugin updater and news publishing tests.
5. Benchmark against representative staging load: Redis `INFO commandstats`/provider-specific operation counters, p50/p95/p99 PHP time, process RSS/peak memory, MySQL queries/slow log, and cache HIT/MISS. Compare before and after with the same traffic profile, warm-up and concurrency. Never enable monitor-level Redis commands such as `MONITOR` indefinitely on production.

## Release sequencing

**Dependency: PR #8 (rc7 foundation) remains a draft, with required CI queued at last inspection.** This P2 feature is based on that rc7 branch. Merge/release rc7 only after required CI and Cloudways staging acceptance. Then rebase/retarget this rc8 branch to `main`, rerun the full gate, and publish a new tagged rc8 prerelease. Do not deploy a stacked draft branch or overwrite release tags.

## Follow-on optimization candidates

- Profile hot `get_users`, report-summary and audit listing queries against an archive-sized staging database before introducing indexes or schema migrations.
- Consider keyset pagination for long audit histories, but preserve administrator date/staff filters and stable ordering. Database migrations can lock high-volume production tables and must be separately scheduled.
- Measure Redis operations on a loaded origin. Any conditional early exit from expensive unique-URL accounting must retain the same 429/403 decisions and dry-run telemetry, with explicit abuse-traffic tests.
