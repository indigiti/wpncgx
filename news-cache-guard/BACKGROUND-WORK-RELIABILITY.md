# P3 — Background Work Reliability (v1.2.0-rc9, stacked draft)

## Scope and motivation

On a low-resource WordPress newsroom, limiting Action Scheduler batch size is not sufficient: if job execution is stalled, scheduled stories, notifications and other business workflows can accumulate indefinitely. This milestone provides **low-overhead visibility**, not auto-tuning or a new job system.

Access: **WordPress Admin → Tools → PM Worker Guard**. The screen is accessible only to administrators with `manage_options`. Existing **Newsroom Guard → Worker Guard** links to it.

### Implemented

- Show whether WordPress page-triggered cron is disabled via `DISABLE_WP_CRON`. This is not evidence that external cron exists.
- Show whether Newsroom Guard has explicitly disabled Action Scheduler's asynchronous HTTP loopback runner.
- Show `wp_next_scheduled` timestamps for the known `action_scheduler_run_queue` and `ncg_editorial_cleanup` hooks. Absence of one of those hooks is **not proof** that no server cron or alternative runner exists.
- If Action Scheduler is initialized, issue **one** oldest-pending query: `as_get_scheduled_actions(['status'=>'pending','per_page'=>1,'orderby'=>'date','order'=>'ASC'],'ids')`, followed by one store date lookup for the returned ID. Do not count the entire queue, enumerate logs, fetch action payloads, claim tasks or change schedules.
- Mark a pending action 5 minutes or more overdue as a **potential** delay; it may still have a valid worker or be held by another plugin. Future actions and an empty queue are distinguished. Failures only mark the diagnostic result unknown.
- Warn when both page-triggered WP-Cron and the optional Action Scheduler async loopback runner are disabled, requiring **independent** validation of server-side runners.

### Performance and security constraints

- Diagnostics class is only included on admin requests. Snapshot is taken only when an authorised administrator opens the Worker Guard page. **No frontend polling, database table scan, scheduled monitor, transient, external request, or new Redis operation**.
- No changes to existing rate limits, cron event creation, PHP-FPM worker counts, server cron, Action Scheduler execution caps (default 5 per batch, 1 concurrent batch), sitemap settings, cache, theme, public author attribution or Post History.
- The admin screen is a point-in-time read-only observation; it **cannot prove** Cloudways cron runs or measure CPU, RSS or Redis usage. Check server logs and WP-CLI to confirm execution.
- An Action Scheduler installation using a custom store is queried through its own official API; if unavailable or malformed, diagnostics report unknown without crashing the admin page.
- Supported PHP: 7.4+, WordPress: 6.2+. Read-only probes run using standard WordPress API and documented Action Scheduler API where available.

## Safe Cloudways verification procedure

1. Back up the database and document existing Cloudways cron and WP-Cron settings.
2. Inspect **Tools → PM Worker Guard**; do not act on a single overdue snapshot as a reason to disable or purge jobs.
3. Independently verify jobs using `wp --path=/absolute/wordpress/path cron event list` and, when Action Scheduler WP-CLI is installed, `wp --path=/absolute/wordpress/path action-scheduler run --batch-size=5 --batches=1` on staging, not on a live newsroom as a blind command.
4. Publish a **scheduled test article** on staging and verify its intended public byline, publishing timestamps, cache freshness and Post History integrity.
5. If server cron is already configured, verify actual command invocation, exit codes, timezone, locking and stderr logs. Do **not** disable WP-Cron or Action Scheduler loopbacks until the replacement has been demonstrated.
6. Compare origin CPU/RAM and queue age before and after an equivalent workload. The diagnostic screen itself is not a benchmark.

## Automated test gates

- Standalone `tests/worker-diagnostics.php` modes: unavailable, future, overdue, empty, error, both-off. Assert at most one oldest action ID query and **zero** scheduling actions.
- Existing `tests/worker-guard.php` tests verify the admin page remains available and the legacy worker caps remain unchanged.
- Real WordPress/MariaDB `tests/wp-worker-diagnostics.php`: zero SQL writes and no change to WP-Cron option while collecting a snapshot, admin page renders successfully.
- Existing PHP 7.4/8.3/8.4, publishing, media, history, cache and updater regressions must all pass on the final head commit.

## Release dependencies

**rc7 PR #8 and rc8 PR #9 remain draft/unmerged at milestone start, with CI queued.** P3 branch and PR are stacked on rc8. Do not merge this P3 branch directly into main or publish an rc9 GitHub release until both prerequisites pass and Cloudways staging validates the actual cache, scheduled-publication flow and worker runtime. After rc8 is merged, retarget P3 to main and rerun all checks.

No Cloudways server setting or live WordPress plugin was changed by this development commit.
