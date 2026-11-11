<?php
/** P2 cache TTL and atomic-failure regression, PHP 7.4+. */
declare(strict_types=1);
define('ABSPATH', __DIR__);
$GLOBALS['p2_cache'] = [];
$GLOBALS['p2_ttl'] = [];
$GLOBALS['p2_ops'] = ['incr'=>0,'add'=>0,'get'=>0,'set'=>0];
$GLOBALS['p2_enabled'] = true;
$GLOBALS['p2_incr_fail'] = false;
$GLOBALS['p2_race_add'] = false;
$GLOBALS['p2_immortal_count'] = 0;

function wp_using_ext_object_cache() { return $GLOBALS['p2_enabled']; }
function wp_cache_incr($key, $step, $group) {
    ++$GLOBALS['p2_ops']['incr'];
    if ($GLOBALS['p2_incr_fail']) return false;
    // Simulate a Redis drop-in forwarding INCRBY directly. On a missing
    // key, Redis creates the counter with NO TTL! The plugin must never
    // execute this operation for a missing key.
    if (!isset($GLOBALS['p2_cache'][$group][$key])) {
        $GLOBALS['p2_cache'][$group][$key] = $step;
        $GLOBALS['p2_ttl'][$group][$key] = 0;
        ++$GLOBALS['p2_immortal_count'];
        return $step;
    }
    return $GLOBALS['p2_cache'][$group][$key] += $step;
}
function wp_cache_add($key, $value, $group, $ttl) {
    ++$GLOBALS['p2_ops']['add'];
    if ($GLOBALS['p2_race_add']) {
        $GLOBALS['p2_race_add'] = false;
        // Another worker inserted a counter before our ADD.
        $GLOBALS['p2_cache'][$group][$key] = 1;
        $GLOBALS['p2_ttl'][$group][$key] = $ttl;
        return false;
    }
    if (isset($GLOBALS['p2_cache'][$group][$key])) return false;
    $GLOBALS['p2_cache'][$group][$key] = $value;
    $GLOBALS['p2_ttl'][$group][$key] = $ttl;
    return true;
}
function wp_cache_get($key, $group) {
    ++$GLOBALS['p2_ops']['get'];
    return $GLOBALS['p2_cache'][$group][$key] ?? false;
}
function wp_cache_set($key, $value, $group, $ttl) {
    ++$GLOBALS['p2_ops']['set'];
    $GLOBALS['p2_cache'][$group][$key] = $value;
    $GLOBALS['p2_ttl'][$group][$key] = $ttl;
    return true;
}
function p2_assert(bool $valid, string $message): void {
    if (!$valid) {fwrite(STDERR,'FAIL: '.$message."\n");exit(1);}
    echo 'PASS: '.$message."\n";
}
function p2_reset_ops(): void {
    $GLOBALS['p2_ops']=['incr'=>0,'add'=>0,'get'=>0,'set'=>0];
}
require_once __DIR__.'/../includes/class-ncg-counter.php';

p2_reset_ops();
p2_assert(\NCG\Counter::increment('p2-first','editor',60)===1,
    'Cold counter created with an expiring atomic ADD');
p2_assert($GLOBALS['p2_ops']['add']===1 && $GLOBALS['p2_ops']['incr']===0,
    'Cold counter does not invoke Redis INCRBY on a missing key');
p2_reset_ops();
p2_assert(\NCG\Counter::increment('p2-first','editor',60)===2,
    'Warm counter increments atomically');
p2_assert($GLOBALS['p2_ops']['add']===1 && $GLOBALS['p2_ops']['incr']===1,
    'Generic object-cache implementation preserves TTL-safe ADD-first flow');

p2_reset_ops();
$GLOBALS['p2_race_add'] = true;
p2_assert(\NCG\Counter::increment('p2-race','editor',60)===2,
    'Atomic ADD losing a concurrency race safely increments winner count');
p2_assert($GLOBALS['p2_ops']['set']===0 &&
    $GLOBALS['p2_ops']['add']===1 && $GLOBALS['p2_ops']['incr']===1,
    'Race remains atomic without GET+SET');

p2_reset_ops();
p2_assert(\NCG\Counter::stat('p2-stat')===1,'Daily stat created with an expiry');
p2_assert($GLOBALS['p2_ops']['incr']===0,
    'Daily stats do not create immortal Redis keys on first use');
p2_assert(\NCG\Counter::stat('p2-stat')===2,'Daily stat increment preserved');

p2_reset_ops();
p2_assert(\NCG\Counter::increment_unique('p2-unique','person','/a',60)===1,
    'Unique URL marker and count initialized');
p2_assert(\NCG\Counter::increment_unique('p2-unique','person','/b',60)===2,
    'Second unique URL counted atomically');
p2_assert(\NCG\Counter::increment_unique('p2-unique','person','/b',60)===2,
    'Repeat URL does not increment unique counter');

p2_assert($GLOBALS['p2_immortal_count']===0,
    'No missing-key Redis INCRBY occurred despite object-cache behavior');
foreach ($GLOBALS['p2_ttl'] as $keys) {
    foreach ($keys as $ttl) p2_assert($ttl>0, 'Every allocated counter and marker carries an expiry');
}

p2_reset_ops();
$GLOBALS['p2_incr_fail']=true;
p2_assert(\NCG\Counter::increment('p2-first','editor',60)===0,
    'Redis increment failure remains fail-open');
p2_assert($GLOBALS['p2_ops']['set']===0,
    'No unsafe non-atomic GET+SET on backend failure');
$GLOBALS['p2_incr_fail']=false;
p2_assert(\NCG\Counter::increment('p2-first','editor',60)===3,
    'Failure did not corrupt existing count');

p2_reset_ops();
$GLOBALS['p2_enabled']=false;
p2_assert(\NCG\Counter::increment('disabled','person',60)===0 &&
    \NCG\Counter::stat('disabled')===0,
    'No persistent object cache preserves existing fail-open contract');
p2_assert(array_sum($GLOBALS['p2_ops'])===0,
    'Disabled persistent cache makes zero backend calls');

echo "All P2 counter expiry and atomic-safety checks passed.\n";
