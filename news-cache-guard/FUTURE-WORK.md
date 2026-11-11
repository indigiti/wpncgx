# Newsroom Guard — Future Work / Product Roadmap

> **Status:** Planning only. None of the items below should be represented as implemented merely because they appear here.
>
> **Product:** One installable WordPress plugin, currently in the `news-cache-guard/` package (display name: **Newsroom Guard**, formerly News Cache Guard). Keep the existing plugin directory, options and `ncg_*` identifiers until a deliberately tested migration is approved.
>
> **Current release baseline:** News Cache Guard v1.0.6 on `main`. Editorial Audit / Security / Approval / Post Lock are being developed in [draft PR #1](https://github.com/indigiti/wpncgx/pull/1). A responsive ten-section admin workspace and independent Bot/Worker/Reports switches are under [draft PR #2](https://github.com/indigiti/wpncgx/pull/2), stacked on PR #1. Both PRs remain unmerged pending Cloudways staging, and the editorial master remains OFF by default. Automated CI is not a substitute for actual Cloudways staging acceptance. Do **not** merge, deploy enforcement or mark the release complete before the specified staging gates pass.

## Roadmap principles

1. **Reliability before feature count.** Every new enforcement feature must fail safely, be reversible and be validated on a representative WordPress + Cloudways + LiteSpeed + Redis stack.
2. **One plugin, isolated modules.** Independent admin ON/OFF controls, versioned settings, explicit capability checks, no duplicate hooks, no surprising changes to existing cache or worker protection. Security-critical audit must remain independent of optional UI and AI.
3. **Preserve ownership boundaries.** LiteSpeed/Cloudflare serve cache; Yoast/Rank Math own their SEO settings; WordPress owns content and role APIs. Newsroom Guard monitors, coordinates and audits rather than replacing these systems.
4. **Protect publication integrity.** Never expose unapproved content; no self-approval; bind approvals to immutable revision fingerprints; distinguish original submitter, creator, approver and current post author.
5. **Keep frontend overhead near zero.** No frontend dashboards/scripts. Use indexed audit tables, bounded/paginated queries, async jobs and rate-limited health checks. No expensive database writes on public cacheable GETs.
6. **Evidence over assumptions.** Record acceptance tests, measured impacts and source provenance. Treat AI suggestions as advisory and require editor authorization for consequential actions.
7. **Module-level rollbacks.** Turning a module OFF should stop only that module; preserve logs and pending reviews. No automated mass-publishing of previously pending posts.

## Current release gate (M1 — highest priority)

| ID | Release-critical work | Exit criterion | Status |
|---|---|---|---|
| M1-01 | Deploy PR #1 to an isolated representative Cloudways staging installation | Recorded backup and rollback; no production changes | **Pending staging** |
| M1-02 | Verify Contributor / Author / Editor / Admin journeys in Gutenberg and Classic Editor | No prohibited direct publication or self-approval; accurate UI and permissions | **Pending staging** |
| M1-03 | Validate REST, WP-CLI, XML-RPC, imports, quick/bulk edit, cron, media, SEO meta, terms and custom integrations | No unauthorised live mutation via supported routes; document privileged-code trust boundary | **Pending staging** |
| M1-04 | Verify published correction and revision approval with cache invalidation | Review snapshot matches published revision; correct cache purge; no private page caching | **Pending staging** |
| M1-05 | Verify cache, Redis, MU Search Guard, Worker Guard, .htaccess and Cloudflare behaviour against baseline | No protection regression, accidental force-public cache, or measurable origin overload | **Pending staging** |
| M1-06 | Check audit integrity, actor/source/IP trust, first-publish timestamp and original-creator tracking | Accurate traceability; no privilege or first-publish metadata forgery | **Pending staging** |
| M1-07 | Resolve known direct `wp_publish_post()`/direct SQL and other privileged-path exposure risks | Explicitly verified or isolated; never rely on post-transition rollback as the security boundary | **Open risk** |
| M1-08 | Full CI, security review, staging sign-off and merge | CI green **and** M1-01–07 satisfied; then merge PR #1; master remains OFF until per-site approval | **Blocked on staging** |

**No auto-merge.** A green GitHub workflow does not prove browser behaviour or live cache safety.

## Future milestones (not started)

### M2 — Newsroom security, evidence and corrections (P0/P1)

| ID | Module / feature | Requirements and acceptance criteria |
|---|---|---|
| M2-01 | **Security Incident Center** | Triage alerts, link actor/IP/post/revision/source, deduplicate noisy events, acknowledge/assign/escalate, maintain immutable incident notes and clear remediation history |
| M2-02 | **Tamper-evident audit export** | Signed/hashed export batches to external controlled storage, retention/erasure policy and reproducible integrity verification (local DB entries alone are not tamper-proof) |
| M2-03 | **Correction and Retraction Manager** | Controlled proposal, independent approval, visible correction/retraction notice, original text snapshot, reason, time, updater/approver and proper cache purge |
| M2-04 | **Integration & publishing service-account governance** | Inventory API tokens/importers/cron/CLI; scoped integrations, source attribution, expiry/rotation, alerts and deny-by-default publishing |
| M2-05 | **Administrator emergency override control** | Require reason and explicit confirmation, independent alert and durable audit evidence; document that plugin admins can deactivate code |
| M2-06 | **Access and role intelligence** | Privilege-change watch, stale account review, unusual login/IP correlations; distinguish shared proxy IP from verified visitor IP |
| M2-07 | **Security reporting / incident exports** | Role-scoped filtered exports with post, author, reviewer, action, source, severity, timing and IP handling policy |

**Exit gate:** Security and correction workflows do not allow a bypass of independent approval. Alert false-positive rates and logging overhead measured on staging.

### M3 — Article health and publishing quality (P1)

| ID | Module / feature | Requirements and acceptance criteria |
|---|---|---|
| M3-01 | **Article Health Monitor** | Async checks for missing/broken images, broken outbound links, redirect chains, canonical errors and publishing/indexing configuration, with owner, severity and rescan |
| M3-02 | **News SEO readiness** | Check `NewsArticle`/article markup, visible date consistency, byline, image metadata, sitemap visibility, robots directives; do not replace SEO plugin ownership |
| M3-03 | **Discover image readiness** | Validate image quality, size, preview/robots configuration and crop; present readiness checks, **never guarantee** Discover traffic |
| M3-04 | **Unexpected SEO-change monitoring** | Version and alert on canonical/noindex/robots/title changes, including third-party SEO plugin update paths |
| M3-05 | **Scheduled content-quality sweeps** | Incremental queue with concurrency limits, pause/resume, retries, source timestamps, failure budgets and bounded HTTP calls |
| M3-06 | **Media rights & attribution registry** | Credits, licensing status, use restrictions, expiration warnings and documented permissions; no auto-claim of ownership |

**Exit gate:** No significant increase to public request TTFB, Action Scheduler backlog or database contention; asynchronous checks are source-aware and safe to retry.

### M4 — Editorial assignment desk and workflow (P1/P2)

| ID | Module / feature | Requirements and acceptance criteria |
|---|---|---|
| M4-01 | **Assignment Desk** | Story briefs, beats, desk/owner, assignee, deadlines, priority, attachments and handoff |
| M4-02 | **Editorial calendar** | Per-desk day/week planning, story status and embargo visibility; no calendar duplication when an existing solution already meets needs |
| M4-03 | **Breaking News Mode** | Faster queue and dedicated duty reviewer **without bypassing second-person approval**; emergency path separately recorded |
| M4-04 | **Embargo & approved scheduling** | Pre-publication embargo lock, independently approved future revisions, timezone/DST correctness, cron readiness and safe failure behaviour |
| M4-05 | **Multilingual editorial workflow** | Link English, Marathi and Hindi story variants while preserving independent review, bylines, fact provenance and localized slugs |
| M4-06 | **Source and evidence register** | Sources, supporting documents, verification status and editor notes; private evidence not exposed by public cache |
| M4-07 | **Article ownership transfer and shift handover** | Controlled reassignment, continuity of initial creator and history, outstanding issues by desk and shift |
| M4-08 | **Real editorial activity and bottleneck reports** | Median queue time, corrections, overdue assignments and role workload without surveillance-style individual performance claims from weak data |

**Exit gate:** Measured reduction in editorial turnaround without increasing errors, unauthorized publication or notification noise.

### M5 — Cache, server and business observability (P1/P2)

| ID | Module / feature | Requirements and acceptance criteria |
|---|---|---|
| M5-01 | **Cache & Origin Observatory** | Time-series cache HIT/MISS and purge results, 429/403, origin load and queue health; distinguish server logs from PHP request samples |
| M5-02 | **Purge verification** | Verify fresh content at origin and configured edge after article changes; private/personalized content must never enter public cache |
| M5-03 | **Page experience monitoring** | Core Web Vitals and real-user/performance trend integration with explicit consent/availability and low overhead |
| M5-04 | **Search Console integration** | Site-authorized account connection, URL/article clicks, impressions, CTR and indexing diagnostics; careful permissions and API quotas |
| M5-05 | **Article performance intelligence** | Map traffic to editorial metadata (desk, article type, approval delay and correction count); separate correlation from causal conclusions |
| M5-06 | **Advertising delivery health** | Detect missing slots, ad-error spikes and template regressions without blocking visitors or leaking ad-tech data |
| M5-07 | **Operational alert routing** | Rate-limited incidents to email or optional connected channels, dedupe/escalation windows and audit of delivery failures |

**Exit gate:** Dashboards use aggregations, not full-table scans; performance sampling and data volume budgets are explicitly measured.

### M6 — AI and controlled distribution (P2/P3)

| ID | Module / feature | Requirements and acceptance criteria |
|---|---|---|
| M6-01 | **AI copy desk** | Opt-in proofreading, style and clarity suggestions in English/Hindi/Marathi with visible before/after diffs |
| M6-02 | **Duplicate and related-story detection** | Transparent thresholds, editor override and false-positive review |
| M6-03 | **Revision explanation** | Summarize significant text, external links, sensitive claims and headline changes; show actual diff as primary evidence |
| M6-04 | **Translation drafts** | Names, quotes, numbers and source citations checked by a human translator/editor; no invisible auto-publishing |
| M6-05 | **Fact-check preparation** | Extract checkable claims and source gaps; no unsupported automatic “verified” decisions |
| M6-06 | **AI security investigations** | Natural-language incident summaries grounded in logged evidence, with prompt-injection precautions and source links |
| M6-07 | **Distribution Control Center** | Post-approval social/newsletter/push queue; platform-specific API permissions, retries, delivery states and revocation |
| M6-08 | **AI policy, cost and privacy** | Per-site ON/OFF, explicit data-sharing controls, redaction, token budgets, retention, provider isolation and observability |

**Exit gate:** Editorial decisions remain human-controlled and attributable. No AI tool can publish, retract, delete or silently revise an article.

### M7 — Multi-publication management (P3)

| ID | Module / feature | Requirements and acceptance criteria |
|---|---|---|
| M7-01 | **Multi-site command center** | Central activity view for multiple independent publications, authenticated site registration and per-site controls |
| M7-02 | **Cross-publication incident correlation** | Correlate suspicious IP/activity patterns without conflating identities or sharing private data across unauthorized sites |
| M7-03 | **Central audit and retention policies** | Site-scoped evidence collection, searchable exports and storage/retention policies |
| M7-04 | **Fleet module/version management** | Compatible plugin version, enabled modules, health, deployment drift and rollback/readiness overview |
| M7-05 | **Central governance** | Strict tenant isolation, least-privilege API, per-publication reviewers, site-specific secret rotation and verified authorization |

**Exit gate:** Independent site isolation and rights are demonstrated under adversarial tests; no shared credential can act as an unlogged publisher.

## Cross-cutting platform backlog

- [ ] Professional modular admin navigation, responsive tables, accessible controls and status indicators; avoid adding one enormous settings form.
- [ ] Per-group master and feature toggles, per-site permissions, validation and safe rollback, **OFF by default** for new enforcement and AI.
- [ ] Indexed and bounded data reads, batch processing, privacy-safe exports and backup/restore documentation.
- [ ] Accurate initial publication/source/actor provenance; immutable review snapshot hashes and concurrency-safe review claims.
- [ ] WordPress integration tests for Gutenberg, Classic Editor, REST, cron, WP-CLI, imports, Quick Edit, taxonomy, metadata and cache purge.
- [ ] Accessibility, security, failure-injection, migration, load, and plugin-conflict acceptance tests.
- [ ] Deployment/upgrade instructions compatible with the existing repository and DigiOps workflow once its release contract is verified.
- [ ] External name availability and trademark clearance before public marketing of **Newsroom Guard**.

## Delivery tracking rules

Each future feature must have its own scoped issue/PR with owner, acceptance criteria, risk rating, prerequisites and test evidence. Track states as **Proposed → Specified → In development → CI passed → Staging verified → Released**. Do not move a feature to Released solely because code exists or the workflow passes CI.

**Recommended order:** M1 release safety → M2 security/corrections → M3 quality monitoring → M4 editorial operations → M5 observability → M6 AI/distribution → M7 multi-publication. Re-evaluate order using newsroom incident volumes, time saved, traffic loss and operational cost rather than assumptions.
