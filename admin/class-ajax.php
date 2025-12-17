<?php
namespace ConflictDetective\Admin;
use ConflictDetective\TestRunner;
use ConflictDetective\Database;
use ConflictDetective\PluginManager;
use ConflictDetective\ReportGenerator;
defined('ABSPATH') || exit;
class Ajax {
    private $runner;
    private $db;
    private $pm;
    public function __construct() {
        $this->runner = new TestRunner();
        $this->db = new Database();
        $this->pm = new PluginManager();
    }
    public function init() {
        add_action('wp_ajax_conflict_detective_start_scan', array($this, 'start_scan'));
        add_action('wp_ajax_conflict_detective_cancel_scan', array($this, 'cancel_scan'));
        add_action('wp_ajax_conflict_detective_get_progress', array($this, 'progress'));
        add_action('wp_ajax_conflict_detective_resolve_conflict', array($this, 'resolve_conflict'));
        add_action('wp_ajax_conflict_detective_disable_plugin', array($this, 'disable_plugin'));
        add_action('wp_ajax_conflict_detective_restore_snapshot', array($this, 'restore_snapshot'));
        add_action('wp_ajax_conflict_detective_create_snapshot', array($this, 'create_snapshot'));
    }
    private function guard() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'No permission.'));
        }
        check_ajax_referer('conflict_detective_ajax', 'nonce');
    }
    public function start_scan() {
        $this->guard();
        $result = $this->runner->run_automated_detection();
        if (empty($result['ok'])) {
            wp_send_json_error(array('message' => isset($result['message']) ? $result['message'] : 'Scan failed.'));
        }
        wp_send_json_success(array('scan_id' => $result['scan_id'], 'conflicts_found' => $result['conflicts_found']));
    }
    public function cancel_scan() {
        $this->guard();
        $ok = $this->runner->cancel_scan();
        wp_send_json_success(array('cancelled' => (bool) $ok));
    }
    public function progress() {
        $this->guard();
        $p = $this->runner->get_scan_progress();
        wp_send_json_success(array('progress' => $p));
    }
    public function resolve_conflict() {
        $this->guard();
        $id = isset($_POST['conflict_id']) ? (int) $_POST['conflict_id'] : 0;
        $ok = $this->db->resolve_conflict($id);
        wp_send_json_success(array('resolved' => (bool) $ok));
    }
    public function disable_plugin() {
        $this->guard();
        $basename = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash($_POST['plugin'])) : '';
        if ($basename === '') {
            wp_send_json_error(array('message' => 'Missing plugin.'));
        }
        $ok = $this->pm->disable_plugin_permanently($basename);
        if (!$ok) {
            wp_send_json_error(array('message' => 'Could not disable. It might be protected, or WordPress refused.'));
        }
        wp_send_json_success(array('disabled' => true));
    }
    public function restore_snapshot() {
        $this->guard();
        $id = isset($_POST['snapshot_id']) ? (int) $_POST['snapshot_id'] : 0;
        $ok = $this->pm->restore_snapshot($id);
        wp_send_json_success(array('restored' => (bool) $ok));
    }
    public function create_snapshot() {
        $this->guard();
        $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : 'Manual snapshot';
        $id = $this->pm->save_snapshot($label);
        wp_send_json_success(array('snapshot_id' => (int) $id));
    }
}
