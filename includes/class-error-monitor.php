<?php
namespace ConflictDetective;
defined('ABSPATH') || exit;
class ErrorMonitor {
    private $db;
    private $buffer = array();
    private $buffer_max = 25;
    public function __construct() {
        $this->db = new Database();
    }
    public function init() {
        $enabled = get_option('conflict_detective_monitoring_enabled', 'yes');
        if ($enabled !== 'yes') {
            return;
        }
        set_error_handler(array($this, 'capture_php_error'));
        set_exception_handler(array($this, 'capture_exception'));
        register_shutdown_function(array($this, 'capture_shutdown_fatal'));
        add_action('shutdown', array($this, 'flush_buffer'), 1);
    }
    public function import_early_log() {
        if (!file_exists(CONFLICT_DETECTIVE_EARLY_LOG_FILE)) {
            return;
        }
        $raw = file_get_contents(CONFLICT_DETECTIVE_EARLY_LOG_FILE);
        if (!$raw) {
            return;
        }
        $lines = array_filter(array_map('trim', explode("\n", $raw)));
        foreach ($lines as $line) {
            $this->store_error_safely(array(
                'type' => 'early_log',
                'severity' => 'warning',
                'message' => $line,
                'file' => null,
                'line' => null,
                'plugin' => null,
                'backtrace' => null,
                'url' => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '',
                'timestamp' => current_time('mysql'),
            ));
        }
        file_put_contents(CONFLICT_DETECTIVE_EARLY_LOG_FILE, '');
    }
    public function capture_php_error($errno, $errstr, $errfile, $errline) {
        $severity = $this->map_severity($errno);
        $plugin = $this->guess_plugin_from_path($errfile);
        $error = array(
            'type' => 'php_error',
            'severity' => $severity,
            'message' => (string) $errstr,
            'file' => (string) $errfile,
            'line' => (int) $errline,
            'plugin' => $plugin,
            'backtrace' => $this->backtrace_string(),
            'url' => $this->current_url(),
            'timestamp' => current_time('mysql'),
        );
        $this->buffer[] = $error;
        if (count($this->buffer) >= $this->buffer_max) {
            $this->flush_buffer();
        }
        return false;
    }
    public function capture_exception($e) {
        $file = method_exists($e, 'getFile') ? $e->getFile() : '';
        $plugin = $this->guess_plugin_from_path($file);
        $error = array(
            'type' => 'exception',
            'severity' => 'critical',
            'message' => $e->getMessage(),
            'file' => $file,
            'line' => method_exists($e, 'getLine') ? (int) $e->getLine() : 0,
            'plugin' => $plugin,
            'backtrace' => $e->getTraceAsString(),
            'url' => $this->current_url(),
            'timestamp' => current_time('mysql'),
        );
        $this->store_error_safely($error);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            throw $e;
        }
    }
    public function capture_shutdown_fatal() {
        $last = error_get_last();
        if (!$last) {
            return;
        }
        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR);
        if (!in_array($last['type'], $fatal_types, true)) {
            return;
        }
        $plugin = $this->guess_plugin_from_path($last['file']);
        $error = array(
            'type' => 'fatal',
            'severity' => 'critical',
            'message' => (string) $last['message'],
            'file' => (string) $last['file'],
            'line' => (int) $last['line'],
            'plugin' => $plugin,
            'backtrace' => null,
            'url' => $this->current_url(),
            'timestamp' => current_time('mysql'),
        );
        $this->store_error_safely($error);
    }
    public function flush_buffer() {
        if (empty($this->buffer)) {
            return;
        }
        foreach ($this->buffer as $err) {
            $this->store_error_safely($err);
        }
        $this->buffer = array();
    }
    private function store_error_safely($error) {
        $ok = $this->db->store_error($error);
        if ($ok === false) {
            $line = '[' . current_time('mysql') . '] ' . ($error['severity'] ?? 'notice') . ' ' . ($error['message'] ?? '') . "\n";
            file_put_contents(CONFLICT_DETECTIVE_FALLBACK_LOG_FILE, $line, FILE_APPEND);
        }
    }
    private function map_severity($errno) {
        $critical = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        $warning = array(E_WARNING, E_USER_WARNING, E_RECOVERABLE_ERROR);
        if (in_array($errno, $critical, true)) {
            return 'critical';
        }
        if (in_array($errno, $warning, true)) {
            return 'warning';
        }
        return 'notice';
    }
    private function guess_plugin_from_path($path) {
        $path = (string) $path;
        if ($path === '') {
            return null;
        }
        $plugin_dir = wp_normalize_path(WP_PLUGIN_DIR);
        $p = wp_normalize_path($path);
        if (strpos($p, $plugin_dir) === false) {
            return null;
        }
        $relative = ltrim(str_replace($plugin_dir, '', $p), '/');
        $parts = explode('/', $relative);
        if (empty($parts[0])) {
            return null;
        }
        $plugin_folder = $parts[0];
        return $plugin_folder;
    }
    private function backtrace_string() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        if (!$trace) {
            return '';
        }
        $out = array();
        foreach ($trace as $i => $t) {
            $file = isset($t['file']) ? $t['file'] : '';
            $line = isset($t['line']) ? $t['line'] : '';
            $fn = isset($t['function']) ? $t['function'] : '';
            $out[] = '#' . $i . ' ' . $file . ':' . $line . ' ' . $fn;
        }
        return implode("\n", $out);
    }
    private function current_url() {
        if (empty($_SERVER['REQUEST_URI'])) {
            return '';
        }
        return esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']));
    }
}
