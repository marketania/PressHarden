<?php
// Emits only one-way digests of an allowlisted group. Raw salts are never logged.
// WP-CLI config list uses "name" in current versions and "key" in older ones.
try {
    $group = $argv[1] ?? '';
    if (!in_array($group, ['auth', 'cache'], true)) throw new RuntimeException();
    $keys = $group === 'cache' ? ['WP_CACHE_KEY_SALT'] : ['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'];
    $raw = stream_get_contents(STDIN, 65537);
    if ($raw === false || strlen($raw) > 65536) throw new RuntimeException();
    $rows = json_decode($raw, true);
    if (!is_array($rows) || count($rows) !== count($keys)) throw new RuntimeException();
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new RuntimeException();
        $name = $row['name'] ?? $row['key'] ?? null;
        if (isset($row['name'], $row['key']) && $row['name'] !== $row['key']) throw new RuntimeException();
        if (!is_string($name) || !in_array($name, $keys, true) || isset($out[$name])) throw new RuntimeException();
        if (isset($row['type']) && $row['type'] !== 'constant') throw new RuntimeException();
        if (!isset($row['value']) || !is_string($row['value']) || strlen($row['value']) < 32 || strlen($row['value']) > 4096) throw new RuntimeException();
        $out[$name] = hash('sha256', $row['value']);
    }
    if (count(array_unique($out)) !== count($keys)) throw new RuntimeException();
    ksort($out);
    echo json_encode($out), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Salt group could not be verified; values suppressed.\n");
    exit(2);
}
