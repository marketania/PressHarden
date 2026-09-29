<?php
/** Validate inert policy records before normalized logging or dashboard output. */
require_once __DIR__.'/ops-safety.php';

function ph_policy_keys() {
    return explode(' ', 'file_mods editor core_updates plugin_updates plugin_updates_count theme_updates theme_updates_count updater updater_blockers cron recovery environment development debug debug_log debug_display savequeries script_debug force_ssl_admin wp_cache revisions trash_days autosave_interval wp_memory_limit wp_max_memory_limit db_charset db_collate home_override siteurl_override cookie_domain fs_method allow_repair unfiltered_uploads unfiltered_html http_block_external');
}
function ph_policy_snapshot_identity($stat) {
    if (!is_array($stat)) throw new RuntimeException('Missing source identity');
    $out = [];
    foreach (['dev','ino','mode','uid','gid','nlink','size','mtime','ctime'] as $key) $out[$key] = $stat[$key];
    return $out;
}
function ph_policy_read_input($path, $limit) {
    clearstatcache();
    $before = ph_policy_snapshot_identity(ph_ops_regular($path, $limit));
    $handle = @fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Unreadable policy input');
    try {
        if (ph_policy_snapshot_identity(fstat($handle)) !== $before) throw new RuntimeException('Changed policy input');
        $text = stream_get_contents($handle, $limit + 1);
        clearstatcache();
        if ($text === false || strlen($text) > $limit || strlen($text) !== $before['size']
            || ph_policy_snapshot_identity(fstat($handle)) !== $before
            || ph_policy_snapshot_identity(ph_ops_regular($path, $limit)) !== $before) {
            throw new RuntimeException('Incomplete or changed policy input');
        }
        return $text;
    } finally { fclose($handle); }
}
function ph_policy_validate_record($text, $expected = null) {
    if (strlen($text) > 65536) throw new RuntimeException('Policy record too large');
    $row = json_decode($text, true, 8);
    if (!is_array($row) || count($row) !== 2 || !isset($row['site'], $row['policy'])
        || !is_string($row['site']) || strlen($row['site']) > 4096
        || !preg_match('/^[A-Za-z0-9._\/-]+$/D', $row['site']) || !is_array($row['policy'])
        || ($expected !== null && $row['site'] !== $expected)) {
        throw new RuntimeException('Invalid or unrelated policy record');
    }
    $keys = ph_policy_keys();
    if (count($row['policy']) !== count($keys)) throw new RuntimeException('Incomplete or unexpected policy fields');
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row['policy'])) throw new RuntimeException('Missing policy field');
        $value = $row['policy'][$key];
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || strlen((string)$value) > 1024) {
            throw new RuntimeException('Invalid policy field value');
        }
    }
    return $row;
}
function ph_policy_read_rows($path) {
    $text = ph_policy_read_input($path, 16*1024*1024);
    $offset = 0; $length = strlen($text); $rows = []; $sites = [];
    while ($offset < $length) {
        $end = strpos($text, "\n", $offset);
        if ($end === false) $end = $length;
        if ($end - $offset > 65536) throw new RuntimeException('Policy record too large');
        $line = substr($text, $offset, $end - $offset); $offset = $end + 1;
        if (trim($line, " \t\r") === '') continue;
        $row = ph_policy_validate_record($line);
        if (isset($sites[$row['site']])) throw new RuntimeException('Duplicate policy site');
        $sites[$row['site']] = true; $rows[] = $row;
        if (count($rows) > 10000) throw new RuntimeException('Policy row limit exceeded');
    }
    if (!$rows) throw new RuntimeException('No policy snapshots');
    return $rows;
}
function ph_policy_safe_text($text) {
    $text = (string)$text;
    if (preg_match('//u', $text) !== 1) {
        return preg_replace_callback('/[\x00-\x1f\x7f-\xff]/', function ($match) {
            return sprintf('\\x%02X', ord($match[0]));
        }, $text);
    }
    return preg_replace_callback('/[\x00-\x1f\x7f-\x9f\x{061c}\x{200e}\x{200f}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', function ($match) {
        return strlen($match[0]) === 1 ? sprintf('\\x%02X', ord($match[0])) : substr(json_encode($match[0]), 1, -1);
    }, $text);
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    try {
        if ($argc !== 4 || $argv[1] !== 'record') throw new RuntimeException('Invalid record request');
        $row = ph_policy_validate_record(ph_policy_read_input($argv[2], 65536), $argv[3]);
        $json = json_encode($row, JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new RuntimeException('Cannot normalize policy');
        echo $json, "\n";
    } catch (Throwable $e) {
        // Never echo unexpected keys, values, labels or raw provider diagnostics.
        fwrite(STDERR, "INCOMPLETE: policy snapshot schema, source or selected-site identity could not be verified.\n");
        exit(2);
    }
}
