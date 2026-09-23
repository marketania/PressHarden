<?php
// Emits only one-way digests of an allowlisted group. Raw salts are never logged.
try {
    $keys=($argv[1]??'')==='cache'?['WP_CACHE_KEY_SALT']:['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'];
    $raw=stream_get_contents(STDIN,65537);if(strlen($raw)>65536)throw new RuntimeException();
    $rows=json_decode($raw,true);if(!is_array($rows)||count($rows)!==count($keys))throw new RuntimeException();
    $out=[];
    foreach($rows as $row) {
        if(!is_array($row)||!isset($row['key'],$row['value'])||!in_array($row['key'],$keys,true)||isset($out[$row['key']])||!is_string($row['value'])||strlen($row['value'])<32||strlen($row['value'])>4096)throw new RuntimeException();
        $out[$row['key']]=hash('sha256',$row['value']);
    }
    if(count(array_unique($out))!==count($keys))throw new RuntimeException();
    ksort($out);echo json_encode($out),"\n";
} catch(Throwable $e) { fwrite(STDERR,"Salt group could not be verified; values suppressed.\n");exit(2); }
