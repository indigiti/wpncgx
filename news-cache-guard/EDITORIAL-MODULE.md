# Editorial & Publishing module (staging release candidate)

This optional module is part of News Cache Guard's **Settings → News Cache Guard → Editorial & Publishing** tab.

## Safe defaults

- **Master OFF** on upgrade/installation. All cache, crawler, LiteSpeed, Redis, search and worker controls remain independent.
- Audit, Security, Approvals and Lock have independent group toggles (dependent on Master).
- Lock: **4 hours** from original recorded publication; administrators and user ID **529** are exempt.
- Editors cannot self-approve. If the submitting article was authored, created, submitted or revised by an editor, the reviewer must be a **different Editor**.
- Author/Contributor submissions require a different Editor or Administrator.
- Administrators retain emergency direct publication rights; when audit is enabled such publication is recorded.
- Protected live articles can be revised via a separate proposal; the live article remains unchanged while awaiting review.
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
11. Attempt unapproved cron and imported publication. Verify it is reversed and cannot be displayed to anonymous visitors, including warmed cache.
12. Disable Master and confirm previously published posts and ordinary WordPress capabilities work as before.
13. Confirm scheduled posts, SEO meta data, media uploads, and category/tag changes cannot bypass the approval gate.
14. Check user-authorization conflicts with other editorial plugins. Validate backups and rollback.

## Known limitations — do not claim production security approval

- No full WordPress browser/REST/cron integration suite has been run. Current unit tests cover configuration and representative capability checks.
- Administrators remain privileged, including plugin deactivation.
- Approval UI currently supports immediate approval; future-dated approval scheduling has not been implemented in this branch.
- Highly customised integrations that update the database directly bypass WordPress filters. Additional post-meta/term/featured-image filters require integration testing.
- Existing articles without an immutable recorded first-publication timestamp use their legacy WordPress published date as a fallback, not as forensic proof.
- IP records depend on the configured News Cache Guard Cloudflare trust model; direct/unsupported proxy setups may have unknown client IP.
- Log events are not independently tamper-evident. For compliance, replicate protected audit evidence outside the WordPress database.
- An unapproved publish transition may briefly execute hooks before its reversal; test page cache to ensure nothing leaks publicly.
- Performance and role workflows need a staging test under the site's actual plugin/theme/hosting stack.

**Release gate:** Do not merge this feature branch to main until these issues and tests are resolved. In particular, the publish-before-rollback behavior and non-post-field bypasses must be hardened before production enforcement.
