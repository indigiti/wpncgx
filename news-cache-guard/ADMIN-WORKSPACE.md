# Newsroom Guard — Admin Workspace (PR #2)

## Scope and design

The WordPress plugin retains its existing directory (`news-cache-guard/`), options (`ncg_settings`, `ncg_editorial_settings`), LiteSpeed `.htaccess` marker, database tables, PHP 7.4+ compatibility and existing deployment workflow.

Version **1.2.0-rc2** consolidates the administration experience under one **Newsroom Guard** menu. The former Settings → Newsroom Guard and duplicate Module Manager URLs redirect to the appropriate integrated dashboard sections. All former advanced origin/proxy, scanner and cache-key controls, plus diagnostics, now live under **Module Manager → Advanced Controls**.

The visual system draws on the provided compact analytics reference: warm neutral canvas, rounded white cards, dense but spacious typography, subdued states and restrained rose accents. CSS is prefixed `nrg-*` and enqueued **only on the top-level Newsroom Guard admin screen**, not on public pages or the Gutenberg editor. No web fonts, external assets or JavaScript chart libraries are loaded.

## Ten administration sections

| Section | Implemented functions | Important limitations |
|---|---|---|
| Overview | Real Redis-origin requests, recent audit counts, open approvals, recent alerts, 14-day activity bars, health indicators and quick actions | Redis counters do not prove total origin traffic; no fake analytics |
| Cache & Performance | Existing cache state and query/caching/purge settings, server-side save with preserved existing fields and .htaccess sync | LiteSpeed and Cloudflare remain independent; any advanced no-cache reuse requires staging privacy testing |
| Bot Protection | Runtime module switch, origin protection/dry-run toggle, capped numeric limits and integrated Advanced Controls for CIDR/allowlist and XML-RPC | Early MU search guard runs independently and requires separate installation/management |
| Worker Guard | Runtime ON/OFF for Action Scheduler/sitemap caps, visible effective caps, persistent diagnostics page | Does not control PHP-FPM, server cron or worker process counts |
| Editorial Approvals | Existing review queue with 25-row server pagination, visible reviewer requirements, protected review snapshot/action forms | Depends on editorial master and staging verification; no AI reviews |
| Publishing Audit | Server-paginated search/filter by UTC date, actor and action; show evidence from actual indexed audit table | External tamper-evident storage remains future work |
| Security Intelligence | Filtered priority events and evidence | Full incident-assignment/escalation workflow remains a separate future milestone |
| Post Lock | Independent lock switch, lock duration, exempt user IDs and existing enforcement | Exemption is for timed lock only; must never bypass independent approval |
| Reports | Administrator-only, filterable CSV of up to 5,000 audit events, protected from spreadsheet formula injection | Bounded export, not a background archive job or cross-site report |
| Module Manager | Independent Bot/Worker/Reports switches and editorial Master/Audit/Security/Approvals/Lock controls; extra confirmation on enabling editorial master | Core LiteSpeed cache and independently installed MU plugin are not controlled by a fake global switch |

## Data and security

- CSV export uses `manage_options` and nonce checks; output fields are escaped to avoid spreadsheet formula execution.
- Admin forms use capability and CSRF checks; POST handlers update only specified settings and preserve other options.
- Editorial master is OFF by default. Enabling it from the unified Module Manager explicitly requires staging confirmation and audit-table availability.
- On disabling Worker Guard, existing dedicated diagnostics remain accessible even though its filters are no longer installed.
- Invalid date filters are discarded; all dynamic event queries are parameterized and capped.
- Workload queries only execute on the administrator's dashboard or report pages, not on a public article view.
- Existing publish, correction, scheduling and privileged-code limitations documented in `EDITORIAL-MODULE.md` still apply.

## Release gate / testing checklist

- [ ] PHP 7.4, 8.3 and 8.4 syntax/compatibility and existing News Cache Guard regression jobs
- [ ] New dashboard/module regression test, integration render of all ten WP admin tabs
- [ ] Test admin save + redirect + nonce errors; verify unrelated settings and proxy CIDRs remain byte-identical
- [ ] Test Worker and Bot module OFF then ON with a fresh WordPress request; confirm independent MU search guard behaviour
- [ ] Check small-screen layout, 200–400% zoom, keyboard-only navigation, focus indicators, screen-reader names and reduced-motion preference
- [ ] Verify CSV export filtering, 5,000-row limit, actor privacy, UTF-8 and formula injection cases
- [ ] Test real cached article HIT/MISS, purge correctness, public/authenticated cache privacy on Cloudways with LiteSpeed and configured CDN
- [ ] Verify all editorial roles, Gutenberg/Classic, cron, REST/CLI and existing third-party publishing integrations on staging
- [ ] Rollback trial with verified backup, then a review of operational counters/CPU/worker costs

**Do not merge either PR #1 or stacked PR #2 until all required staging gates pass.** Appearance alone is not sufficient evidence for a newsroom publishing-security release.
