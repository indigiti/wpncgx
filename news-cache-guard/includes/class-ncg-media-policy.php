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
    public const MAX_UPLOAD_BYTES = 500 * 1024; // Match the requested 500 KB (512,000 bytes).
    public const MAX_LABEL = '500 KB';
    public const ERROR = 'Newsroom Guard: only JPG/JPEG and WebP images of 500 KB or smaller are allowed.';

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
            // wp_after_insert_post runs after post data and thumbnail metadata;
            // REST has its own final hook for Gutenberg's featured_media update.
            add_action('wp_after_insert_post', [self::class, 'after_post_saved'], 20, 4);
            add_action('rest_after_insert_post', [self::class, 'rest_post_saved'], 20, 3);
            add_action('rest_after_insert_page', [self::class, 'rest_post_saved'], 20, 3);
            // Handles setting a thumbnail after an initial post save.
            add_action('added_post_meta', [self::class, 'thumbnail_meta_changed'], 20, 4);
            add_action('updated_post_meta', [self::class, 'thumbnail_meta_changed'], 20, 4);
        }
    }

    public static function media_upload_notice(): void {
        if (!is_admin() || !current_user_can('upload_files')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array((string)$screen->id, ['upload','media'], true)) return;
        echo '<div class="notice notice-warning"><p><strong>' .
            esc_html(self::ERROR) . '</strong></p></div>';
    }

    public static function allowed_mimes(array $mimes): array {
        if (!Modules::on('media_uploads')) return $mimes;
        // Intentionally global when explicitly enabled; documents, PNG/GIF,
        // uploads by service users and non-JPEG sources will be rejected.
        return [
            'jpg|jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
        ];
    }

    public static function check_upload(array $file): array {
        if (!Modules::on('media_uploads')) return $file;
        if (!empty($file['error'])) return $file;

        $tmp = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
        $name = isset($file['name']) && is_string($file['name']) ? $file['name'] : '';
        if (!$name || !$tmp || !is_file($tmp) || !is_readable($tmp)) {
            $file['error'] = self::ERROR;
            return $file;
        }
        $size = filesize($tmp);
        if ($size === false || $size < 1 || $size > self::MAX_UPLOAD_BYTES) {
            $file['error'] = self::ERROR;
            return $file;
        }

        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'webp'], true)) {
            $file['error'] = self::ERROR;
            return $file;
        }
        $types = self::allowed_mimes([]);
        // WordPress inspects file contents (not the client-supplied MIME string).
        $checked = wp_check_filetype_and_ext($tmp, $name, $types);
        $expected = $extension === 'webp' ? 'image/webp' : 'image/jpeg';
        $real = @getimagesize($tmp);
        if (!is_array($checked)
            || ($checked['type'] ?? '') !== $expected
            || !in_array((string)($checked['ext'] ?? ''), ['jpg','jpeg','webp'], true)
            || !is_array($real)
            || ($real['mime'] ?? '') !== $expected) {
            $file['error'] = self::ERROR;
            return $file;
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
