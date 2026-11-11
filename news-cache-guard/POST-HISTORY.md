# Post History and Editorial Activity

The **Newsroom Guard → Editorial Activity** workspace and the **Newsroom Guard — Post History** box in the existing WordPress post editor separate the public author from the person who actually creates, edits or publishes a news article.

## Compatibility and privacy
- **No theme modifications**. Does not alter `post_author`, author pages, frontend CSS, public URLs, SEO or content.
- Independent **Track Post History and Editorial Activity** switch under **Newsroom Guard → Module Manager**. Enabled for new installations and upgrades; turn off to stop future collection without deleting historical records. Other editorial security/approval/lock settings remain unchanged and OFF unless separately configured.
- The journal is a dedicated WordPress database table (`{prefix}ncg_post_history`), with bounded pagination and indexed post, actor, author, event and date fields. Database installation runs on plugin activation or once on upgrade.
- Events: article creation, headline/content/excerpt/slug edits (changed field names only, no content copied), public author changes (old/new user IDs), publication, scheduling and status changes. Each event captures the logged-in WordPress user and their display name at event time, and source (dashboard, REST, cron, WP-CLI, etc.).
- Cron/CLI/system operations with no authenticated WordPress user are **not** attributed to the reporter or an imagined editor.
- The post editor history box is restricted to staff with `edit_others_posts` AND `edit_post` permissions. The unified dashboard is Administrator-only.
- Unlike the optional editorial security audit, the journal **does not store IP addresses**, does not grant capabilities, and does not enforce publishing approval.
- Previous articles may have no creation/publication records. We never infer an unknown uploader from the public byline.
- The journal is append-only through the plugin UI. A database administrator can still change it. For tamper-evident records, independently export and sign/replicate events.

The CI integration suite runs this tracker on real WordPress + MariaDB with the existing Editorial Master disabled **before** WordPress bootstraps. This verifies that staff attribution is independent of the approval/lock hooks, which must not be activated solely to inspect post history.

## Staging test checklist
1. Back up WordPress DB. Activate the build and visit Newsroom Guard → Module Manager. Confirm **Track Post History and Editorial Activity** is on and Approvals and Lock stay off.
2. As Editor A, create a draft; select Reporter B using the existing WordPress Author UI. Verify site byline still says Reporter B and Post History says created by Editor A.
3. Publish as Editor A. Verify publication records Editor A, even though post author is Reporter B.
4. Edit the same post as Editor C. Verify last editor and author-change evidence, and exact staff filtering in Editorial Activity.
5. Test Gutenberg, Classic Editor, Quick Edit, REST saves, future scheduled publication and WordPress cron. Do not attribute cron to a logged-in person. A scheduler is distinguishable from a cron publishing process.
6. Turn tracking off and verify **no new events**; existing ones remain queryable. Check editorial approval, caching, media rules, GitHub updates and existing settings remain unchanged.
7. Confirm post-editing restrictions and XSS protection against malicious article titles, author display names and request query strings.
8. Check performance on a representative publishing workload. The dashboard queries run only in wp-admin; each tracked write adds a bounded journal insert.

## Limitations
- Audit begins **after deployment**; it cannot prove who uploaded an old post.
- A WordPress login identifies the user account, **not the physical person**. Prevent shared accounts.
- Display names can change and WP users can be deleted; actor ID and captured actor label survive the change, but reporter name lookup may become unavailable for deleted users.
- This is editorial accountability, not a compliance-grade immutable log.
