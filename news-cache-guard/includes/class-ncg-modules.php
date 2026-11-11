<?php
/**
 * Small, fail-safe module switches. Existing WordPress option keys are unchanged.
 * This option only owns the bot, worker and reporting switches; editorial groups
 * continue to be owned by NCG\Editorial.
 */
declare(strict_types=1);
namespace NCG;
if (!defined('ABSPATH')) exit;

final class Modules {
    public const OPTION = 'ncg_module_switches';
    private static array $requestValues = [];
    private static bool $booted = false;

    public static function boot(): void {
        if (self::$booted) return;
        self::$booted = true;
        foreach (['updated_option', 'added_option', 'deleted_option'] as $hook) {
            add_action($hook, [self::class, 'invalidate'], 10, 1);
        }
    }

    public static function invalidate(string $option = self::OPTION): void {
        if ($option === self::OPTION) self::$requestValues = [];
    }

    private static function blog_key(): int {
        return function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 0;
    }

    public static function defaults(): array {
        return ['bot'=>1, 'worker'=>1, 'reports'=>1, 'media_uploads'=>0, 'category_creation'=>0, 'featured_alt'=>0, 'post_history'=>1];
    }

    public static function all(): array {
        $blog = self::blog_key();
        if (isset(self::$requestValues[$blog])) return self::$requestValues[$blog];
        $value = get_option(self::OPTION, []);
        return self::$requestValues[$blog] = array_replace(self::defaults(), is_array($value) ? $value : []);
    }

    public static function on(string $name): bool {
        $all = self::all();
        return array_key_exists($name, self::defaults()) && (int)$all[$name] === 1;
    }

    public static function sanitize($raw): array {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach (self::defaults() as $name=>$default) {
            $out[$name] = empty($raw[$name]) ? 0 : 1;
        }
        return $out;
    }
}
