<?php
namespace ConflictDetective;
defined('ABSPATH') || exit;
class TestRunner {
    private $plugin_manager;
    private $detector;
    private $db;
    private $test_timeout = 30;
    public function __construct() {
        $this->plugin_manager = new PluginManager();
        $this->detector = new ConflictDetector();
        $this->db = new Database();
        $timeout = (int) get_option('conflict_detective_test_timeout', 30);
        $this->test_timeout = max(10, min(120, $timeout));
    }
    public function run_automated_detection() {
        $state = conflict_detective_read_state();
        if (!empty($state['scan']) && $state['scan']['status'] === 'running') {
            return array('ok' => false, 'message' => 'Scan already running.');
        }
        $snapshot_id = $this->plugin_manager->save_snapshot('Before automated detection');
        $state['recovery']['last_known_good_snapshot_id'] = $snapshot_id;
        $state['testing_mode'] = true;
        $state['scan'] = array(
            'scan_id' => null,
            'status' => 'running',
            'started_at' => current_time('mysql'),
            'current_step' => 'initialising',
            'plugins_total' => 0,
            'plugins_tested' => 0,
            'conflicts_found' => 0,
            'last_heartbeat' => time(),
            'last_error' => null,
        );
        conflict_detective_write_state($state);
        $start_ts = time();
        $active = $this->plugin_manager->get_active_plugins();
        $all = $this->plugin_manager->get_all_plugins();
        $candidates = array();
        foreach ($active as $basename) {
            if (isset($all[$basename]) && !$all[$basename]['protected']) {
                $candidates[] = $basename;
            }
        }
        $state = conflict_detective_read_state();
        $state['scan']['plugins_total'] = count($candidates);
        $state['scan']['current_step'] = 'testing_individual';
        conflict_detective_write_state($state);
        $tested = 0;
        foreach ($candidates as $basename) {
            $state = conflict_detective_read_state();
            if (!empty($state['scan']) && $state['scan']['status'] === 'cancelled') {
                break;
            }
            $tested++;
            $state['scan']['plugins_tested'] = $tested;
            $state['scan']['current_step'] = 'Testing ' . $basename;
            $state['scan']['last_heartbeat'] = time();
            conflict_detective_write_state($state);
            $to_disable = array_diff($active, array($basename));
            $state['disabled_plugins'] = array_values($to_disable);
            conflict_detective_write_state($state);
            $this->db->log_action(array('action' => 'scan_step', 'plugin' => $basename, 'user_id' => get_current_user_id()));
            $this->touch_site();
            usleep(350000);
        }
        $state = conflict_detective_read_state();
        $state['disabled_plugins'] = array();
        conflict_detective_write_state($state);
        $recent_errors = $this->db->get_recent_errors(3600, 500);
        $conflicts = $this->detector->analyse_errors($recent_errors);
        $scan_id = $this->db->store_scan_results(array(
            'type' => 'automated',
            'status' => (!empty($state['scan']) && $state['scan']['status'] === 'cancelled') ? 'cancelled' : 'complete',
            'plugins_tested' => $tested,
            'conflicts_found' => count($conflicts),
            'start_time' => $state['scan']['started_at'],
            'end_time' => current_time('mysql'),
            'duration' => time() - $start_ts,
            'results' => array('conflicts' => $conflicts),
        ));
        foreach ($conflicts as $c) {
            $c['scan_id'] = $scan_id;
            $this->db->store_conflict($c);
        }
        $state = conflict_detective_read_state();
        $state['scan']['scan_id'] = $scan_id;
        $state['scan']['conflicts_found'] = count($conflicts);
        $state['scan']['status'] = (!empty($state['scan']) && $state['scan']['status'] === 'cancelled') ? 'cancelled' : 'complete';
        $state['scan']['current_step'] = 'complete';
        $state['scan']['last_heartbeat'] = time();
        $state['testing_mode'] = false;
        $state['disabled_plugins'] = array();
        conflict_detective_write_state($state);
        $this->plugin_manager->restore_snapshot($snapshot_id);
        return array(
            'ok' => true,
            'scan_id' => $scan_id,
            'conflicts_found' => count($conflicts),
        );
    }
    public function cancel_scan() {
        $state = conflict_detective_read_state();
        if (empty($state['scan']) || $state['scan']['status'] !== 'running') {
            return false;
        }
        $state['scan']['status'] = 'cancelled';
        $state['scan']['last_heartbeat'] = time();
        conflict_detective_write_state($state);
        $this->db->log_action(array('action' => 'scan_cancel', 'plugin' => 'scan', 'user_id' => get_current_user_id()));
        return true;
    }
    public function get_scan_progress() {
        $state = conflict_detective_read_state();
        if (empty($state['scan']) || $state['scan']['status'] === 'idle') {
            return null;
        }
        $scan = $state['scan'];
        $total = isset($scan['plugins_total']) ? (int) $scan['plugins_total'] : 0;
        $tested = isset($scan['plugins_tested']) ? (int) $scan['plugins_tested'] : 0;
        $percent = ($total > 0) ? min(100, round(($tested / $total) * 100, 1)) : 0;
        return array(
            'status' => isset($scan['status']) ? (string) $scan['status'] : 'unknown',
            'current_step' => isset($scan['current_step']) ? (string) $scan['current_step'] : '',
            'plugins_tested' => $tested,
            'plugins_total' => $total,
            'percent' => $percent,
            'conflicts_found' => isset($scan['conflicts_found']) ? (int) $scan['conflicts_found'] : 0,
            'scan_id' => isset($scan['scan_id']) ? (int) $scan['scan_id'] : 0,
        );
    }
    private function touch_site() {
        $url = home_url('/');
        $args = array(
            'timeout' => $this->test_timeout,
            'redirection' => 0,
            'headers' => array('Cache-Control' => 'no-cache'),
        );
        $r = wp_remote_get($url, $args);
        return !is_wp_error($r);
    }
}
