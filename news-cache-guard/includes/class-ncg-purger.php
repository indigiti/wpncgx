<?php

declare(strict_types=1);

namespace NCG;

if (!defined('ABSPATH')) exit;

final class Purger {
    public function boot(): void {
        add_action('save_post', [$this, 'on_save_post'], 20, 3);
        add_action('transition_post_status', [$this, 'on_transition'], 20, 3);
    }

    public function on_save_post(int $postId, \WP_Post $post, bool $update): void {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) return;
        if ($post->post_status !== 'publish') return;
        if (!post_type_supports($post->post_type, 'title')) return;
        $this->purge_post($postId);
    }

    public function on_transition(string $new, string $old, \WP_Post $post): void {
        if ($new === $old) return;
        if ($new === 'publish' || $old === 'publish') $this->purge_post((int)$post->ID);
    }

    private function purge_post(int $postId): void {
        $url = get_permalink($postId);
        if (is_string($url)) LiteSpeed::purge_url($url);
        if ((int)Settings::get('purge_home', 1) === 1) LiteSpeed::purge_url(home_url('/'));

        if ((int)Settings::get('purge_categories', 1) === 1) {
            foreach (wp_get_post_categories($postId) as $termId) {
                $link = get_category_link((int)$termId);
                if (!is_wp_error($link)) LiteSpeed::purge_url((string)$link);
            }
        }
        if ((int)Settings::get('purge_feeds', 1) === 1) {
            LiteSpeed::purge_url(get_feed_link());
        }
        if ((int)Settings::get('purge_sitemap', 1) === 1) {
            LiteSpeed::purge_url(home_url('/wp-sitemap.xml'));
            LiteSpeed::purge_url(home_url('/sitemap_index.xml'));
        }
        Counter::set_flag('regen_post_' . $postId, 'global', 180);
        Counter::stat('fresh_regeneration_permission');
    }
}
