<?php
/**
 * Plugin Name: Conflict Detective
 * Plugin URI: https://github.com/TABARC-Code/conflict-detective
 * Description: Detect and isolate WordPress plugin conflicts through systematic testing and error analysis. Because manual toggling is not a hobby.
 * Version: 1.0.0.7
 * Author: TABARC-Code
 * Author URI: https://github.com/TABARC-Code
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: conflict-detective
 * Domain Path: /languages
 */
defined('ABSPATH') || exit;
define('CONFLICT_DETECTIVE_VERSION', '1.0.0.7');
define('CONFLICT_DETECTIVE_PLUGIN_FILE', __FILE__);
define('CONFLICT_DETECTIVE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CONFLICT_DETECTIVE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CONFLICT_DETECTIVE_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('CONFLICT_DETECTIVE_STATE_FILE', WP_CONTENT_DIR . '/conflict-detective-state.json');
define('CONFLICT_DETECTIVE_EARLY_LOG_FILE', WP_CONTENT_DIR . '/conflict-detective-early-errors.log');
define('CONFLICT_DETECTIVE_FALLBACK_LOG_FILE', WP_CONTENT_DIR . '/conflict-detective-fallback.log');
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'includes/class-database.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'includes/class-error-monitor.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'includes/class-plugin-manager.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'includes/class-conflict-detector.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'includes/class-test-runner.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'includes/class-recovery-mode.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'includes/class-report-generator.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'admin/class-admin-page.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'admin/class-ajax.php';
function conflict_detective_read_state() {
    if (!file_exists(CONFLICT_DETECTIVE_STATE_FILE)) {
        return array(
            'safe_mode' => false,
            'testing_mode' => false,
            'disabled_plugins' => array(),
            'scan' => array(
                'scan_id' => null,
                'status' => 'idle',
                'started_at' => null,
                'current_step' => 'idle',
                'plugins_total' => 0,
                'plugins_tested' => 0,
                'conflicts_found' => 0,
                'last_heartbeat' => 0,
                'last_error' => null,
            ),
            'recovery' => array(
                'last_known_good_snapshot_id' => null,
                'rollback_pending' => false,
            ),
        );
    }
    $raw = file_get_contents(CONFLICT_DETECTIVE_STATE_FILE);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}
function conflict_detective_write_state($state) {
    if (!is_array($state)) {
        return false;
    }
    $json = wp_json_encode($state, JSON_PRETTY_PRINT);
    if (!$json) {
        return false;
    }
    return (bool) file_put_contents(CONFLICT_DETECTIVE_STATE_FILE, $json);
}
function conflict_detective_is_safe_mode() {
    $state = conflict_detective_read_state();
    return !empty($state['safe_mode']);
}
function conflict_detective_set_safe_mode($on) {
    $state = conflict_detective_read_state();
    $state['safe_mode'] = (bool) $on;
    if (!$on) {
        $state['testing_mode'] = false;
        $state['disabled_plugins'] = array();
        if (!empty($state['scan']) && is_array($state['scan'])) {
            $state['scan']['status'] = 'idle';
            $state['scan']['current_step'] = 'idle';
        }
    }
    return conflict_detective_write_state($state);
}
function conflict_detective_install_mu_loader() {
    $target_dir = WP_CONTENT_DIR . '/mu-plugins';
    $target_file = $target_dir . '/conflict-detective-loader.php';
    if (!file_exists($target_dir)) {
        wp_mkdir_p($target_dir);
    }
    $source = CONFLICT_DETECTIVE_PLUGIN_DIR . 'mu-plugin/conflict-detective-loader.php';
    if (!file_exists($source)) {
        return false;
    }
    $contents = file_get_contents($source);
    if (!$contents) {
        return false;
    }
    return (bool) file_put_contents($target_file, $contents);
}
function conflict_detective_activation() {
    $db = new \ConflictDetective\Database();
    $db->create_tables();
    conflict_detective_install_mu_loader();
    if (!file_exists(CONFLICT_DETECTIVE_STATE_FILE)) {
        conflict_detective_write_state(conflict_detective_read_state());
    }
    if (!wp_next_scheduled('conflict_detective_cron_tick')) {
        wp_schedule_event(time() + 300, 'hourly', 'conflict_detective_cron_tick');
    }
    if (!wp_next_scheduled('conflict_detective_cron_cleanup')) {
        wp_schedule_event(time() + 900, 'daily', 'conflict_detective_cron_cleanup');
    }
}
register_activation_hook(__FILE__, 'conflict_detective_activation');
function conflict_detective_deactivation() {
    $state = conflict_detective_read_state();
    $state['testing_mode'] = false;
    $state['disabled_plugins'] = array();
    if (!empty($state['scan']) && is_array($state['scan'])) {
        $state['scan']['status'] = 'idle';
        $state['scan']['current_step'] = 'idle';
    }
    conflict_detective_write_state($state);
    $timestamp = wp_next_scheduled('conflict_detective_cron_tick');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'conflict_detective_cron_tick');
    }
    $timestamp2 = wp_next_scheduled('conflict_detective_cron_cleanup');
    if ($timestamp2) {
        wp_unschedule_event($timestamp2, 'conflict_detective_cron_cleanup');
    }
}
register_deactivation_hook(__FILE__, 'conflict_detective_deactivation');
add_action('plugins_loaded', function () {
    load_plugin_textdomain('conflict-detective', false, dirname(CONFLICT_DETECTIVE_PLUGIN_BASENAME) . '/languages');
}, 1);
add_action('plugins_loaded', function () {
    $monitor = new \ConflictDetective\ErrorMonitor();
    $monitor->init();
    $monitor->import_early_log();
}, 1);
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (isset($_GET['conflict_detective_disable_safe_mode'])) {
        check_admin_referer('conflict_detective_toggle_safe_mode');
        conflict_detective_set_safe_mode(false);
        wp_safe_redirect(remove_query_arg(array('conflict_detective_disable_safe_mode', '_wpnonce')));
        exit;
    }
}, 1);
add_action('admin_menu', function () {
    $page = new \ConflictDetective\Admin\AdminPage();
    $page->init();
});
add_action('admin_init', function () {
    $ajax = new \ConflictDetective\Admin\Ajax();
    $ajax->init();
});
add_action('conflict_detective_cron_tick', function () {
    $recovery = new \ConflictDetective\RecoveryMode();
    $recovery->tick();
});
add_action('conflict_detective_cron_cleanup', function () {
    $days = (int) get_option('conflict_detective_retention_days', 30);
    $days = max(1, min(365, $days));
    $db = new \ConflictDetective\Database();
    $db->cleanup_old_data($days);
});
