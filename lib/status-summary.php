<?php
/** Compact observations, not a security certification or web-runtime probe. */
require_once __DIR__.'/wp-policy-input.php';
require_once __DIR__.'/php-policy.php';

function ph_status_value($value) {
    $value = ph_policy_safe_text($value);
    return strlen($value) > 100 ? substr($value, 0, 97).'...' : $value;
}

function ph_status_wp($path, $areas) {
    $rows = ph_policy_read_rows($path);
    if (count($rows) !== 1) throw new RuntimeException('One selected site required');
    $p = $rows[0]['policy']; $parts = []; $review = [];
    $general = in_array('wp', $areas, true);
    if ($general) {
        $parts[] = 'WordPress: '.ph_status_value($p['environment']).'; editor '.ph_status_value($p['editor']).'; debug '.ph_status_value($p['debug']);
        if ($p['environment'] === 'PRODUCTION') {
            foreach (['debug'=>'debug enabled', 'savequeries'=>'query logging enabled', 'script_debug'=>'script debug enabled'] as $key=>$reason) {
                if ($p[$key] === 'ENABLED') $review[] = $reason;
            }
        }
        if ($p['allow_repair'] === 'ENABLED') $review[] = 'database repair endpoint enabled';
        if ($p['unfiltered_uploads'] === 'ENABLED') $review[] = 'unfiltered uploads enabled';
    }
    if ($general || in_array('locks', $areas, true)) {
        $parts[] = 'File changes: '.ph_status_value($p['file_mods']);
    }
    if ($general || in_array('updates', $areas, true)) {
        $parts[] = 'Updates: core '.ph_status_value($p['core_updates']).'; plugins '.ph_status_value($p['plugin_updates_count']).'; themes '.ph_status_value($p['theme_updates_count']);
        if ($p['updater'] === 'BLOCKED') $review[] = 'automatic updates blocked';
        if ($p['core_updates'] === 'DISABLED') $review[] = 'core automatic updates disabled';
    }
    if ($review) $parts[] = 'Review: '.implode('; ', array_unique($review));
    echo ($review ? 'REVIEW' : 'OBSERVED'), "\t", implode(' | ', $parts), "\n";
}

function ph_status_php($site, $verbose) {
    list($file, $bytes, $stat, $parsed) = ph_php_config($site, true);
    $policies = ph_php_policies(); $count = 0; $complex = false; $autoload = ph_php_has_autoload($parsed);
    foreach ($policies as $key=>$policy) {
        if (array_key_exists($key, $parsed)) { $count++; if (!is_string($parsed[$key])) $complex = true; }
    }
    if (!$verbose) {
        echo ($complex || $autoload) ? 'REVIEW' : 'UNVERIFIED', "\tPHP: ", $count, ' local directive', $count === 1 ? '' : 's', '; web effective UNKNOWN';
        if ($complex) echo '; complex value needs review';
        if ($autoload) echo '; autoload configured (text only; write review needed)';
        echo "\n"; return;
    }
    $all = ini_get_all();
    if ($autoload) echo "    REVIEW: autoload configured; no file loaded and no raw path disclosed.\n";
    echo "    PHP directive                    CLI value      Local .user.ini    Web effective\n";
    foreach ($policies as $key=>$policy) {
        $cli = isset($all[$key]) ? (string)$all[$key]['local_value'] : 'UNSUPPORTED';
        $local = $parsed[$key] ?? 'UNSET';
        if (!is_string($local)) $local = 'COMPLEX';
        // Widths are bounded; full static inspection remains php status.
        printf("    %-32s %-14s %-18s UNKNOWN\n", $key, substr(ph_status_value($cli),0,14), substr(ph_status_value($local),0,18));
    }
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    try {
        $action = $argv[1] ?? '';
        if ($action === 'label' && $argc === 3) echo ph_policy_safe_text($argv[2]);
        elseif ($action === 'wp' && $argc === 4) ph_status_wp($argv[2], explode(',', $argv[3]));
        elseif ($action === 'php' && $argc === 3) ph_status_php($argv[2], false);
        elseif ($action === 'php-details' && $argc === 3) ph_status_php($argv[2], true);
        else throw new RuntimeException('Invalid summary arguments');
    } catch (Throwable $e) {
        fwrite(STDERR, "INCOMPLETE: selected policy data could not be read safely.\n"); exit(2);
    }
}
