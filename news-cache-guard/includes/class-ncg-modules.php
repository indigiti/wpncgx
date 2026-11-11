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

    public static function defaults(): array {
        return ['bot'=>1, 'worker'=>1, 'reports'=>1];
    }

    public static function all(): array {
        $value = get_option(self::OPTION, []);
        return array_replace(self::defaults(), is_array($value) ? $value : []);
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
