<?php
namespace ConflictDetective;
defined('ABSPATH') || exit;
class PluginManager {
    private $protected_plugins = array();
    private $original_state = array();
    private $db;
    public function __construct() {
        $this->db = new Database();
        $this->protected_plugins[] = CONFLICT_DETECTIVE_PLUGIN_BASENAME;
        $essential = get_option('conflict_detective_essential_plugins', array());
        if (is_array($essential)) {
            $this->protected_plugins = array_values(array_unique(array_merge($this->protected_plugins, $essential)));
        }
        $this->original_state = $this->get_active_plugins();
    }
    public function get_active_plugins() {
        $active = get_option('active_plugins', array());
        return is_array($active) ? $active : array();
    }
    public function get_all_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = get_plugins();
        $active = $this->get_active_plugins();
        $plugins = array();
        foreach ($all as $file => $data) {
            $plugins[$file] = array(
                'name' => isset($data['Name']) ? $data['Name'] : $file,
                'version' => isset($data['Version']) ? $data['Version'] : '',
                'description' => isset($data['Description']) ? $data['Description'] : '',
                'author' => isset($data['Author']) ? $data['Author'] : '',
                'active' => in_array($file, $active, true),
                'protected' => in_array($file, $this->protected_plugins, true),
                'path' => WP_PLUGIN_DIR . '/' . $file,
            );
        }
        return $plugins;
    }
    public function disable_plugin_temporarily($plugin_basename) {
        if (in_array($plugin_basename, $this->protected_plugins, true)) {
            return false;
        }
        add_filter('option_active_plugins', function ($active) use ($plugin_basename) {
            return array_values(array_diff((array) $active, array($plugin_basename)));
        }, 999);
        return true;
    }
    public function disable_plugins_temporarily($plugin_basenames) {
        $plugin_basenames = array_values(array_diff((array) $plugin_basenames, $this->protected_plugins));
        if (empty($plugin_basenames)) {
            return array();
        }
        add_filter('option_active_plugins', function ($active) use ($plugin_basenames) {
            return array_values(array_diff((array) $active, $plugin_basenames));
        }, 999);
        return $plugin_basenames;
    }
    public function disable_plugin_permanently($plugin_basename) {
        if (in_array($plugin_basename, $this->protected_plugins, true)) {
            return false;
        }
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        deactivate_plugins($plugin_basename, true);
        $this->db->log_action(array('action' => 'disable', 'plugin' => $plugin_basename, 'user_id' => get_current_user_id()));
        return true;
    }
    public function enable_plugin($plugin_basename) {
        $plugin_file = WP_PLUGIN_DIR . '/' . $plugin_basename;
        if (!file_exists($plugin_file)) {
            return new \WP_Error('conflict_detective_plugin_missing', 'Plugin file does not exist.');
        }
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $result = activate_plugin($plugin_basename, '', false, true);
        if (is_wp_error($result)) {
            return $result;
        }
        $this->db->log_action(array('action' => 'enable', 'plugin' => $plugin_basename, 'user_id' => get_current_user_id()));
        return true;
    }
    public function save_snapshot($label = '') {
        return $this->db->store_snapshot(array(
            'label' => $label,
            'active_plugins' => $this->get_active_plugins(),
        ));
    }
    public function restore_snapshot($snapshot_id) {
        $snap = $this->db->get_snapshot($snapshot_id);
        if (!$snap) {
            return false;
        }
        update_option('active_plugins', $snap['active_plugins']);
        $this->db->log_action(array('action' => 'restore', 'plugin' => 'snapshot_' . (int) $snapshot_id, 'user_id' => get_current_user_id()));
        return true;
    }
    public function rollback_to_original() {
        update_option('active_plugins', $this->original_state);
        $this->db->log_action(array('action' => 'rollback', 'plugin' => 'original_state', 'user_id' => get_current_user_id()));
        return true;
    }
    public function get_recently_updated_plugins($days = 7) {
        $days = max(1, min(365, (int) $days));
        $cutoff = time() - ($days * DAY_IN_SECONDS);
        $all = $this->get_all_plugins();
        $recent = array();
        foreach ($all as $basename => $info) {
            if (empty($info['path']) || !file_exists($info['path'])) {
                continue;
            }
            $mod = @filemtime($info['path']);
            if ($mod && $mod >= $cutoff) {
                $recent[] = $basename;
            }
        }
        return $recent;
    }
    public function test_plugin($plugin_basename) {
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        ob_start();
        $activation = activate_plugin($plugin_basename, '', false, true);
        $output = ob_get_clean();
        if (is_wp_error($activation)) {
            return array('success' => false, 'errors' => array(array('type' => 'activation_error', 'message' => $activation->get_error_message(), 'plugin' => $plugin_basename)));
        }
        if (trim((string) $output) !== '') {
            deactivate_plugins($plugin_basename, true);
            return array('success' => false, 'errors' => array(array('type' => 'unexpected_output', 'message' => 'Plugin produced output during activation.', 'plugin' => $plugin_basename)));
        }
        deactivate_plugins($plugin_basename, true);
        return array('success' => true, 'errors' => array());
    }
}
