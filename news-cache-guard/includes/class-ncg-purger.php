<?php

declare(strict_types=1);

namespace NCG;

if (!defined('ABSPATH')) exit;

/**
 * Purge only after WordPress finishes writing the article, metadata and terms.
 * Previously save_post + transition_post_status caused duplicate invalidations
 * for a single publication. wp_after_insert_post covers standard post saves,
 * REST updates and the wp_publish_post() cron path on supported WordPress 6.2+.
 */
final class Purger {
    public function boot(): void {
        add_action('wp_after_insert_post', [$this, 'on_after_insert'], 20, 4);
    }

    public function on_after_insert(int $postId, \WP_Post $post, bool $update, $before): void {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) return;
        // Cover new publications, published edits and publication reversals.
        // Don't purge for ordinary draft changes or autosaves.
        if ($post->post_status !== 'publish' &&
            (!$before instanceof \WP_Post || $before->post_status !== 'publish')) return;
        $this->purge_post($postId);
    }

    private function purge_post(int $postId): void {
        $settings = Settings::get_all();
        $urls = [];
        $url = get_permalink($postId);
        if (is_string($url) && $url !== '') $urls[] = $url;
        if (!empty($settings['purge_home'])) $urls[] = home_url('/');

        if (!empty($settings['purge_categories'])) {
            foreach (wp_get_post_categories($postId) as $termId) {
                $link = get_category_link((int)$termId);
                if (!is_wp_error($link) && is_string($link) && $link !== '') $urls[] = $link;
            }
        }
        if (!empty($settings['purge_feeds'])) $urls[] = get_feed_link();
        if (!empty($settings['purge_sitemap'])) {
            $urls[] = home_url('/wp-sitemap.xml');
            $urls[] = home_url('/sitemap_index.xml');
        }

        // Avoid accidental duplicate URLs in a single saved article's purge set.
        foreach (array_unique(array_filter($urls, 'is_string')) as $target) {
            LiteSpeed::purge_url($target);
        }
        Counter::set_flag('regen_post_' . $postId, 'global', 180);
        Counter::stat('fresh_regeneration_permission');
    }
}
