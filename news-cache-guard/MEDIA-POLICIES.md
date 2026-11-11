# Newsroom Guard — optional media & editorial content policies

The three policies below are implemented inside the existing Newsroom Guard plugin, with independent switches under **WordPress Admin → Newsroom Guard → Module Manager → Media and content policies**. All three are **OFF by default** for upgrades and new installations.

## Configurable upload types and maximum file size (v1.2.0-rc5)

**Newsroom Guard → Module Manager → Configure file types & maximum size** now lets an Administrator select:
- **JPG/JPEG**, **WebP**, **PNG**, **GIF** and **PDF (e-paper)** as separate allowed format options.
- Maximum per-file size **1–20,480 KB** (1 KB = 1,024 bytes).
- The current upload ON/OFF switch remains independent. Saving settings never automatically enables upload enforcement.
- Existing installations retain their **JPG/JPEG + WebP, 500 KB** defaults until an Administrator explicitly changes them. All media-policy enforcement modules remain **OFF by default**.

The allowlist intentionally excludes SVG, HTML, code and executable formats. Every selected file type is validated against extension, WordPress MIME checks and real file contents. Image types require matching image headers; PDFs require an actual PDF signature and fileinfo MIME recognition when available. A deliberately selected PDF option supports e-paper publishing, but other documents and video remain disallowed while enforcement is ON.

**Compatibility:** MediaFlow v0.2.5 was supplied as a separate plugin archive; it is **not installed or merged into Newsroom Guard**. MediaFlow expects WordPress 6.5+ and PHP 8.1+, whereas Newsroom Guard currently supports older WordPress/PHP releases. MediaFlow generates optimized image derivatives from originals; it does not change this upload allowlist. For MediaFlow source-image quality, allow adequately sized originals (for example 2–5 MB) where server quotas and newsroom requirements permit. A single global maximum still applies to selected PDFs; consider splitting e-paper and editorial-image workflows in a future policy if different limits are needed.

**Operational limits:** Cloudways/PHP `upload_max_filesize`, `post_max_size`, web-server limits, external forms and trusted code that writes directly to disk may bypass or supersede WordPress' upload handlers. These plugin settings do not reconfigure server limits or process existing attachments.

## Legacy default: JPG/JPEG + WebP, max 500 KB

- Validates real on-disk byte count (500 × 1024 = 512,000 bytes); caller-supplied \`size\` is not trusted.
- The default MIME policy is JPEG/WebP only. Administrators can now include PNG, GIF and PDF explicitly; real image/content validation remains mandatory.
- Runs before ordinary WordPress uploads **and** sideloads, including their REST pathways that use WordPress handlers.
- Uses a short, plain-text uploader error, and shows a **bold warning** on WordPress Media screens. Does **not** replace the global \`gettext\` message "This file cannot be processed by the web server"—that error can indicate unrelated filesystem, memory or image-processing failures, and disguising it would mislead staff.
- Applies to *all new WordPress uploads* when ON. **Unselected** types (including PDF, PNG, GIF, and MP4 when not allowed) are rejected, even for Administrators and automated ingestion accounts. Existing media is unchanged.
- Compatible with a separately installed **MediaFlow** image-derivative engine. Choose appropriate original-image size limits and enable PDFs if they pass through WordPress media; keep enforcement OFF until these paths are tested.
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
