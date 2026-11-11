# Editorial & Publishing module (staging release candidate)

This optional module is part of Newsroom Guard's **Settings → Newsroom Guard → Editorial & Publishing** tab.

## Safe defaults

- **Master OFF** on upgrade/installation. All cache, crawler, LiteSpeed, Redis, search and worker controls remain independent.
- Audit, Security, Approvals and Lock have independent group toggles (dependent on Master).
- Lock: **4 hours** from original recorded publication; administrators and user ID **529** are exempt from the lock only. The allowlist never bypasses independent editorial approvals.
- Editors cannot self-approve. If a submitting article was authored, created, submitted or drafted by an Editor, the reviewer must be a **different Editor**. All tracked contributors are excluded.
- Author/Contributor submissions require a different Editor or Administrator.
- Administrators retain emergency direct publication rights; when audit is enabled such publication is recorded. Editors and Authors have the runtime `publish_posts` capability masked so Gutenberg offers a review path.
- Protected live articles can be revised via a separate proposal, including SEO metadata; the live article remains unchanged while awaiting review. Direct REST and WordPress post writes are denied before save.
- Audit events are stored in dedicated indexed tables; logs are retained 180 days by default.
- Switching off a feature does **not** erase logs or process pending approvals.

## Staging acceptance checklist (required before merging)

1. Back up the database; install the branch on a representative staging copy.
2. Confirm existing News Cache Guard cache behavior, `.htaccess`, query collapse, Redis guard, worker guard and LiteSpeed HIT/MISS behavior are unchanged.
3. Verify new Master is OFF and no author/editor permission changes occur until explicitly enabled.
4. Turn on Audit, Security, Approvals and Lock individually. Confirm toggles persist and disabling each group stops only that group.
5. As Contributor, Author and Editor: create articles, attempt direct publish via Classic Editor, Gutenberg, REST, Quick Edit and WP-CLI. Nothing may publish without independent approval.
6. Editor A's article requires Editor B; Editor A cannot approve their own or an article they changed. Check other edit/submitter combinations.
7. Review approved/rejected/returned content. Confirm approved revisions replace only approved fields and publish cache invalidation fires.
8. Verify lock after 4 hours for Edit, Quick Edit, Trash and REST API, including attempts by ordinary editors and authors.
9. Verify user ID 529 and administrators bypass the timed lock **only where normal WordPress permissions also allow the action**.
10. Verify new IP, role change, external link, content change and deletion events appear with correct actor and source. Review proxy IP trust configuration.
11. Attempt legacy unapproved scheduled publication: ensure it is prevented **before** the core cron publish callback. Verify approved administrator schedules still work, and verify any direct `wp_publish_post()` integrations on staging (direct API code that bypasses authorization is not a supported publishing route).
12. Disable Master and confirm previously published posts and ordinary WordPress capabilities work as before.
13. Confirm scheduled posts, SEO meta data, media uploads, and category/tag changes cannot bypass the approval gate.
14. Check user-authorization conflicts with other editorial plugins. Validate backups and rollback.

## Known limitations — do not claim production security approval

- Automated integration runs provision real WordPress and MariaDB on GitHub Actions and cover REST, user roles, protected metadata, locking and cron preflight. This is **not** equivalent to the actual Cloudways staging stack, a full Gutenberg browser test, or LiteSpeed cache verification.
- Administrators remain privileged, including plugin deactivation.
- Approval UI currently supports immediate approval. Administrators can create explicit authorised WordPress schedules; Editor-approved deferred publishing is not yet implemented.
- Highly customised integrations that update WordPress's database directly or call `wp_set_object_terms()` bypass core permission checks. WordPress's standard editor/REST paths are blocked; arbitrary trusted PHP and direct SQL require separate site-level governance and integration review.
- Existing articles without an immutable recorded first-publication timestamp use their legacy WordPress published date as a fallback, not as forensic proof.
- IP records depend on the configured News Cache Guard Cloudflare trust model; direct/unsupported proxy setups may have unknown client IP.
- Log events are not independently tamper-evident. For compliance, replicate protected audit evidence outside the WordPress database.
- Direct invocation of `wp_publish_post()` by privileged code bypasses the pre-insert filter because WordPress updates the post before emitting the transition. A defensive rollback exists, but is not an atomic guarantee against publication hooks, cache, webhooks or external consumers. Do not expose this path to untrusted integrations.
- Performance and role workflows need a staging test under the site's actual plugin/theme/hosting stack.

**Release gate:** Do not merge this feature branch to main until Cloudways staging has passed real Gutenberg/Classic/REST/editorial workflows, existing cache and Redis functionality, authenticated-page privacy, scheduled publication, and external publishing integration checks. A green GitHub Actions run is necessary but insufficient. The master switch must remain OFF on release until site-specific acceptance is signed off.
