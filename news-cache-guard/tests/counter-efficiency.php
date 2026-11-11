<?php
/** Standalone counter hot-path, races and failure-contract tests (PHP 7.4+). */
declare(strict_types=1);
define('ABSPATH', __DIR__);
$GLOBALS['p2_cache'] = [];
$GLOBALS['p2_ops'] = ['incr'=>0,'add'=>0,'get'=>0,'set'=>0];
$GLOBALS['p2_enabled'] = true;
$GLOBALS['p2_incr_fail'] = false;
$GLOBALS['p2_race_add'] = false;
function wp_using_ext_object_cache() { return $GLOBALS['p2_enabled']; }
function wp_cache_incr($key, $step, $group) {
    ++$GLOBALS['p2_ops']['incr'];
    if ($GLOBALS['p2_incr_fail'] || !isset($GLOBALS['p2_cache'][$group][$key])) return false;
    return $GLOBALS['p2_cache'][$group][$key] += $step;
}
function wp_cache_add($key, $value, $group, $ttl) {
    ++$GLOBALS['p2_ops']['add'];
    if ($GLOBALS['p2_race_add']) {
        $GLOBALS['p2_race_add'] = false;
        $GLOBALS['p2_cache'][$group][$key] = 1; // Another worker won the initial ADD.
        return false;
    }
    if (isset($GLOBALS['p2_cache'][$group][$key])) return false;
    $GLOBALS['p2_cache'][$group][$key] = $value;
    return true;
}
function wp_cache_get($key, $group) {
    ++$GLOBALS['p2_ops']['get'];
    return $GLOBALS['p2_cache'][$group][$key] ?? false;
}
function wp_cache_set($key, $value, $group, $ttl) {
    ++$GLOBALS['p2_ops']['set'];
    $GLOBALS['p2_cache'][$group][$key] = $value; return true;
}
function p2_assert(bool $valid, string $message): void {
    if (!$valid) {fwrite(STDERR, 'FAIL: '.$message."\n");exit(1);}
    echo 'PASS: '.$message."\n";
}
function p2_reset_ops(): void {$GLOBALS['p2_ops']=['incr'=>0,'add'=>0,'get'=>0,'set'=>0];}
require_once __DIR__.'/../includes/class-ncg-counter.php';

p2_reset_ops();
p2_assert(\NCG\Counter::increment('p2-first','editor',60)===1, 'Initial counter safely created by ADD');
p2_assert($GLOBALS['p2_ops']['incr']===1 && $GLOBALS['p2_ops']['add']===1,
    'Cold key uses INCR then ADD');
p2_reset_ops();
p2_assert(\NCG\Counter::increment('p2-first','editor',60)===2, 'Existing counter increments atomically');
p2_assert($GLOBALS['p2_ops']['incr']===1 && $GLOBALS['p2_ops']['add']===0 &&
    $GLOBALS['p2_ops']['get']===0 && $GLOBALS['p2_ops']['set']===0,
    'Hot key takes exactly one cache operation, no GET+SET');

p2_reset_ops();
$GLOBALS['p2_race_add']=true;
p2_assert(\NCG\Counter::increment('p2-race','editor',60)===2,
    'Race between INCR and ADD recovered using atomic retry');
p2_assert($GLOBALS['p2_ops']['incr']===2 && $GLOBALS['p2_ops']['add']===1 &&
    $GLOBALS['p2_ops']['set']===0,'Lost ADD race does not replace atomic increments');

p2_reset_ops();
p2_assert(\NCG\Counter::stat('p2-stat')===1,'First daily stat initialized');
p2_reset_ops();
p2_assert(\NCG\Counter::stat('p2-stat')===2,'Subsequent daily stat increments');
p2_assert($GLOBALS['p2_ops']['incr']===1 && $GLOBALS['p2_ops']['add']===0,
    'High-volume daily stats use single atomic cache operation');

p2_reset_ops();
p2_assert(\NCG\Counter::increment_unique('p2-unique','person','/a',60)===1,
    'First unique path increments count');
p2_reset_ops();
p2_assert(\NCG\Counter::increment_unique('p2-unique','person','/b',60)===2,
    'Second distinct path increments count');
p2_assert($GLOBALS['p2_ops']['incr']===1 && $GLOBALS['p2_ops']['add']===1,
    'Second distinct path adds only marker and performs one counter INCR');
p2_reset_ops();
p2_assert(\NCG\Counter::increment_unique('p2-unique','person','/b',60)===2,
    'Repeated unique URL does not increase count');
p2_assert($GLOBALS['p2_ops']['incr']===0 && $GLOBALS['p2_ops']['get']===1,
    'Repeat marker uses read-only count');

p2_reset_ops();
$GLOBALS['p2_incr_fail']=true;
p2_assert(\NCG\Counter::increment('p2-first','editor',60)===0,
    'Persistent backend increment failure fails open');
p2_assert($GLOBALS['p2_ops']['set']===0 &&
    $GLOBALS['p2_ops']['incr']===2 && $GLOBALS['p2_ops']['add']===1,
    'Failed INCR never falls back to unsafe GET+SET');
$GLOBALS['p2_incr_fail']=false;
p2_assert(\NCG\Counter::increment('p2-first','editor',60)===3,
    'Failed increment did not corrupt existing counter');

p2_reset_ops();
$GLOBALS['p2_enabled']=false;
p2_assert(\NCG\Counter::increment('disabled','person',60)===0 &&
    \NCG\Counter::stat('disabled')===0,
    'Without persistent object cache counters retain existing fail-open policy');
p2_assert(array_sum($GLOBALS['p2_ops'])===0,
    'Without external object cache there are zero cache calls');
echo "All P2 cache counter resource-efficiency checks passed.\n";
