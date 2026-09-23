<?php
$repo = dirname(__DIR__);

define('DISALLOW_FILE_MODS', true);
define('DISALLOW_FILE_EDIT', false);
define('WP_AUTO_UPDATE_CORE', 'minor');
define('WP_DISABLE_FATAL_ERROR_HANDLER', false);
define('DISABLE_WP_CRON', false);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('SAVEQUERIES', true);
define('SCRIPT_DEBUG', false);
define('FORCE_SSL_ADMIN', true);
define('WP_CACHE', true);
define('WP_POST_REVISIONS', 5);
define('EMPTY_TRASH_DAYS', 14);
define('AUTOSAVE_INTERVAL', 90);
define('WP_MEMORY_LIMIT', '128M');
define('WP_MAX_MEMORY_LIMIT', '256M');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('FS_METHOD', 'direct');
define('WP_ALLOW_REPAIR', false);
define('ALLOW_UNFILTERED_UPLOADS', false);
define('DISALLOW_UNFILTERED_HTML', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('ABSPATH', sys_get_temp_dir() . '/');

function get_plugins() { return array('a/a.php' => array(), 'b/b.php' => array()); }
function wp_get_themes() { return array('theme-a' => new stdClass(), 'theme-b' => new stdClass()); }
function get_site_option($key, $default = false) {
    if ($key === 'auto_update_plugins') return array('a/a.php');
    if ($key === 'auto_update_themes') return array('theme-a', 'theme-b');
    return $default;
}
function wp_get_environment_type() { return 'staging'; }
function wp_get_development_mode() { return 'plugin'; }

$args = array('example.com');
ob_start();
include $repo . '/lib/wp-policy-runtime.php';
$out = trim(ob_get_clean());
$row = json_decode($out, true);
if (!is_array($row)) throw new RuntimeException('collector did not emit JSON');
$p = $row['policy'];
$expect = array(
    'file_mods' => 'LOCKED',
    'editor' => 'DISABLED',
    'core_updates' => 'MINOR',
    'plugin_updates' => 'PARTIAL',
    'plugin_updates_count' => '1/2',
    'theme_updates' => 'ENABLED',
    'theme_updates_count' => '2/2',
    'updater' => 'BLOCKED',
    'updater_blockers' => 'DISALLOW_FILE_MODS',
    'cron' => 'ENABLED',
    'recovery' => 'ENABLED',
    'environment' => 'STAGING',
    'development' => 'PLUGIN',
    'debug' => 'ENABLED',
    'debug_log' => 'ENABLED',
    'debug_display' => 'DISABLED',
    'savequeries' => 'ENABLED',
    'force_ssl_admin' => 'ENABLED',
    'wp_cache' => 'ENABLED',
    'revisions' => 'LIMIT:5',
    'trash_days' => '14 DAYS',
    'autosave_interval' => '90 SEC',
    'wp_memory_limit' => '128M',
    'wp_max_memory_limit' => '256M',
    'db_charset' => 'UTF8MB4',
    'db_collate' => 'DEFAULT',
    'fs_method' => 'DIRECT',
    'unfiltered_html' => 'ENABLED',
    'http_block_external' => 'ENABLED',
);
foreach ($expect as $k => $v) {
    if (!array_key_exists($k, $p) || $p[$k] !== $v) {
        throw new RuntimeException("$k expected $v, got " . var_export(isset($p[$k]) ? $p[$k] : null, true));
    }
}
foreach (array_keys($p) as $k) {
    if (preg_match('/password|secret|salt|auth_key|secure_auth|logged_in_key|nonce_key|ftp|proxy/i', $k)) {
        throw new RuntimeException('sensitive key leaked into allowlist: ' . $k);
    }
}
echo "WordPress policy runtime allowlist/effective-state checks: PASS\n";
