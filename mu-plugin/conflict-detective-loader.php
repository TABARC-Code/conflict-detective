<?php
defined('WPINC') || die;
define('CONFLICT_DETECTIVE_MU_LOADED', true);
function cd_mu_state_file() {
    return WP_CONTENT_DIR . '/conflict-detective-state.json';
}
function cd_mu_read_state() {
    $file = cd_mu_state_file();
    if (!file_exists($file)) {
        return array();
    }
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}
function cd_mu_write_state($state) {
    if (!is_array($state)) {
        return false;
    }
    $json = function_exists('wp_json_encode') ? wp_json_encode($state, JSON_PRETTY_PRINT) : json_encode($state);
    if (!$json) {
        return false;
    }
    return (bool) file_put_contents(cd_mu_state_file(), $json);
}
function cd_mu_check_safe_mode() {
    if (isset($_GET['conflict_detective_safe_mode'])) {
        return true;
    }
    $state = cd_mu_read_state();
    return !empty($state['safe_mode']);
}
function cd_mu_handle_exit_safe_mode() {
    if (!isset($_GET['conflict_detective_disable_safe_mode'])) {
        return;
    }
    $state = cd_mu_read_state();
    $state['safe_mode'] = false;
    $state['testing_mode'] = false;
    $state['disabled_plugins'] = array();
    cd_mu_write_state($state);
}
function cd_mu_filter_active_plugins($plugins) {
    $state = cd_mu_read_state();
    $plugins = is_array($plugins) ? $plugins : array();
    if (!empty($state['testing_mode'])) {
        $disabled = isset($state['disabled_plugins']) && is_array($state['disabled_plugins']) ? $state['disabled_plugins'] : array();
        return array_values(array_diff($plugins, $disabled));
    }
    if (!empty($state['safe_mode'])) {
        $essentials = array();
        if (!empty($state['essential_plugins']) && is_array($state['essential_plugins'])) {
            $essentials = $state['essential_plugins'];
        }
        $allowed = array();
        foreach ($plugins as $p) {
            if (strpos($p, 'conflict-detective') !== false) {
                $allowed[] = $p;
                continue;
            }
            if (!empty($essentials) && in_array($p, $essentials, true)) {
                $allowed[] = $p;
            }
        }
        return array_values(array_unique($allowed));
    }
    return $plugins;
}
function cd_mu_setup_early_error_handling() {
    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        $log = WP_CONTENT_DIR . '/conflict-detective-early-errors.log';
        $entry = '[' . date('Y-m-d H:i:s') . '] ' . $errstr . ' in ' . $errfile . ' line ' . $errline . "\n";
        file_put_contents($log, $entry, FILE_APPEND);
        return false;
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if (!$e) return;
        $fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR);
        if (!in_array($e['type'], $fatal, true)) return;
        $log = WP_CONTENT_DIR . '/conflict-detective-early-errors.log';
        $entry = '[' . date('Y-m-d H:i:s') . '] FATAL ' . $e['message'] . ' in ' . $e['file'] . ' line ' . $e['line'] . "\n";
        file_put_contents($log, $entry, FILE_APPEND);
    });
}
cd_mu_setup_early_error_handling();
cd_mu_handle_exit_safe_mode();
if (cd_mu_check_safe_mode()) {
    add_filter('option_active_plugins', 'cd_mu_filter_active_plugins', 1);
}
