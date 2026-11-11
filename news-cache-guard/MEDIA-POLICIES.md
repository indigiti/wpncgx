# Newsroom Guard — optional media & editorial content policies

The three policies below are implemented inside the existing Newsroom Guard plugin, with independent switches under **WordPress Admin → Newsroom Guard → Module Manager → Media and content policies**. All three are **OFF by default** for upgrades and new installations.

## Strict image uploads: JPG/JPEG + WebP, max 500 KB

- Validates real on-disk byte count (500 × 1024 = 512,000 bytes); caller-supplied \`size\` is not trusted.
- Restricts allowed upload MIME types to JPEG/WebP and verifies the actual image header, MIME and filename extension.
- Runs before ordinary WordPress uploads **and** sideloads, including their REST pathways that use WordPress handlers.
- Uses a short, plain-text uploader error, and shows a **bold warning** on WordPress Media screens. Does **not** replace the global \`gettext\` message "This file cannot be processed by the web server"—that error can indicate unrelated filesystem, memory or image-processing failures, and disguising it would mislead staff.
- Applies to *all new WordPress uploads* when ON. **PDFs, PNG, GIF, MP4 and other document/media types will be rejected**, including epaper PDFs, even for Administrators and automated ingestion accounts. Existing media is unchanged.
- Compatible with a separately installed **MediaFlow** image-derivative engine at runtime, but strict source uploads disallow PNG and large JPEG/WEBP originals that MediaFlow might otherwise process. **Keep OFF if you ingest high-resolution original news images or PDFs; split document workflows or request narrower rules before enabling.**
- Not a firewall or file-system policy: trusted code that writes directly to disk does not invoke these filters; web-server/PHP upload limits may reject a file before the plugin runs.

## Category creation

- Blocks creating new terms in the built-in WordPress \`category\` taxonomy unless the acting account has \`manage_options\`.
- Uses \`pre_insert_term\` and a clear \`WP_Error\` without changing Editor roles or capabilities in the database.
- Existing categories can still be assigned; terms in other taxonomies are unaffected.
- WP-CLI, cron and importers acting **without** an administrator user context cannot create categories while this policy is ON; choose a pre-created taxonomy or an explicitly authorized administrator context.
- Trusted direct SQL writes remain outside WordPress hook enforcement.

## Featured-image alt fallback

- If a post/page has a featured image with *empty* \`_wp_attachment_image_alt\`, populate it with the sanitized post/page title.
- Runs after post insertion, after REST/Gutenberg post save and when the \`_thumbnail_id\` metadata is added or changed; it does not duplicate or rewrite human-authored alt text.
- Requires current user permission to edit **both** the post/page and the image attachment.
- **Attachment alt text is shared** wherever that media is reused. The first successful fallback wins; subsequent articles cannot overwrite it. This is a convenience fallback, **not** a guarantee of contextually accurate accessibility text. Editorial staff should provide meaningful manual descriptions when possible.
- Existing images are not bulk-modified at feature enablement; the next authorized save or featured-image assignment triggers the fallback.

## Testing / release gates

1. Confirm default OFF and independently persistent switches in the unified Module Manager.
2. Test JPEG/JPEG/WebP success, oversize rejection, PNG/PDF/GIF rejection, fake JPEG content, and media sideload/REST errors against an isolated WordPress installation.
3. Test Editor and Administrator category creation, existing-category assignment, tag insertion and lack of permanent role mutations.
4. Test featured-image assignment, Gutenberg saves, existing alt preservation, attachment permission checks and shared-image reuse.
5. Test a separate **MediaFlow** installation and actual image delivery/caching; this package was inspected, not merged into Newsroom Guard.
6. Verify epaper publishing and other upload workflows before enabling strict upload mode on **indianewshouse.in**.
7. Rebuild the \`digiops-release\` artifact and deploy a testing ZIP. This change does not automatically deploy itself.

**Non-goals:** image compression/resizing, original-media replacement, automating alt for historical uploads, disabling unrelated upload error messages, and persistent WordPress role changes.
