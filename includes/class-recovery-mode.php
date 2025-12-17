<?php
namespace ConflictDetective;
defined('ABSPATH') || exit;
class RecoveryMode {
    private $db;
    public function __construct() {
        $this->db = new Database();
    }
    public function tick() {
        $state = conflict_detective_read_state();
        if (empty($state['scan']) || $state['scan']['status'] !== 'running') {
            return;
        }
        $heartbeat = isset($state['scan']['last_heartbeat']) ? (int) $state['scan']['last_heartbeat'] : 0;
        $age = time() - $heartbeat;
        if ($heartbeat === 0) {
            return;
        }
        if ($age < 600) {
            return;
        }
        $state['recovery']['rollback_pending'] = true;
        conflict_detective_write_state($state);
        $this->maybe_rollback();
    }
    public function maybe_rollback() {
        $state = conflict_detective_read_state();
        if (empty($state['recovery']['rollback_pending'])) {
            return false;
        }
        $snapshot_id = isset($state['recovery']['last_known_good_snapshot_id']) ? (int) $state['recovery']['last_known_good_snapshot_id'] : 0;
        $pm = new PluginManager();
        if ($snapshot_id > 0) {
            $pm->restore_snapshot($snapshot_id);
        } else {
            $pm->rollback_to_original();
        }
        $state['testing_mode'] = false;
        $state['disabled_plugins'] = array();
        if (!empty($state['scan'])) {
            $state['scan']['status'] = 'failed';
            $state['scan']['current_step'] = 'recovery_rollback';
            $state['scan']['last_error'] = 'Scan heartbeat stalled. Rolled back.';
            $state['scan']['last_heartbeat'] = time();
        }
        $state['recovery']['rollback_pending'] = false;
        $state['safe_mode'] = true;
        conflict_detective_write_state($state);
        $this->db->log_action(array('action' => 'rollback', 'plugin' => 'recovery', 'user_id' => get_current_user_id()));
        return true;
    }
    public function force_exit_safe_mode() {
        return conflict_detective_set_safe_mode(false);
    }
}
