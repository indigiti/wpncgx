# Newsroom Guard — updates from GitHub Releases

## First installation / migration

The first updater-enabled build is **v1.2.0-rc4**. Earlier versions (including v1.2.0-rc3) lack the WordPress \`Update URI\` integration and therefore **must be upgraded once** through the existing DigiOps release pipeline or Plugins → Add New → Upload Plugin.

The plugin directory remains \`wp-content/plugins/news-cache-guard/\`, with the same \`news-cache-guard/news-cache-guard.php\` entry point and unchanged \`ncg_*\` settings/database keys.

Once v1.2.0-rc4 is installed, WordPress periodically checks the public \`indigiti/wpncgx\` GitHub Releases API using the standard plugin update mechanism.

## Where to configure

WordPress Admin → **Newsroom Guard → Module Manager → GitHub Release Updates**.

- **Stable (default):** only final published, non-prerelease GitHub releases.
- **Testing (explicit opt-in):** final releases and prereleases, including \`-rcN\` release candidates. Appropriate for staging and testing; not recommended for unattended production updating.
- **Check GitHub releases now:** administrator-only, nonce-protected, clears the release and WordPress plugin-update cache and runs a fresh native check.
- **Update installation:** WordPress Admin → **Plugins** → Newsroom Guard → **Update now**. This requires WordPress's \`update_plugins\` capability.
- **Automatic installation:** This plugin does **not** activate auto-installation. WordPress's per-plugin **Enable auto-updates** control remains the authority. For a production newsroom, prefer manual updates after a backup and staging verification.

GitHub release metadata is normally cached for **four hours** (errors for 15 minutes). WordPress also has its own update-check interval; the "Check now" action refreshes both. This is periodic checking, not real-time push notifications.

## Release and ZIP trust policy

1. Release must be published (not draft) under the **public** \`indigiti/wpncgx\` repository.
2. Release tag must follow \`vMAJOR.MINOR.PATCH\` or a supported \`-rcN\`/\`-betaN\`/\`-alphaN\` suffix.
3. It must contain the exact named WordPress ZIP \`newsroom-guard-VERSION.zip\` and \`SHA256SUMS.txt\` from that same tag.
4. Asset URLs must be exactly \`https://github.com/indigiti/wpncgx/releases/download/TAG/ASSET\`. No branch archives, HTTP URLs, redirects to arbitrary candidate URLs, or untrusted hostnames are offered by the updater.
5. Only a version **newer** than the currently installed plugin may be offered.
6. Before WordPress unpacks the ZIP, the updater downloads the exact checksum manifest, downloads or reuses the ZIP, verifies SHA-256 and (when PHP ZipArchive is present) checks the expected plugin root and version header.
7. If GitHub is unavailable, the checksum is missing, the ZIP fails hash validation or the version/structure is invalid, the update stops with an error. **No fallback to unverified packages.**

**Trust boundary:** a SHA-256 sum published alongside the GitHub ZIP confirms download consistency, but does not independently protect against a compromised repository/release account. Restrict GitHub release permissions, use protected tags, monitor workflow access and keep Cloudways backups.

## GitHub release workflow

The existing \`.github/workflows/build-release.yml\` produces a WordPress-installable ZIP, \`SHA256SUMS.txt\`, a \`manifest.json\` and the \`digiops-release\` artifact. The updater consumes **GitHub Releases**, not temporary GitHub Actions artifacts, and cannot install from a ZIP without a matching checksum asset.

The release packaging job validates PHP syntax and core regressions; it must verify the updater's standalone tests as well. New releases must bump both the plugin header/constant and the release build workflow version/tag; each tagged ZIP must contain the same version and unchanged WordPress plugin directory.

**Do not force-push or rewrite tags for published releases.** Create a new version.

## Operational limitations

- Requires the site to make outbound HTTPS requests to \`api.github.com\` and \`github.com\`, with WordPress filesystem update permissions. GitHub's unauthenticated API rate limits apply.
- Private repositories are unsupported without a separate authenticated distribution service.
- Existing early Search Guard MU files under \`wp-content/mu-plugins/\` are **not** updated by the standard WordPress plugin updater. Audit and maintain them separately.
- CDN/LiteSpeed rules and worker configuration must be tested after updates; the release package alone does not prove the staging or live server is safe.
- If file changes are made directly inside the plugin directory, a normal update may replace those changes.
- The updater is designed to work with the existing single Newsroom Guard admin interface and does not add a separate WordPress sidebar menu.
