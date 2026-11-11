<?php
/**
 * Native GitHub release updater for Newsroom Guard.
 *
 * WordPress handles installation, permissions, rollback and filesystem access.
 * This class only supplies verified release metadata and validates its ZIP
 * checksum before WordPress installs an update. No automatic installs are
 * enabled by the plugin itself.
 */
declare(strict_types=1);
namespace NCG;

if (!defined('ABSPATH')) exit;

final class GitHubUpdates {
    public const OPTION = 'ncg_github_update_settings';
    public const UPDATE_URI = 'https://github.com/indigiti/wpncgx';
    public const API = 'https://api.github.com/repos/indigiti/wpncgx/releases?per_page=30';
    public const SLUG = 'news-cache-guard';
    private const CACHE_PREFIX = 'ncg_gh_release_';
    private const MAX_ZIP_BYTES = 20971520; // 20 MiB compressed, safety ceiling.
    private const MAX_UNPACKED_BYTES = 104857600; // 100 MiB uncompressed.

    public static function boot(): void {
        add_filter('update_plugins_github.com', [self::class, 'offer_update'], 20, 4);
        add_filter('upgrader_pre_download', [self::class, 'verify_download'], 9999, 4);
        add_filter('plugins_api', [self::class, 'plugin_information'], 20, 3);
        if (is_admin()) {
            add_action('admin_post_nrg_github_save', [self::class, 'save']);
            add_action('admin_post_nrg_github_check', [self::class, 'check_now']);
        }
    }

    public static function settings(): array {
        $saved = get_site_option(self::OPTION, []);
        if (!is_array($saved)) $saved = [];
        return ['channel' => isset($saved['channel']) && $saved['channel'] === 'testing' ? 'testing' : 'stable'];
    }

    public static function channel(): string {
        return self::settings()['channel'];
    }

    /**
     * Pure release parser for public GitHub release API responses.
     * Never use a branch ZIP, API asset URL, or arbitrary download URL.
     * The expected CI package keeps the installed plugin's original slug.
     */
    public static function select_release(array $items, string $channel): ?array {
        $selected = null;
        foreach ($items as $item) {
            if (!is_array($item) || !empty($item['draft'])) continue;
            $tag = isset($item['tag_name']) && is_string($item['tag_name']) ? $item['tag_name'] : '';
            if (!preg_match('/^v(\d+\.\d+\.\d+(?:-(?:alpha|beta|rc)\d+)?)$/D', $tag, $match)) continue;
            $version = $match[1];
            $prerelease = !empty($item['prerelease']) || strpos($version, '-') !== false;
            if ($channel !== 'testing' && $prerelease) continue;
            $zip_name = 'newsroom-guard-' . $version . '.zip';
            $release_prefix = 'https://github.com/indigiti/wpncgx/releases/download/' . $tag . '/';
            $package = '';
            $checksum = '';
            $zip_size = 0;
            foreach (($item['assets'] ?? []) as $asset) {
                if (!is_array($asset)) continue;
                $name = $asset['name'] ?? null;
                $url = $asset['browser_download_url'] ?? null;
                if (!is_string($name) || !is_string($url) ||
                    $url !== $release_prefix . rawurlencode($name)) continue;
                if ($name === $zip_name) {
                    $zip_size = (int)($asset['size'] ?? 0);
                    if ($zip_size > 0 && $zip_size <= self::MAX_ZIP_BYTES) $package = $url;
                }
                if ($name === 'SHA256SUMS.txt') $checksum = $url;
            }
            if (!$package || !$checksum) continue;
            if ($selected !== null && version_compare($version, $selected['version'], '<=')) continue;
            $selected = [
                'tag' => $tag,
                'version' => $version,
                'prerelease' => $prerelease,
                'package' => $package,
                'filename' => $zip_name,
                'checksum_url' => $checksum,
                'size' => $zip_size,
                'url' => self::UPDATE_URI . '/releases/tag/' . $tag,
                'published' => is_string($item['published_at'] ?? null) ? $item['published_at'] : '',
                'notes' => is_string($item['body'] ?? null) ? substr($item['body'], 0, 4000) : '',
            ];
        }
        return $selected;
    }

    private static function cache_key(string $channel): string {
        return self::CACHE_PREFIX . ($channel === 'testing' ? 'test' : 'stable');
    }

    /**
     * Short negative-result caching avoids retry storms during GitHub outages.
     * The site never downloads a ZIP when release validation fails.
     */
    public static function release_status(?string $channel = null): array {
        $channel = $channel === 'testing' ? 'testing' : ($channel === 'stable' ? 'stable' : self::channel());
        $cache = get_site_transient(self::cache_key($channel));
        if (is_array($cache) && isset($cache['status'])) return $cache;

        $result = ['status' => 'error', 'release' => null, 'checked' => time(),
            'message' => 'GitHub releases cannot be reached at present.'];
        $response = wp_remote_get(self::API, [
            'timeout' => 8,
            'redirection' => 2,
            'sslverify' => true,
            'limit_response_size' => 786432,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'Newsroom-Guard/' . NCG_VERSION . ' (WordPress plugin updater)',
            ],
        ]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode((string)wp_remote_retrieve_body($response), true);
            if (is_array($data) && array_values($data) === $data) {
                $candidate = self::select_release($data, $channel);
                $result['status'] = $candidate ? 'ok' : 'empty';
                $result['release'] = $candidate;
                $result['message'] = $candidate ? '' : 'No eligible GitHub release with a matching ZIP and SHA-256 manifest was found.';
            } else {
                $result['message'] = 'GitHub returned an invalid release listing.';
            }
        } elseif (!is_wp_error($response)) {
            $result['message'] = 'GitHub release request failed (HTTP ' .
                (int)wp_remote_retrieve_response_code($response) . ').';
        }
        set_site_transient(self::cache_key($channel), $result,
            $result['status'] === 'error' ? 15 * MINUTE_IN_SECONDS : 4 * HOUR_IN_SECONDS);
        return $result;
    }

    public static function offer_update($update, array $plugin_data, string $plugin_file, array $locales) {
        if ($plugin_file !== NCG_BASENAME ||
            ($plugin_data['UpdateURI'] ?? '') !== self::UPDATE_URI) return $update;
        $result = self::release_status();
        $candidate = $result['release'];
        if ($result['status'] !== 'ok' || !is_array($candidate)) return false;
        if (!version_compare($candidate['version'], NCG_VERSION, '>')) return false;
        return [
            'id' => self::UPDATE_URI,
            'slug' => self::SLUG,
            'version' => $candidate['version'],
            'url' => $candidate['url'],
            'package' => $candidate['package'],
            'requires' => '6.2',
            'requires_php' => '7.4',
        ];
    }

    public static function plugin_information($result, string $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) ||
            ($args->slug ?? '') !== self::SLUG) return $result;
        $status = self::release_status();
        if ($status['status'] !== 'ok' || !is_array($status['release'])) return $result;
        $release = $status['release'];
        return (object)[
            'name' => 'Newsroom Guard',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => 'DCN',
            'homepage' => self::UPDATE_URI,
            'requires' => '6.2',
            'requires_php' => '7.4',
            'tested' => '',
            'download_link' => $release['package'],
            'last_updated' => $release['published'],
            'sections' => [
                'description' => '<p>Newsroom publishing security, editorial approvals, audit, cache and origin protection.</p>',
                'changelog' => '<p><a href="' . esc_url($release['url']) .
                    '" target="_blank" rel="noopener noreferrer">Read the GitHub release notes</a>.</p>',
            ],
        ];
    }

    /**
     * Intercept only a normal WordPress update for this exact plugin. Verify
     * the release ZIP hash from the same tagged GitHub release before letting
     * WordPress's own upgrader unpack it. Core's file-system rollback is kept.
     */
    public static function verify_download($reply, string $package, $upgrader, array $hook_extra) {
        if (($hook_extra['type'] ?? '') !== 'plugin' ||
            ($hook_extra['action'] ?? '') !== 'update' ||
            ($hook_extra['plugin'] ?? '') !== NCG_BASENAME) return $reply;
        if (is_wp_error($reply)) return $reply;
        $state = self::release_status();
        $release = $state['release'];
        if ($state['status'] !== 'ok' || !is_array($release) ||
            $package !== $release['package'] ||
            !version_compare($release['version'], NCG_VERSION, '>')) {
            return new \WP_Error('ncg_invalid_update', 'Newsroom Guard refused an unrecognized GitHub update package.');
        }
        $response = wp_remote_get($release['checksum_url'], [
            'timeout' => 12, 'redirection' => 2, 'sslverify' => true,
            'limit_response_size' => 4096,
            'headers' => ['User-Agent' => 'Newsroom-Guard/' . NCG_VERSION],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return new \WP_Error('ncg_missing_checksum',
                'Newsroom Guard cannot verify the GitHub release checksum. The update was not installed.');
        }
        $sum_text = (string)wp_remote_retrieve_body($response);
        $escaped = preg_quote($release['filename'], '/');
        if (!preg_match('/^([a-f0-9]{64})\s+\*?' . $escaped . '\s*$/mi', $sum_text, $matches)) {
            return new \WP_Error('ncg_bad_checksum_manifest',
                'The GitHub release checksum manifest is missing or malformed.');
        }

        $own_download = false;
        if (is_string($reply)) {
            $file = $reply; // Another trusted WordPress hook already downloaded it.
        } else {
            if (!function_exists('download_url')) require_once ABSPATH . 'wp-admin/includes/file.php';
            $file = download_url($release['package'], 60);
            $own_download = true;
        }
        if (is_wp_error($file)) return $file;
        if (!is_string($file) || !is_file($file) || !is_readable($file) ||
            filesize($file) > self::MAX_ZIP_BYTES) {
            if ($own_download && is_string($file)) @unlink($file);
            return new \WP_Error('ncg_invalid_zip', 'Newsroom Guard update ZIP was not downloaded correctly.');
        }
        $actual = hash_file('sha256', $file);
        if (!is_string($actual) || !hash_equals(strtolower($matches[1]), strtolower($actual))) {
            if ($own_download) @unlink($file);
            return new \WP_Error('ncg_checksum_mismatch',
                'Newsroom Guard GitHub update ZIP failed SHA-256 verification. No files were replaced.');
        }
        $check = self::check_zip_structure($file, $release['version']);
        if (is_wp_error($check)) {
            if ($own_download) @unlink($file);
            return $check;
        }
        return $file;
    }

    /**
     * Extra safety when ZipArchive is installed; the CI build also checks ZIP
     * layout, and the SHA-256 verification is mandatory on all servers.
     */
    public static function check_zip_structure(string $file, string $version) {
        if (!class_exists('ZipArchive')) return true;
        $zip = new \ZipArchive();
        if ($zip->open($file) !== true) return new \WP_Error('ncg_zip_invalid', 'Newsroom Guard release is not a valid ZIP.');
        $bytes = 0;
        if ($zip->numFiles > 10000) { $zip->close(); return new \WP_Error('ncg_zip_overflow', 'Newsroom Guard release includes too many files.'); }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string)($stat['name'] ?? '');
            $bytes += (int)($stat['size'] ?? 0);
            if ($bytes > self::MAX_UNPACKED_BYTES || strpos($name, 'news-cache-guard/') !== 0 ||
                strpos($name, '../') !== false || strpos($name, '\\') !== false) {
                $zip->close();
                return new \WP_Error('ncg_zip_layout', 'Newsroom Guard ZIP has an unexpected plugin folder or unsafe contents.');
            }
        }
        $header = $zip->getFromName('news-cache-guard/news-cache-guard.php');
        $zip->close();
        if (!is_string($header) ||
            strpos($header, 'Plugin Name: Newsroom Guard') === false ||
            strpos($header, 'Version: ' . $version) === false ||
            strpos($header, 'Update URI: ' . self::UPDATE_URI) === false) {
            return new \WP_Error('ncg_zip_header', 'Newsroom Guard ZIP does not contain the expected plugin version.');
        }
        return true;
    }

    private static function authorized(): bool {
        return current_user_can('update_plugins') &&
            (!is_multisite() || current_user_can('manage_network_plugins'));
    }

    public static function save(): void {
        if (!self::authorized()) wp_die('You cannot change plugin update settings.', '', ['response' => 403]);
        check_admin_referer('nrg_github_save');
        $value = isset($_POST['channel']) && is_string($_POST['channel']) ?
            sanitize_key(wp_unslash($_POST['channel'])) : 'stable';
        $channel = $value === 'testing' ? 'testing' : 'stable';
        update_site_option(self::OPTION, ['channel' => $channel]);
        delete_site_transient('update_plugins');
        wp_safe_redirect(Dashboard::url('settings', ['panel'=>'updates','nrg_notice'=>'github_saved']));
        exit;
    }

    public static function check_now(): void {
        if (!self::authorized()) wp_die('You cannot check for plugin updates.', '', ['response' => 403]);
        check_admin_referer('nrg_github_check');
        delete_site_transient(self::cache_key('stable'));
        delete_site_transient(self::cache_key('testing'));
        delete_site_transient('update_plugins');
        if (!function_exists('wp_update_plugins')) require_once ABSPATH . 'wp-includes/update.php';
        wp_update_plugins();
        wp_safe_redirect(Dashboard::url('settings', ['panel'=>'updates','nrg_notice'=>'github_checked']));
        exit;
    }
}
