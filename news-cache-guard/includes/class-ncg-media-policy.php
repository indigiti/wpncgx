<?php
/**
 * Optional newsroom media and editorial policy controls.
 *
 * No persistent WordPress role mutation, global gettext rewriting, or frontend
 * assets. All restrictions are OFF until an Administrator enables each switch.
 */
declare(strict_types=1);
namespace NCG;

if (!defined('ABSPATH')) exit;

final class MediaPolicy {
    public const MAX_UPLOAD_BYTES = 500 * 1024; // Backward-compatible default.
    public const MAX_LABEL = '500 KB';
    public const ERROR = 'Newsroom Guard: upload rejected by the configured file policy.';
    public const OPTION = 'ncg_media_upload_policy';
    public const MIN_KB = 1;
    public const MAX_KB = 20480; // Cap administrator-configured policy at 20 MiB.

    /**
     * An intentionally small allowlist; SVG/HTML and executable file types
     * cannot be enabled. PDFs are optional for newsroom/e-paper workflows.
     */
    public static function supported_types(): array {
        return [
            'jpeg' => ['label'=>'JPG / JPEG', 'ext'=>'jpg|jpeg', 'mime'=>'image/jpeg'],
            'webp' => ['label'=>'WebP', 'ext'=>'webp', 'mime'=>'image/webp'],
            'png'  => ['label'=>'PNG', 'ext'=>'png', 'mime'=>'image/png'],
            'gif'  => ['label'=>'GIF', 'ext'=>'gif', 'mime'=>'image/gif'],
            'pdf'  => ['label'=>'PDF (e-paper)', 'ext'=>'pdf', 'mime'=>'application/pdf'],
        ];
    }

    public static function defaults(): array {
        return ['max_kb'=>500, 'types'=>['jpeg','webp']];
    }

    public static function clean($raw): array {
        $raw = is_array($raw) ? $raw : [];
        $limit = isset($raw['max_kb']) && is_scalar($raw['max_kb']) ? (int)$raw['max_kb'] : 500;
        $choices = isset($raw['types']) && is_array($raw['types']) ? $raw['types'] : [];
        $types = [];
        foreach (array_keys(self::supported_types()) as $key) {
            if (in_array($key, $choices, true)) $types[] = $key;
        }
        // The saving handler rejects empty selections. Corrupt stored settings
        // fail back to the known working default, not an unrestricted MIME list.
        if (!$types) $types = self::defaults()['types'];
        return [
            'max_kb' => max(self::MIN_KB,min(self::MAX_KB,$limit)),
            'types' => $types,
        ];
    }

    public static function settings(): array {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) $stored = [];
        return self::clean(array_replace(self::defaults(), $stored));
    }

    public static function max_bytes(): int {
        return self::settings()['max_kb'] * 1024;
    }

    public static function error_message(): string {
        $config = self::settings();
        $supported = self::supported_types();
        $names = [];
        foreach ($config['types'] as $type) $names[] = $supported[$type]['label'];
        return 'Newsroom Guard: only ' . implode(', ', $names) . ' files up to ' .
            number_format((int)$config['max_kb']) . ' KB are allowed.';
    }

    public static function boot(): void {
        if (Modules::on('media_uploads')) {
            add_filter('upload_mimes', [self::class, 'allowed_mimes'], 999, 1);
            add_filter('wp_handle_upload_prefilter', [self::class, 'check_upload'], 10, 1);
            add_filter('wp_handle_sideload_prefilter', [self::class, 'check_upload'], 10, 1);
            add_action('admin_notices', [self::class, 'media_upload_notice']);
        }
        if (Modules::on('category_creation')) {
            add_filter('pre_insert_term', [self::class, 'block_category_creation'], 10, 3);
        }
        if (Modules::on('featured_alt')) {
            add_action('wp_after_insert_post', [self::class, 'after_post_saved'], 20, 4);
            add_action('rest_after_insert_post', [self::class, 'rest_post_saved'], 20, 3);
            add_action('rest_after_insert_page', [self::class, 'rest_post_saved'], 20, 3);
            add_action('added_post_meta', [self::class, 'thumbnail_meta_changed'], 20, 4);
            add_action('updated_post_meta', [self::class, 'thumbnail_meta_changed'], 20, 4);
        }
    }

    public static function media_upload_notice(): void {
        if (!is_admin() || !current_user_can('upload_files')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array((string)$screen->id, ['upload','media'], true)) return;
        echo '<div class="notice notice-warning"><p><strong>' .
            esc_html(self::error_message()) . '</strong></p></div>';
    }

    public static function allowed_mimes(array $mimes): array {
        if (!Modules::on('media_uploads')) return $mimes;
        $out = [];
        $supported = self::supported_types();
        foreach (self::settings()['types'] as $key) {
            $row = $supported[$key];
            $out[$row['ext']] = $row['mime'];
        }
        return $out;
    }

    public static function check_upload(array $file): array {
        if (!Modules::on('media_uploads') || !empty($file['error'])) return $file;

        $deny = self::error_message();
        $tmp = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
        $name = isset($file['name']) && is_string($file['name']) ? $file['name'] : '';
        if (!$name || !$tmp || !is_file($tmp) || !is_readable($tmp)) {
            $file['error'] = $deny;
            return $file;
        }
        $size = filesize($tmp);
        if ($size === false || $size < 1 || $size > self::max_bytes()) {
            $file['error'] = $deny;
            return $file;
        }

        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $supported = self::supported_types();
        $expected_type = null;
        foreach (self::settings()['types'] as $key) {
            if (in_array($ext, explode('|', $supported[$key]['ext']), true)) {
                $expected_type = $supported[$key]['mime'];
                break;
            }
        }
        if ($expected_type === null) {
            $file['error'] = $deny;
            return $file;
        }

        $checked = wp_check_filetype_and_ext($tmp, $name, self::allowed_mimes([]));
        if (!is_array($checked) || ($checked['type'] ?? '') !== $expected_type ||
            ($checked['ext'] ?? '') !== $ext) {
            $file['error'] = $deny;
            return $file;
        }

        // Never trust extension or browser-provided MIME by themselves.
        if ($expected_type === 'application/pdf') {
            $fh = @fopen($tmp, 'rb');
            $header = $fh ? fread($fh, 5) : '';
            if ($fh) fclose($fh);
            if ($header !== '%PDF-') {
                $file['error'] = $deny;
                return $file;
            }
            if (function_exists('finfo_open') && function_exists('finfo_file')) {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? @finfo_file($finfo, $tmp) : false;
                if ($finfo) finfo_close($finfo);
                if ($mime !== 'application/pdf') {
                    $file['error'] = $deny;
                    return $file;
                }
            }
        } else {
            $image = @getimagesize($tmp);
            if (!is_array($image) || ($image['mime'] ?? '') !== $expected_type) {
                $file['error'] = $deny;
                return $file;
            }
        }
        return $file;
    }

    public static function block_category_creation($term, string $taxonomy, $args = []) {
        if (!Modules::on('category_creation') || $taxonomy !== 'category' || is_wp_error($term)) {
            return $term;
        }
        // Keep assigning existing categories available to normal editors.
        // This is request-time authorization, never a persistent role change.
        if (current_user_can('manage_options')) return $term;
        return new \WP_Error('nrg_category_creation_blocked',
            'Newsroom Guard: only administrators can create new categories while this policy is enabled.');
    }

    public static function after_post_saved(int $post_id, $post, bool $update, $post_before = null): void {
        self::ensure_featured_alt($post_id);
    }

    public static function rest_post_saved($post, $request, bool $creating): void {
        if (isset($post->ID)) self::ensure_featured_alt((int)$post->ID);
    }

    public static function thumbnail_meta_changed($meta_id, $object_id, $meta_key, $meta_value): void {
        if ((string)$meta_key === '_thumbnail_id') self::ensure_featured_alt((int)$object_id);
    }

    public static function ensure_featured_alt(int $post_id): void {
        if (!Modules::on('featured_alt') || $post_id <= 0) return;
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, ['post', 'page'], true)
            || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        // Publishing protections must not be bypassed to update image metadata.
        if (!current_user_can('edit_post', $post_id)) return;
        $attachment_id = (int)get_post_thumbnail_id($post_id);
        if (!$attachment_id || !wp_attachment_is_image($attachment_id)
            || !current_user_can('edit_post', $attachment_id)) return;
        $existing = trim((string)get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
        if ($existing !== '') return;
        $title = trim(wp_strip_all_tags((string)get_the_title($post_id)));
        if ($title === '') return;
        // Shared image metadata is GLOBAL to the attachment: only populate empty.
        // A deliberately supplied human-authored alt always wins.
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $title);
    }
}
