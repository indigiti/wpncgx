# PM Worker Guard (integrated) — v1.0.4

News Cache Guard loads this module automatically; do **not** activate the old standalone PM Worker Guard alongside it. The module conservatively caps **Action Scheduler queue claims**, **Yoast** XML sitemap sizes, and **Rank Math** XML sitemap sizes. It does **not** set PHP-FPM `pm.max_children`, enforce a maximum number of PHP requests, or stop slow background jobs. A batch of five *slow* jobs can still exhaust a worker or database.

## Defaults (PHP 7.4+)

| Item | Default | `wp-config.php` constant |
| --- | ---: | --- |
| Action Scheduler actions claimed per web batch | 5 (clamp 1–25) | `PMWG_ACTION_SCHEDULER_BATCH_SIZE` |
| Action Scheduler concurrent batches | 1 (clamp 1–5) | `PMWG_ACTION_SCHEDULER_CONCURRENT_BATCHES` |
| Rank Math sitemap links | 200 (clamp 50–1000) | `PMWG_RANKMATH_LINKS_PER_SITEMAP` |
| Yoast sitemap links | 250 (clamp 50–1000) | `PMWG_YOAST_LINKS_PER_SITEMAP` |
| Disable Action Scheduler async loopback runner | **Off** (safe) | `PMWG_DISABLE_ASYNC_RUNNER` |
| Force Rank Math sitemap transient caching | **Off** (respects other filters) | `PMWG_FORCE_RANKMATH_SITEMAP_CACHE` |

The limits preserve more conservative values supplied by other plugins. Rank Math sitemap settings are capped at runtime on frontend/CLI and **not written to `wp_options`**. Missing or invalid `items_per_page` values are left intact to preserve Rank Math defaults. WordPress admin settings reads are not modified. Other plugins may apply a filter later than priority 999 and change the result; confirm effective behavior in staging.

## Recommended staging rollout

1. Back up your WordPress database and any modified cron configuration. Deactivate the **standalone** PM Worker Guard, if installed, to prevent overlapping filters.
2. Upgrade News Cache Guard v1.0.4, then visit **Tools → PM Worker Guard** to confirm the module is loaded. No separate MU plugin is needed for this part; the optional **Search Guard MU** continues independently.
3. Monitor **Tools → Scheduled Actions** (when available) and count pending, overdue, and failed tasks. Identify the slowest hooks and log their processing durations. Limiting batch claims may increase backlog, especially if numerous notifications or WooCommerce subscriptions are due.
4. Via Cloudways SSH, run the status-page WP-CLI command *manually* using the correct PHP/WP-CLI binary and path; confirm that actions complete and exit successfully. Use `wp --path=/path/to/wordpress action-scheduler run --batch-size=5 --batches=1` as a pattern.
5. Only **after** WP-CLI is proven, add a server cron once per minute. Ensure it executes as the intended web/CLI user and does not overlap; use `flock` if available or equivalent locking, and monitor failures. `--quiet` is appropriate only after validation, with independent stderr logging.
6. Only then, optionally add `define( 'PMWG_DISABLE_ASYNC_RUNNER', true );` to `wp-config.php`. This disables Action Scheduler asynchronous loopback dispatch; **WP-Cron and other mechanisms remain separate**. Never disable WP-Cron unless the entire site has a verified replacement cron.
7. Check the Yoast/Rank Math sitemap index and last sitemap pages, flush sitemap caches after changing caps, confirm current articles appear, and ensure the sitemaps are fresh. If a plugin intentionally disabled Rank Math sitemap caching for freshness, the default module preserves that decision.
8. Recheck server load, queue latency, and memory. The correct fix may be optimizing a specific slow Action Scheduler hook, reducing external request timeouts *for that hook*, or adding a queue supervisor rather than globally reducing timeouts.

## Optional wp-config.php configuration

```php
define( 'PMWG_ACTION_SCHEDULER_BATCH_SIZE', 5 );
define( 'PMWG_ACTION_SCHEDULER_CONCURRENT_BATCHES', 1 );
define( 'PMWG_RANKMATH_LINKS_PER_SITEMAP', 200 );
define( 'PMWG_YOAST_LINKS_PER_SITEMAP', 250 );
// Optional ONLY after server cron verified:
// define( 'PMWG_DISABLE_ASYNC_RUNNER', true );
// Optional ONLY if sitemap caching is known safe:
// define( 'PMWG_FORCE_RANKMATH_SITEMAP_CACHE', true );
```

## Rollback

Remove the async-disable constant to restore browser loopback dispatch and stop the external cron only once you have confirmed ordinary WP-Cron queue processing. Disable the News Cache Guard plugin to remove the worker and sitemap cap filters (this also removes its other origin/cache protection). Avoid relying on the sitemap cap to protect against cache-bypass crawler spikes; those need edge controls and/or origin profiling.

## Tests

```sh
php news-cache-guard/tests/worker-guard.php
php news-cache-guard/tests/worker-guard.php phase2
```

These are **logic tests** with WordPress stubs, not a substitute for staging integration with your installed Action Scheduler, Rank Math/Yoast, Cloudways PHP-FPM and Redis.
