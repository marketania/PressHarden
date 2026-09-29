<?php
/** Summarize normalized, allowlisted PressHarden WordPress policy snapshots. */

require_once __DIR__.'/wp-policy-input.php';
try {
    if ($argc < 2 || $argc > 3) throw new RuntimeException('Invalid summary request');
    $mode = $argv[2] ?? 'fleet';
    if (!in_array($mode, ['single','fleet'], true)) throw new RuntimeException('Invalid summary mode');
    $rows = ph_policy_read_rows($argv[1]);
    if ($mode === 'single' && count($rows) !== 1) throw new RuntimeException('Expected one site');
} catch (Throwable $e) {
    fwrite(STDERR, "INCOMPLETE: policy snapshots could not be verified; no dashboard produced.\n");
    exit(2);
}

$labels = array(
    'file_mods' => 'File modifications',
    'editor' => 'Dashboard editor',
    'core_updates' => 'Core auto-updates',
    'plugin_updates' => 'Plugin auto-updates',
    'theme_updates' => 'Theme auto-updates',
    'updater' => 'Automatic updater',
    'cron' => 'WP-Cron',
    'recovery' => 'Recovery Mode',
    'environment' => 'Environment',
    'development' => 'Development Mode',
    'debug' => 'Debug',
    'debug_log' => 'Debug Log',
    'debug_display' => 'Debug Display',
    'savequeries' => 'Query logging',
    'script_debug' => 'Script Debug',
    'force_ssl_admin' => 'Force SSL Admin',
    'wp_cache' => 'WP Cache',
    'revisions' => 'Post revisions',
    'trash_days' => 'Trash retention',
    'autosave_interval' => 'Autosave interval',
    'wp_memory_limit' => 'WP memory limit',
    'wp_max_memory_limit' => 'WP max memory limit',
    'db_charset' => 'DB charset',
    'db_collate' => 'DB collation',
    'home_override' => 'WP_HOME override',
    'siteurl_override' => 'WP_SITEURL override',
    'cookie_domain' => 'Cookie domain override',
    'fs_method' => 'Filesystem method',
    'allow_repair' => 'DB repair endpoint',
    'unfiltered_uploads' => 'Unfiltered uploads',
    'unfiltered_html' => 'Unfiltered HTML restriction',
    'http_block_external' => 'External HTTP blocking',
);

function pwps_val($row, $key) {
    if (!array_key_exists($key, $row['policy'])) return null;
    $v = $row['policy'][$key];
    return is_string($v) || is_int($v) || is_float($v) ? (string) $v : null;
}
function pwps_display_value($row, $key) {
    $v = pwps_val($row, $key);
    if ($v === null) return null;
    if ($key === 'plugin_updates') {
        $c = pwps_val($row, 'plugin_updates_count');
        if ($c !== null) $v .= ' (' . $c . ')';
    } elseif ($key === 'theme_updates') {
        $c = pwps_val($row, 'theme_updates_count');
        if ($c !== null) $v .= ' (' . $c . ')';
    } elseif ($key === 'updater' && $v === 'BLOCKED') {
        $b = pwps_val($row, 'updater_blockers');
        if ($b !== null && $b !== '') $v .= ' (' . $b . ')';
    }
    return $v;
}
function pwps_mode_value($rows, $key) {
    $counts = array();
    foreach ($rows as $row) {
        $v = pwps_val($row, $key);
        if ($v === null) return array(null, 0, true, array());
        if (!isset($counts[$v])) $counts[$v] = 0;
        $counts[$v]++;
    }
    arsort($counts, SORT_NUMERIC);
    $values = array_keys($counts);
    $top = $values[0];
    $topCount = $counts[$top];
    $tie = isset($values[1]) && $counts[$values[1]] === $topCount;
    return array($tie ? null : $top, $topCount, $tie, $counts);
}
function pwps_safe_piece($s) {
    return ph_policy_safe_text($s);
}

if ($mode === 'single') {
    if (count($rows) !== 1) {
        fwrite(STDERR, "INCOMPLETE: single policy display received multiple snapshots\n");
        exit(2);
    }
    $row = $rows[0];
    $groups = array(
        'Security' => array('file_mods','editor','recovery','force_ssl_admin','allow_repair','unfiltered_uploads','unfiltered_html','http_block_external'),
        'Updates' => array('core_updates','plugin_updates','theme_updates','updater'),
        'Runtime' => array('cron','environment','development','debug','debug_log','debug_display','savequeries','script_debug','wp_cache'),
        'Retention / resources' => array('revisions','trash_days','autosave_interval','wp_memory_limit','wp_max_memory_limit'),
        'Configuration posture' => array('db_charset','db_collate','home_override','siteurl_override','cookie_domain','fs_method'),
    );
    echo "SITE\t", pwps_safe_piece($row['site']), "\n";
    foreach ($groups as $group => $keys) {
        echo "GROUP\t", $group, "\n";
        foreach ($keys as $key) {
            $v = pwps_display_value($row, $key);
            if ($v === null) {
                fwrite(STDERR, "INCOMPLETE: policy field missing: $key\n");
                exit(2);
            }
            echo "FIELD\t", pwps_safe_piece($labels[$key]), "\t", pwps_safe_piece($v), "\n";
        }
    }
    exit(0);
}
if ($mode !== 'fleet') {
    fwrite(STDERR, "INCOMPLETE: unknown policy display mode\n");
    exit(2);
}

$baselineGroups = array(
    'Security' => array('file_mods','editor','recovery','force_ssl_admin','allow_repair','unfiltered_uploads'),
    'Updates' => array('core_updates','plugin_updates','theme_updates','updater'),
    'Runtime' => array('cron','environment','development','debug','wp_cache'),
);
$baseline = array();
$mixed = array();
$total = count($rows);
echo "COUNT\t$total\n";
foreach ($baselineGroups as $group => $keys) {
    $parts = array();
    foreach ($keys as $key) {
        list($base, $count, $tie, $counts) = pwps_mode_value($rows, $key);
        if ($tie || $base === null) {
            $baseline[$key] = null;
            $cparts = array();
            foreach ($counts as $value => $n) $cparts[] = pwps_safe_piece($value) . '=' . $n;
            $mixed[] = $labels[$key] . ': ' . implode(', ', $cparts);
            $parts[] = $labels[$key] . '=MIXED';
        } else {
            $baseline[$key] = $base;
            $parts[] = $labels[$key] . '=' . $base . ' (' . $count . '/' . $total . ')';
        }
    }
    echo "BASELINE\t", $group, "\t", pwps_safe_piece(implode(' | ', $parts)), "\n";
}
foreach ($mixed as $m) echo "MIXED\t", pwps_safe_piece($m), "\n";

$diffSites = 0;
foreach ($rows as $row) {
    $parts = array();
    foreach ($baselineGroups as $keys) {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $baseline) || $baseline[$key] === null) continue;
            $v = pwps_val($row, $key);
            if ($v === null) {
                fwrite(STDERR, "INCOMPLETE: policy field missing: $key\n");
                exit(2);
            }
            if ($v !== $baseline[$key]) {
                $shown = pwps_display_value($row, $key);
                $parts[] = $labels[$key] . '=' . $shown . ' (baseline ' . $baseline[$key] . ')';
            }
        }
    }
    if ($parts) {
        $diffSites++;
        echo "DIFF\t", pwps_safe_piece($row['site']), "\t", pwps_safe_piece(implode('; ', $parts)), "\n";
    }
}
echo "DIFFCOUNT\t$diffSites\n";
