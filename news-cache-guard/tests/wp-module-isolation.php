<?php
/**
 * Real WordPress integration check: run after turning Bot and Worker modules OFF
 * in a fresh WP-CLI process. The default cache settings should remain unchanged.
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

function check_nrg_wp(bool $pass,string $label): void {
    if (!$pass) { WP_CLI::error('FAIL: ' . $label); }
    WP_CLI::log('PASS: ' . $label);
}
check_nrg_wp(!\NCG\Modules::on('worker') && !\NCG\Modules::on('bot'), 'Core module options disabled');
check_nrg_wp(has_filter('action_scheduler_queue_runner_batch_size',
    [\NCG\WorkerGuard::class, 'action_scheduler_batch_size']) === false,
    'Disabled Worker Guard does not register scheduler cap');
check_nrg_wp(has_filter('wpseo_sitemap_entries_per_page',
    [\NCG\WorkerGuard::class, 'yoast_sitemap_entries']) === false,
    'Disabled Worker Guard does not change Yoast sitemap limit');
$before = \NCG\Settings::get_all();
$for_test = $before;
$for_test['xmlrpc_mode'] = 'disable';
update_option(\NCG\Settings::OPTION, \NCG\Settings::sanitize($for_test));
check_nrg_wp((new \NCG\Guard())->xmlrpc_enabled(true) === true,
    'Bot module OFF overrides ordinary XML-RPC restriction');
update_option(\NCG\Settings::OPTION, \NCG\Settings::sanitize($before));
check_nrg_wp(\NCG\Settings::get('origin_threshold') === $before['origin_threshold'],
    'Legacy request threshold remains unchanged');
WP_CLI::success('Module OFF isolation passed in real WordPress.');
