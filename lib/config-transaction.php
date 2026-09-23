<?php
// PressHarden transactional wp-config mutation helper.
//
// WP-CLI edits a private staged copy via --config-file. Only after that copy
// passes exact value verification and the live source is revalidated are the
// staged bytes atomically published to the live wp-config.php.
// PHP 7.4 compatible. No WordPress bootstrap is performed by this helper.

const PHCT_MAX_CONFIG = 4194304; // 4 MiB
const PHCT_MAX_OUTPUT = 65536;   // 64 KiB

final class PressHardenConfigTxError extends RuntimeException {}

function phct_fail($message) {
    throw new PressHardenConfigTxError($message);
}

function phct_text($value, $allowEmpty = false, $max = 4096) {
    $value = (string)$value;
    if ((!$allowEmpty && $value === '') || strlen($value) > $max
        || preg_match('/[\x00-\x1F\x7F]/', $value)) {
        phct_fail('invalid text');
    }
    return $value;
}

function phct_key($key) {
    $key = phct_text($key, false, 64);
    $allowed = array(
        'DISALLOW_FILE_MODS',
        'DISALLOW_FILE_EDIT',
        'DISABLE_WP_CRON',
        'WP_DISABLE_FATAL_ERROR_HANDLER',
        'WP_DEBUG', 'WP_DEBUG_DISPLAY', 'WP_DEBUG_LOG', 'SCRIPT_DEBUG', 'SAVEQUERIES',
        'FORCE_SSL_ADMIN',
        'ALTERNATE_WP_CRON',
        'WP_ENVIRONMENT_TYPE',
        'WP_DEVELOPMENT_MODE',
        'WP_AUTO_UPDATE_CORE',
    );
    if (!in_array($key, $allowed, true)) {
        phct_fail('unsupported wp-config key');
    }
    return $key;
}

function phct_validate_request($key, $kind, $value) {
    if ($kind !== 'bool' && $kind !== 'string') {
        phct_fail('invalid value kind');
    }
    if ($kind === 'bool') {
        if ($value !== 'true' && $value !== 'false') {
            phct_fail('invalid boolean value');
        }
        if (in_array($key, array('WP_ENVIRONMENT_TYPE', 'WP_DEVELOPMENT_MODE'), true)) {
            phct_fail('invalid boolean key');
        }
        return;
    }

    $value = phct_text($value, true, 512);
    if ($key === 'WP_ENVIRONMENT_TYPE') {
        if (!in_array($value, array('production', 'staging', 'development', 'local'), true)) {
            phct_fail('invalid environment value');
        }
        return;
    }
    if ($key === 'WP_DEVELOPMENT_MODE') {
        if (!in_array($value, array('', 'core', 'plugin', 'theme', 'all'), true)) {
            phct_fail('invalid development value');
        }
        return;
    }
    if ($key === 'WP_AUTO_UPDATE_CORE') {
        if ($value !== 'minor') {
            phct_fail('invalid core update value');
        }
        return;
    }
    phct_fail('invalid string key');
}

function phct_plain_dir($path, $create = false) {
    $path = rtrim(phct_text($path, false, 8192), '/');
    if ($path === '') {
        $path = '/';
    }
    if (is_link($path)) {
        phct_fail('unsafe state directory');
    }
    if (!file_exists($path)) {
        if (!$create) {
            phct_fail('state directory missing');
        }
        $oldMask = umask(0077);
        try {
            $ok = @mkdir($path, 0700, true);
        } finally {
            umask($oldMask);
        }
        if (!$ok && !is_dir($path)) {
            phct_fail('cannot create state directory');
        }
    }
    $stat = @lstat($path);
    if (!$stat || (($stat['mode'] & 0170000) !== 0040000) || is_link($path)) {
        phct_fail('unsafe state directory');
    }
    return $path;
}

function phct_regular($path, $max = PHCT_MAX_CONFIG, $what = 'wp-config.php') {
    if (is_link($path)) {
        phct_fail('unsafe '.$what);
    }
    $stat = @lstat($path);
    if (!$stat || (($stat['mode'] & 0170000) !== 0100000)
        || (int)$stat['nlink'] !== 1 || (int)$stat['size'] < 0 || (int)$stat['size'] > $max) {
        phct_fail($what.' is not a safe regular single-link file');
    }
    return $stat;
}

function phct_snapshot($path, $what = 'wp-config.php') {
    $before = phct_regular($path, PHCT_MAX_CONFIG, $what);
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        phct_fail($what.' is unreadable');
    }
    try {
        $actual = @fstat($handle);
        if (!$actual || $actual['dev'] !== $before['dev'] || $actual['ino'] !== $before['ino']
            || ($actual['mode'] & 0170000) !== 0100000 || (int)$actual['nlink'] !== 1) {
            phct_fail($what.' changed while opening');
        }
        $bytes = '';
        while (!feof($handle)) {
            $chunk = @fread($handle, 65536);
            if ($chunk === false) {
                phct_fail($what.' read failed');
            }
            $bytes .= $chunk;
            if (strlen($bytes) > PHCT_MAX_CONFIG) {
                phct_fail($what.' exceeds transaction limit');
            }
        }
    } finally {
        @fclose($handle);
    }

    clearstatcache(true, $path);
    $after = phct_regular($path, PHCT_MAX_CONFIG, $what);
    if ($after['dev'] !== $before['dev'] || $after['ino'] !== $before['ino']
        || (int)$after['size'] !== strlen($bytes)) {
        phct_fail($what.' changed while reading');
    }

    return array(
        'bytes' => $bytes,
        'sha256' => hash('sha256', $bytes),
        'size' => strlen($bytes),
        'mode' => ($before['mode'] & 07777),
        'uid' => (int)$before['uid'],
        'gid' => (int)$before['gid'],
        'dev' => $before['dev'],
        'ino' => $before['ino'],
    );
}

function phct_same($a, $b, $identity = true) {
    if ($a['sha256'] !== $b['sha256'] || $a['size'] !== $b['size'] || $a['mode'] !== $b['mode']
        || $a['uid'] !== $b['uid'] || $a['gid'] !== $b['gid']) {
        return false;
    }
    return !$identity || ($a['dev'] === $b['dev'] && $a['ino'] === $b['ino']);
}

function phct_write_all($handle, $data) {
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = @fwrite($handle, substr($data, $offset));
        if ($written === false || $written === 0) {
            phct_fail('write failed');
        }
        $offset += $written;
    }
    if (!@fflush($handle)) {
        phct_fail('flush failed');
    }
}

function phct_private_file($path, $data, $mode = 0600) {
    $oldMask = umask(0077);
    try {
        $handle = @fopen($path, 'xb');
    } finally {
        umask($oldMask);
    }
    if ($handle === false) {
        phct_fail('cannot create private transaction file');
    }
    try {
        phct_write_all($handle, $data);
    } finally {
        if (is_resource($handle)) {
            @fclose($handle);
        }
    }
    @chmod($path, $mode);
}

function phct_atomic_json($path, $data) {
    $dir = phct_plain_dir(dirname($path), false);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        phct_fail('transaction metadata encoding failed');
    }
    $json .= "\n";
    $temp = $dir.'/.meta.'.bin2hex(random_bytes(8)).'.tmp';
    phct_private_file($temp, $json);
    if (is_link($path) || (file_exists($path) && !is_file($path))) {
        @unlink($temp);
        phct_fail('unsafe transaction metadata');
    }
    if (!@rename($temp, $path)) {
        @unlink($temp);
        phct_fail('cannot publish transaction metadata');
    }
}

function phct_lock($locksDir, $siteReal) {
    $locksDir = phct_plain_dir($locksDir, true);
    $path = $locksDir.'/'.hash('sha256', $siteReal).'.lock';
    if (is_link($path)) {
        phct_fail('unsafe config transaction lock');
    }
    if (!file_exists($path)) {
        $oldMask = umask(0077);
        try {
            $created = @fopen($path, 'x+b');
        } finally {
            umask($oldMask);
        }
        if ($created === false && !file_exists($path)) {
            phct_fail('cannot create config transaction lock');
        }
        if (is_resource($created)) {
            @fclose($created);
            @chmod($path, 0600);
        }
    }

    $expected = @lstat($path);
    if (!$expected || (($expected['mode'] & 0170000) !== 0100000)
        || (int)$expected['nlink'] !== 1 || is_link($path)) {
        phct_fail('unsafe config transaction lock');
    }
    $handle = @fopen($path, 'c+b');
    if ($handle === false) {
        phct_fail('cannot open config transaction lock');
    }
    $actual = @fstat($handle);
    if (!$actual || $actual['dev'] !== $expected['dev'] || $actual['ino'] !== $expected['ino']
        || ($actual['mode'] & 0170000) !== 0100000 || (int)$actual['nlink'] !== 1) {
        @fclose($handle);
        phct_fail('config transaction lock changed');
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        phct_fail('another PressHarden wp-config mutation is active for this site');
    }
    return $handle;
}

function phct_run_wp($wpBinary, $site, $args, $capture = false) {
    $command = array_merge(
        array($wpBinary),
        $args,
        array('--path='.$site, '--skip-plugins', '--skip-themes', '--skip-packages', '--no-color')
    );
    $null = '/dev/null';
    $temp = null;
    $stdout = array('file', $null, 'w');
    if ($capture) {
        $temp = sys_get_temp_dir().'/pressharden-config-tx.'.bin2hex(random_bytes(8));
        $oldMask = umask(0077);
        try {
            $captureHandle = @fopen($temp, 'xb');
        } finally {
            umask($oldMask);
        }
        if ($captureHandle === false) {
            return array(127, '');
        }
        @fclose($captureHandle);
        if (!@chmod($temp, 0600)) {
            @unlink($temp);
            return array(127, '');
        }
        $stdout = array('file', $temp, 'w');
    }
    $spec = array(
        0 => array('file', $null, 'r'),
        1 => $stdout,
        2 => array('file', $null, 'w'),
    );
    $process = @proc_open($command, $spec, $pipes);
    if (!is_resource($process)) {
        if ($temp !== null) {
            @unlink($temp);
        }
        return array(127, '');
    }
    $rc = @proc_close($process);
    $output = '';
    if ($capture && $temp !== null) {
        $stat = @lstat($temp);
        if (!$stat || (($stat['mode'] & 0170000) !== 0100000)
            || $stat['size'] > PHCT_MAX_OUTPUT || is_link($temp)) {
            @unlink($temp);
            return array(2, '');
        }
        $output = (string)@file_get_contents($temp, false, null, 0, PHCT_MAX_OUTPUT + 1);
        @unlink($temp);
        if (strlen($output) > PHCT_MAX_OUTPUT) {
            return array(2, '');
        }
    }
    return array((int)$rc, trim($output));
}

function phct_get($wpBinary, $site, $configFile, $key, &$ok) {
    list($rc, $output) = phct_run_wp(
        $wpBinary,
        $site,
        array('config', 'get', $key, '--type=constant', '--format=json', '--config-file='.$configFile),
        true
    );
    if ($rc !== 0) {
        $ok = false;
        return null;
    }
    $value = json_decode($output, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $ok = false;
        return null;
    }
    $ok = true;
    return $value;
}

function phct_set($wpBinary, $site, $configFile, $key, $kind, $value) {
    $args = array('config', 'set', $key, $value, '--type=constant', '--config-file='.$configFile);
    if ($kind === 'bool') {
        $args[] = '--raw';
    }
    list($rc,) = phct_run_wp($wpBinary, $site, $args, false);
    return $rc;
}

function phct_expected($kind, $value) {
    return $kind === 'bool' ? ($value === 'true') : $value;
}

function phct_publish($config, $bytes, $mode, $expectedCurrent) {
    $temp = dirname($config).'/.pressharden-config-publish.'.bin2hex(random_bytes(8)).'.tmp';
    $oldMask = umask(0077);
    try {
        $handle = @fopen($temp, 'xb');
    } finally {
        umask($oldMask);
    }
    if ($handle === false) {
        phct_fail('cannot stage live wp-config.php publication');
    }
    try {
        phct_write_all($handle, $bytes);
    } catch (Throwable $e) {
        @fclose($handle);
        @unlink($temp);
        throw $e;
    }
    @fclose($handle);
    if (!@chmod($temp, $mode)) {
        @unlink($temp);
        phct_fail('cannot preserve wp-config.php mode; live file was not modified');
    }
    $tempStat = phct_regular($temp, PHCT_MAX_CONFIG, 'publication staging file');
    if ((int)$tempStat['uid'] !== (int)$expectedCurrent['uid']
        || (int)$tempStat['gid'] !== (int)$expectedCurrent['gid']) {
        @unlink($temp);
        phct_fail('atomic replacement would change wp-config.php ownership; live file was not modified');
    }

    clearstatcache(true, $config);
    $current = phct_snapshot($config);
    if (!phct_same($current, $expectedCurrent, true)) {
        @unlink($temp);
        phct_fail('wp-config.php changed before publication; live file was not modified');
    }
    if (!@rename($temp, $config)) {
        @unlink($temp);
        phct_fail('atomic wp-config.php publication failed');
    }

    clearstatcache(true, $config);
    $published = phct_snapshot($config);
    if ($published['sha256'] !== hash('sha256', $bytes)
        || $published['size'] !== strlen($bytes) || $published['mode'] !== $mode
        || $published['uid'] !== $expectedCurrent['uid'] || $published['gid'] !== $expectedCurrent['gid']) {
        phct_fail('published wp-config.php verification failed');
    }
    return $published;
}

function phct_tx_id() {
    return 'tx-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6));
}

try {
    if ($argc !== 9 || ($argv[1] ?? '') !== 'set') {
        phct_fail('invalid arguments');
    }

    $stateDir = phct_plain_dir($argv[2], true);
    $siteInput = phct_text($argv[3], false, 8192);
    $label = phct_text($argv[4], false, 1024);
    $key = phct_key($argv[5]);
    $kind = phct_text($argv[6], false, 16);
    $value = (string)$argv[7];
    $wpInput = phct_text($argv[8], false, 8192);
    phct_validate_request($key, $kind, $value);

    $wpBinary = @realpath($wpInput);
    if ($wpBinary === false || !is_file($wpBinary) || !is_executable($wpBinary)) {
        phct_fail('unsafe WP-CLI executable');
    }
    $site = @realpath($siteInput);
    if ($site === false || !is_dir($site) || is_link($siteInput)) {
        phct_fail('unsafe WordPress site path');
    }
    $config = $site.'/wp-config.php';
    phct_regular($config);

    $txRoot = phct_plain_dir($stateDir.'/config-transactions', true);
    $locksDir = phct_plain_dir($txRoot.'/locks', true);
    $lock = phct_lock($locksDir, $site);

    try {
        $expected = phct_expected($kind, $value);
        $currentOk = false;
        $currentValue = phct_get($wpBinary, $site, $config, $key, $currentOk);
        if ($currentOk && $currentValue === $expected) {
            fwrite(STDOUT, "OK\tNOOP\t-\n");
            exit(0);
        }

        $original = phct_snapshot($config);
        if (function_exists('posix_geteuid') && $original['uid'] !== (int)posix_geteuid()) {
            phct_fail('wp-config.php owner differs from the current account; mutation refused');
        }

        $txId = phct_tx_id();
        $txDir = $txRoot.'/'.$txId;
        $oldMask = umask(0077);
        try {
            $made = @mkdir($txDir, 0700);
        } finally {
            umask($oldMask);
        }
        if (!$made) {
            phct_fail('cannot create config transaction directory');
        }

        $backup = $txDir.'/wp-config.php';
        phct_private_file($backup, $original['bytes']);
        $backupSnapshot = phct_snapshot($backup, 'backup');
        if ($backupSnapshot['sha256'] !== $original['sha256']
            || $backupSnapshot['size'] !== $original['size']) {
            phct_fail('backup verification failed; no mutation attempted');
        }

        $stage = $txDir.'/staged-wp-config.php';
        phct_private_file($stage, $original['bytes']);

        $meta = array(
            'format' => 1,
            'tool' => 'PressHarden',
            'transaction_id' => $txId,
            'site' => $site,
            'site_label' => $label,
            'key' => $key,
            'kind' => $kind,
            'requested_value' => $value,
            'started_at' => gmdate('c'),
            'status' => 'PREPARED',
            'original_sha256' => $original['sha256'],
            'original_size' => $original['size'],
            'original_mode' => sprintf('%04o', $original['mode']),
            'backup' => 'wp-config.php',
        );
        phct_atomic_json($txDir.'/meta.json', $meta);

        $setRc = phct_set($wpBinary, $site, $stage, $key, $kind, $value);
        if ($setRc !== 0) {
            $meta['status'] = 'STAGE_MUTATION_FAILED';
            $meta['finished_at'] = gmdate('c');
            $meta['wp_cli_exit'] = $setRc;
            phct_atomic_json($txDir.'/meta.json', $meta);
            phct_fail('WP-CLI could not prepare the staged config; live wp-config.php was unchanged');
        }

        $stageOk = false;
        $stagedValue = phct_get($wpBinary, $site, $stage, $key, $stageOk);
        if (!$stageOk || $stagedValue !== $expected) {
            $meta['status'] = 'STAGE_VERIFY_FAILED';
            $meta['finished_at'] = gmdate('c');
            phct_atomic_json($txDir.'/meta.json', $meta);
            phct_fail('staged config verification failed; live wp-config.php was unchanged');
        }
        $staged = phct_snapshot($stage, 'staged config');
        $tokens = token_get_all($staged['bytes'], TOKEN_PARSE);
        $opening = false; foreach ($tokens as $token) { if (is_array($token) && $token[0] === T_OPEN_TAG) $opening = true; }
        if (!$opening) phct_fail('staged file has no PHP opening tag; live config unchanged');

        clearstatcache(true, $config);
        $liveBeforePublish = phct_snapshot($config);
        if (!phct_same($liveBeforePublish, $original, true)) {
            $meta['status'] = 'REFUSED_SOURCE_CHANGED';
            $meta['finished_at'] = gmdate('c');
            phct_atomic_json($txDir.'/meta.json', $meta);
            phct_fail('wp-config.php changed during staging; live file was not modified');
        }

        $published = phct_publish($config, $staged['bytes'], $original['mode'], $original);

        $liveOk = false;
        $actual = phct_get($wpBinary, $site, $config, $key, $liveOk);
        if (!$liveOk || $actual !== $expected) {
            clearstatcache(true, $config);
            $current = phct_snapshot($config);
            $rolledBack = false;
            if (phct_same($current, $published, true)) {
                try {
                    $restored = phct_publish($config, $original['bytes'], $original['mode'], $published);
                    $rolledBack = ($restored['sha256'] === $original['sha256']);
                } catch (Throwable $ignored) {
                    $rolledBack = false;
                }
            }
            $meta['status'] = $rolledBack
                ? 'LIVE_VERIFY_FAILED_ROLLED_BACK'
                : 'LIVE_VERIFY_FAILED_BACKUP_RETAINED';
            $meta['finished_at'] = gmdate('c');
            phct_atomic_json($txDir.'/meta.json', $meta);
            phct_fail($rolledBack
                ? 'live verification failed; original wp-config.php rollback verified'
                : 'live verification failed; automatic rollback was unsafe, verified backup retained');
        }

        $meta['status'] = 'COMPLETED';
        $meta['finished_at'] = gmdate('c');
        $meta['staged_sha256'] = $staged['sha256'];
        $meta['final_sha256'] = $published['sha256'];
        $meta['final_size'] = $published['size'];
        phct_atomic_json($txDir.'/meta.json', $meta);
        fwrite(STDOUT, "OK\tCHANGED\t".$txId."\n");
        exit(0);
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
} catch (Throwable $e) {
    $message = $e instanceof PressHardenConfigTxError
        ? $e->getMessage()
        : 'unexpected config transaction failure';
    fwrite(STDERR, "CONFIG TRANSACTION: ".$message.".\n");
    exit(2);
}
