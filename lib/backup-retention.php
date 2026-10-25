<?php
/** Manual backup retention for PressHarden's own private state. PHP 7.4.
 * Covers wp-config transaction recovery copies and per-category backups. Preview
 * never deletes. Only backups whose metadata records success are eligible, and
 * the newest backup per site and category is always kept. */
require_once __DIR__.'/ops-safety.php';

const PH_RETENTION_MAX_ENTRIES = 20000;

function ph_retention_names($dir) {
    $h = @opendir($dir); if ($h === false) throw new RuntimeException('cannot read backup directory');
    $names = []; $n = 0;
    try {
        while (false !== ($name = readdir($h))) {
            if ($name === '.' || $name === '..') continue;
            if (++$n > PH_RETENTION_MAX_ENTRIES) throw new RuntimeException('backup listing limit');
            $names[] = $name;
        }
    } finally { closedir($h); }
    sort($names, SORT_STRING);
    return $names;
}
/** Walk a backup directory without following links; returns its regular-file bytes. */
function ph_retention_bytes($dir, $depth = 0) {
    if ($depth > 4) throw new RuntimeException('unexpected backup layout');
    $bytes = 0;
    foreach (ph_retention_names($dir) as $name) {
        $p = $dir.'/'.$name; $s = @lstat($p);
        if (!$s) throw new RuntimeException('backup entry disappeared');
        $type = $s['mode'] & 0170000;
        if ($type === 0040000) $bytes += ph_retention_bytes($p, $depth + 1);
        elseif ($type === 0100000 && $s['nlink'] === 1) $bytes += $s['size'];
        else throw new RuntimeException('link or special file in backup');
    }
    return $bytes;
}
function ph_retention_remove($dir, $depth = 0) {
    if ($depth > 4) throw new RuntimeException('unexpected backup layout');
    foreach (ph_retention_names($dir) as $name) {
        $p = $dir.'/'.$name; $s = @lstat($p);
        if (!$s) throw new RuntimeException('backup entry disappeared');
        $type = $s['mode'] & 0170000;
        if ($type === 0040000) ph_retention_remove($p, $depth + 1);
        elseif ($type === 0100000) { if (!@unlink($p)) throw new RuntimeException('backup file removal failed'); }
        else throw new RuntimeException('link or special file in backup');
    }
    if (!@rmdir($dir)) throw new RuntimeException('backup directory removal failed');
}
/** Why a backup is not known complete, or '' when it is. */
function ph_retention_incomplete($category, $dir) {
    $meta = $dir.'/meta.json';
    try { ph_ops_regular($meta, 4194304); } catch (Throwable $e) { return 'metadata missing or unsafe'; }
    $m = json_decode((string)file_get_contents($meta), true);
    if (!is_array($m)) return 'metadata unreadable';
    $status = (string)($m['status'] ?? 'unknown');
    $done = ['config-transactions'=>'COMPLETED', 'php-policy'=>'CONFIGURED_WEB_UNVERIFIED'];
    if (!isset($done[$category])) return 'no completion record for this category; prune it manually';
    if ($status !== $done[$category]) return 'status '.preg_replace('/[^A-Z_]/', '', $status);
    return '';
}
/** Site group key: wp-config transactions record the site path, backups name its hash. */
function ph_retention_group($category, $dir, $hash) {
    if ($category !== 'config-transactions') return $hash;
    $m = @json_decode((string)@file_get_contents($dir.'/meta.json'), true);
    return is_array($m) && is_string($m['site'] ?? null) ? substr(hash('sha256', $m['site']), 0, 12) : 'unknown';
}
function ph_retention_plan($state, $days, $now = null) {
    if (!is_int($days) || $days < 1 || $days > 36500) throw new RuntimeException('invalid retention days');
    $state = ph_ops_path($state);
    $out = ['eligible'=>[], 'kept'=>[], 'state'=>$state];
    $cutoff = ($now ?? time()) - $days * 86400;
    // Stores: wp-config transactions and per-category backups (for example php-policy).
    $stores = [];
    $tx = $state.'/config-transactions';
    if (is_dir($tx) && !is_link($tx)) $stores['config-transactions'] = $tx;
    $root = $state.'/backups';
    if (is_dir($root) && !is_link($root)) {
        ph_ops_path($root);
        foreach (ph_retention_names($root) as $category) {
            $cdir = $root.'/'.$category;
            if ($category !== 'config-transactions' && preg_match('/^[a-z][a-z0-9-]{0,50}$/D', $category) && !is_link($cdir) && is_dir($cdir)) $stores[$category] = $cdir;
        }
    }
    foreach ($stores as $category => $cdir) {
        ph_ops_path($cdir);
        $groups = [];
        foreach (ph_retention_names($cdir) as $name) {
            if ($category === 'config-transactions') {
                if (!preg_match('/^tx-([0-9]{8}T[0-9]{6}Z)-[0-9a-f][0-9a-f-]{0,39}$/D', $name, $m)) continue;
                $hash = '';
            } else {
                if (!preg_match('/^([0-9]{8}T[0-9]{6}Z)-([a-f0-9]{12})-[a-f0-9]{16}$/D', $name, $m)) continue;
                $hash = $m[2];
            }
            if (is_link($cdir.'/'.$name) || !is_dir($cdir.'/'.$name)) { $out['kept'][$category.'/'.$name] = 'not a plain directory'; continue; }
            $groups[ph_retention_group($category, $cdir.'/'.$name, $hash)][$name] = $m[1];
        }
        foreach ($groups as $entries) {
            ksort($entries, SORT_STRING);
            $newest = array_key_last($entries);
            foreach ($entries as $name => $stamp) {
                $id = $category.'/'.$name; $dir = $cdir.'/'.$name;
                $t = DateTime::createFromFormat('!Ymd\THis\Z', $stamp, new DateTimeZone('UTC'));
                if ($t === false || $t->getTimestamp() > $cutoff) continue;
                if ($name === $newest) { $out['kept'][$id] = 'newest backup for this site and category'; continue; }
                $why = ph_retention_incomplete($category, $dir);
                if ($why !== '') { $out['kept'][$id] = $why; continue; }
                try { $out['eligible'][$id] = ph_retention_bytes($dir); }
                catch (Throwable $e) { $out['kept'][$id] = $e->getMessage(); }
            }
        }
    }
    ksort($out['eligible'], SORT_STRING); ksort($out['kept'], SORT_STRING);
    return $out;
}
/** Remove exactly the previewed IDs after recomputing eligibility. */
function ph_retention_apply($state, $days, array $approved, $now = null) {
    $plan = ph_retention_plan($state, $days, $now);
    $ids = array_keys($plan['eligible']); sort($approved, SORT_STRING);
    if (!$approved || $ids !== $approved) throw new RuntimeException('eligible backups changed since preview; nothing removed');
    $state = $plan['state']; $log = ph_ops_dir(ph_ops_dir($state.'/backups')).'/prune-log.tsv';
    if (is_link($log) || (file_exists($log) && !is_file($log))) throw new RuntimeException('unsafe prune log');
    $removed = 0; $bytes = 0;
    foreach ($ids as $id) {
        list($category, $name) = explode('/', $id, 2);
        $base = $category === 'config-transactions' ? $state.'/config-transactions' : $state.'/backups/'.$category;
        $dir = $base.'/'.$name; $work = $base.'/.pruning-'.$name;
        if (file_exists($work) || is_link($work)) throw new RuntimeException('stale prune work directory');
        // Rename first so a partial removal never looks like a usable backup.
        if (!@rename($dir, $work)) throw new RuntimeException('cannot reserve backup for pruning');
        ph_retention_remove($work);
        clearstatcache();
        if (file_exists($work) || is_link($work) || file_exists($dir)) throw new RuntimeException('backup still present after pruning');
        $mask = umask(0077);
        $ok = @file_put_contents($log, gmdate('c')."\t".$id."\t".$plan['eligible'][$id]."\tpruned\n", FILE_APPEND | LOCK_EX);
        umask($mask);
        if ($ok === false) throw new RuntimeException('prune log write failed');
        ++$removed; $bytes += $plan['eligible'][$id];
        printf("PRUNED  %s  %d bytes\n", $id, $plan['eligible'][$id]);
    }
    return ['removed'=>$removed, 'bytes'=>$bytes];
}
if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    try {
        $mode = $argv[1] ?? '';
        if (!in_array($mode, ['preview','apply'], true) || !preg_match('/^[1-9][0-9]{0,4}$/D', $argv[3] ?? '')) throw new RuntimeException('invalid arguments');
        if ($mode === 'preview' && $argc === 4) {
            $p = ph_retention_plan($argv[2], (int)$argv[3]);
            foreach ($p['eligible'] as $id => $b) printf("ELIGIBLE\t%s\t%d\n", $id, $b);
            foreach ($p['kept'] as $id => $why) printf("KEPT\t%s\t%s\n", $id, $why);
        } elseif ($mode === 'apply' && $argc === 5) {
            ph_ops_regular($argv[4], 4194304);
            $ids = array_values(array_filter(explode("\n", (string)file_get_contents($argv[4])), 'strlen'));
            foreach ($ids as $id) if (!preg_match('~^(?:config-transactions/tx-[0-9]{8}T[0-9]{6}Z-[0-9a-f][0-9a-f-]{0,39}|[a-z][a-z0-9-]{0,50}/[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}-[a-f0-9]{16})$~D', $id)) throw new RuntimeException('invalid approval list');
            $r = ph_retention_apply($argv[2], (int)$argv[3], $ids);
            printf("Backups removed: %d; logical bytes removed: %d.\n", $r['removed'], $r['bytes']);
            echo "Logical removed bytes are not a claim of net disk space reclaimed.\n";
        } else throw new RuntimeException('invalid arguments');
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAILED: '.$e->getMessage().". Backups were retained unless listed as PRUNED.\n");
        exit(2);
    }
}
