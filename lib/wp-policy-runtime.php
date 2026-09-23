<?php
/**
 * PressHarden trusted WordPress policy collector.
 *
 * Executed by WP-CLI after WordPress loads. Emits only an allowlisted JSON
 * policy record; never intentionally emits credentials, salts, arbitrary
 * wp-config values, source code, or decoded payloads.
 */

function ph_policy_bool($name, $default = false) {
    return defined($name) ? (bool) constant($name) : (bool) $default;
}

function ph_policy_onoff($value) {
    return $value ? 'ENABLED' : 'DISABLED';
}

function ph_policy_string($name, $default = '') {
    if (!defined($name)) return $default;
    $v = constant($name);
    if (is_string($v) || is_int($v) || is_float($v)) return (string) $v;
    return $default;
}

function ph_policy_core_updates() {
    if (defined('WP_AUTO_UPDATE_CORE')) {
        $v = constant('WP_AUTO_UPDATE_CORE');
        if ($v === true) return 'MAJOR';
        if ($v === false) return 'DISABLED';
        if ($v === 'minor') return 'MINOR';
        return 'CUSTOM';
    }
    $major = function_exists('get_site_option') ? get_site_option('auto_update_core_major', 'unset') : 'unset';
    $minor = function_exists('get_site_option') ? get_site_option('auto_update_core_minor', 'enabled') : 'enabled';
    if ($major === 'enabled') return 'MAJOR';
    if ($minor === 'disabled') return 'DISABLED';
    return 'MINOR';
}

function ph_policy_item_updates($type) {
    if ($type === 'plugin') {
        if (!function_exists('get_plugins')) {
            $file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_readable($file)) require_once $file;
        }
        $installed = function_exists('get_plugins') ? array_keys(get_plugins()) : array();
        $enabled = function_exists('get_site_option') ? get_site_option('auto_update_plugins', array()) : array();
    } else {
        $installed = function_exists('wp_get_themes') ? array_keys(wp_get_themes()) : array();
        $enabled = function_exists('get_site_option') ? get_site_option('auto_update_themes', array()) : array();
    }
    if (!is_array($installed)) $installed = array();
    if (!is_array($enabled)) $enabled = array();
    $installed = array_values(array_unique(array_filter($installed, 'is_string')));
    $enabled = array_values(array_unique(array_filter($enabled, 'is_string')));
    $count = count($installed);
    $enabled_count = count(array_intersect($installed, $enabled));
    if ($count === 0) $state = 'N/A';
    elseif ($enabled_count === 0) $state = 'DISABLED';
    elseif ($enabled_count === $count) $state = 'ENABLED';
    else $state = 'PARTIAL';
    return array('state' => $state, 'enabled' => $enabled_count, 'total' => $count);
}

function ph_policy_revisions() {
    if (!defined('WP_POST_REVISIONS')) return 'ENABLED';
    $v = constant('WP_POST_REVISIONS');
    if ($v === false || $v === 0 || $v === '0') return 'DISABLED';
    if (is_int($v) || (is_string($v) && ctype_digit($v))) {
        $n = (int) $v;
        return $n > 0 ? 'LIMIT:' . $n : 'DISABLED';
    }
    return 'ENABLED';
}

function ph_policy_memory($name) {
    if (!defined($name)) return 'DEFAULT';
    $v = constant($name);
    if (is_string($v) || is_int($v)) return strtoupper(trim((string) $v));
    return 'CUSTOM';
}

$plugin_updates = ph_policy_item_updates('plugin');
$theme_updates = ph_policy_item_updates('theme');

$environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : ph_policy_string('WP_ENVIRONMENT_TYPE', 'production');
$environment = strtolower((string) $environment);
if (!in_array($environment, array('production','staging','development','local'), true)) $environment = 'custom';

$development = function_exists('wp_get_development_mode') ? wp_get_development_mode() : ph_policy_string('WP_DEVELOPMENT_MODE', '');
$development = strtolower((string) $development);
if (!in_array($development, array('', 'core','plugin','theme','all'), true)) $development = 'custom';

if (defined('WP_DEBUG')) {
    $debug = (bool) constant('WP_DEBUG');
} else {
    $debug = ($development !== '' && $development !== 'custom') || $environment === 'development';
}

$file_mods_locked = ph_policy_bool('DISALLOW_FILE_MODS', false);
$editor_disabled = $file_mods_locked || ph_policy_bool('DISALLOW_FILE_EDIT', false);
$blockers = array();
if (ph_policy_bool('AUTOMATIC_UPDATER_DISABLED', false)) $blockers[] = 'AUTOMATIC_UPDATER_DISABLED';
if ($file_mods_locked) $blockers[] = 'DISALLOW_FILE_MODS';

if (ph_policy_bool('DISABLE_WP_CRON', false)) $cron = 'DISABLED';
elseif (ph_policy_bool('ALTERNATE_WP_CRON', false)) $cron = 'ALTERNATE';
else $cron = 'ENABLED';

$debug_log = 'INACTIVE';
$debug_display = 'INACTIVE';
if ($debug) {
    if (defined('WP_DEBUG_LOG')) {
        $dl = constant('WP_DEBUG_LOG');
        $debug_log = ($dl === true || (is_string($dl) && $dl !== '')) ? 'ENABLED' : 'DISABLED';
    } else $debug_log = 'DISABLED';
    if (defined('WP_DEBUG_DISPLAY')) $debug_display = constant('WP_DEBUG_DISPLAY') === false ? 'DISABLED' : 'ENABLED';
    else $debug_display = 'ENABLED';
}

$trash = defined('EMPTY_TRASH_DAYS') ? constant('EMPTY_TRASH_DAYS') : 30;
$trash = is_numeric($trash) ? (int) $trash : 30;
$autosave = defined('AUTOSAVE_INTERVAL') ? constant('AUTOSAVE_INTERVAL') : 60;
$autosave = is_numeric($autosave) ? (int) $autosave : 60;
$db_charset = ph_policy_string('DB_CHARSET', 'DEFAULT');
$db_charset = $db_charset === '' ? 'DEFAULT' : strtoupper($db_charset);
$db_collate = ph_policy_string('DB_COLLATE', '');
$fs_method = ph_policy_string('FS_METHOD', 'AUTO');
$fs_method = $fs_method === '' ? 'AUTO' : strtoupper($fs_method);

$policy = array(
    'file_mods' => $file_mods_locked ? 'LOCKED' : 'UNLOCKED',
    'editor' => $editor_disabled ? 'DISABLED' : 'ENABLED',
    'core_updates' => ph_policy_core_updates(),
    'plugin_updates' => $plugin_updates['state'],
    'plugin_updates_count' => $plugin_updates['enabled'] . '/' . $plugin_updates['total'],
    'theme_updates' => $theme_updates['state'],
    'theme_updates_count' => $theme_updates['enabled'] . '/' . $theme_updates['total'],
    'updater' => count($blockers) ? 'BLOCKED' : 'AVAILABLE',
    'updater_blockers' => implode(',', $blockers),
    'cron' => $cron,
    'recovery' => ph_policy_bool('WP_DISABLE_FATAL_ERROR_HANDLER', false) ? 'DISABLED' : 'ENABLED',
    'environment' => strtoupper($environment),
    'development' => $development === '' ? 'DISABLED' : strtoupper($development),
    'debug' => ph_policy_onoff($debug),
    'debug_log' => $debug_log,
    'debug_display' => $debug_display,
    'savequeries' => ph_policy_onoff(ph_policy_bool('SAVEQUERIES', false)),
    'script_debug' => ph_policy_onoff(ph_policy_bool('SCRIPT_DEBUG', false)),
    'force_ssl_admin' => ph_policy_onoff(ph_policy_bool('FORCE_SSL_ADMIN', false)),
    'wp_cache' => ph_policy_onoff(ph_policy_bool('WP_CACHE', false)),
    'revisions' => ph_policy_revisions(),
    'trash_days' => $trash === 0 ? 'DISABLED' : $trash . ' DAYS',
    'autosave_interval' => $autosave . ' SEC',
    'wp_memory_limit' => ph_policy_memory('WP_MEMORY_LIMIT'),
    'wp_max_memory_limit' => ph_policy_memory('WP_MAX_MEMORY_LIMIT'),
    'db_charset' => $db_charset,
    'db_collate' => $db_collate === '' ? 'DEFAULT' : strtoupper($db_collate),
    'home_override' => defined('WP_HOME') ? 'CUSTOM' : 'DEFAULT',
    'siteurl_override' => defined('WP_SITEURL') ? 'CUSTOM' : 'DEFAULT',
    'cookie_domain' => defined('COOKIE_DOMAIN') && (string) constant('COOKIE_DOMAIN') !== '' ? 'CUSTOM' : 'DEFAULT',
    'fs_method' => $fs_method,
    'allow_repair' => ph_policy_onoff(ph_policy_bool('WP_ALLOW_REPAIR', false)),
    'unfiltered_uploads' => ph_policy_onoff(ph_policy_bool('ALLOW_UNFILTERED_UPLOADS', false)),
    'unfiltered_html' => ph_policy_onoff(ph_policy_bool('DISALLOW_UNFILTERED_HTML', false)),
    'http_block_external' => ph_policy_onoff(ph_policy_bool('WP_HTTP_BLOCK_EXTERNAL', false)),
);

$site = isset($args) && is_array($args) && isset($args[0]) ? (string) $args[0] : '';
$site = preg_replace('/[^A-Za-z0-9._\/-]/', '?', $site);
$out = json_encode(array('site' => $site, 'policy' => $policy), JSON_UNESCAPED_SLASHES);
if (!is_string($out)) {
    fwrite(STDERR, "INCOMPLETE: policy JSON encoding failed\n");
    exit(2);
}
echo $out, "\n";
