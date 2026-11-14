<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');

$legacy = ($argv[1] ?? '') === 'legacy';
if (!$legacy) {
    function wp_autoload_values_to_autoload() { return ['yes', 'on', 'auto-on', 'auto']; }
}
function current_user_can($cap) { return $cap === 'manage_options' && $GLOBALS['audit_allowed']; }

final class AuditDb {
    public $options = 'wp_42_options';
    public $last_error = '';
    public $quiet = false;
    public $empty = false;
    public $fail = false;
    public $queries = [];
    public function suppress_errors($quiet) {
        $previous = $this->quiet;
        $this->quiet = $quiet;
        return $previous;
    }
    public function prepare($sql, $args) {
        $this->queries[] = [$sql, $args];
        return $sql;
    }
    public function get_row($sql, $format) {
        return $this->empty ? ['bytes' => '0', 'option_count' => '0'] : ['bytes' => '1234', 'option_count' => '2'];
    }
    public function get_results($sql, $format) {
        if ($this->fail) $this->last_error = 'mock failure';
        return [['option_name' => 'example', 'bytes' => '1000']];
    }
}

require_once __DIR__ . '/../includes/class-ncg-cache-environment.php';
use NCG\CacheEnvironment;

function audit_check($ok, $label) {
    if (!$ok) throw new RuntimeException($label);
}
$wpdb = new AuditDb();
$GLOBALS['audit_allowed'] = false;
audit_check(CacheEnvironment::autoload_audit() === null && !$wpdb->queries, 'No queries without administrator permission');
$GLOBALS['audit_allowed'] = true;
$report = CacheEnvironment::autoload_audit();
audit_check($report['bytes'] === 1234 && $report['count'] === 2 && $report['largest'][0]['option_name'] === 'example', 'Returns total, count, and sizes');
audit_check(count($wpdb->queries) === 2 && !$wpdb->quiet, 'Two reads; suppression restored');
foreach ($wpdb->queries as [$sql, $flags]) {
    audit_check($flags === ($legacy ? ['yes'] : ['yes', 'on', 'auto-on', 'auto']), 'Uses WordPress autoload states');
    audit_check(strpos($sql, 'SELECT ') === 0 && strpos($sql, 'FROM wp_42_options') !== false, 'Queries the current site table');
}
audit_check(strpos($wpdb->queries[1][0], 'SELECT option_name, OCTET_LENGTH(option_value) AS bytes') === 0 &&
    strpos($wpdb->queries[1][0], 'LIMIT 20') !== false, 'Bounded metadata only');
$wpdb->fail = true;
audit_check(CacheEnvironment::autoload_audit() === null && !$wpdb->quiet, 'Read errors fail closed');
$wpdb->last_error = '';
$wpdb->empty = true;
$wpdb->queries = [];
audit_check(CacheEnvironment::autoload_audit() === ['bytes' => 0, 'count' => 0, 'largest' => []] &&
    count($wpdb->queries) === 1, 'Empty autoload set skips the second query');
echo "Autoload audit checks passed.\n";
