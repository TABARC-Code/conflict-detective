<?php
namespace ConflictDetective\Admin;
use ConflictDetective\Database;
use ConflictDetective\TestRunner;
use ConflictDetective\PluginManager;
defined('ABSPATH') || exit;
class AdminPage {
    private $db;
    private $runner;
    public function __construct() {
        $this->db = new Database();
        $this->runner = new TestRunner();
    }
    public function init() {
        add_management_page(
            __('Conflict Detective', 'conflict-detective'),
            __('Conflict Detective', 'conflict-detective'),
            'manage_options',
            'conflict-detective',
            array($this, 'render')
        );
        add_action('admin_enqueue_scripts', array($this, 'assets'));
    }
    public function assets($hook) {
        if ($hook !== 'tools_page_conflict-detective') {
            return;
        }
        wp_enqueue_style(
            'conflict-detective-admin',
            CONFLICT_DETECTIVE_PLUGIN_URL . 'admin/css/admin-style.css',
            array(),
            CONFLICT_DETECTIVE_VERSION
        );
        wp_enqueue_script(
            'conflict-detective-admin',
            CONFLICT_DETECTIVE_PLUGIN_URL . 'admin/js/admin-script.js',
            array('jquery'),
            CONFLICT_DETECTIVE_VERSION,
            true
        );
        wp_localize_script('conflict-detective-admin', 'conflictDetectiveData', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('conflict_detective_ajax'),
            'strings' => array(
                'start' => __('Starting scan...', 'conflict-detective'),
                'progress' => __('Working...', 'conflict-detective'),
                'done' => __('Scan complete.', 'conflict-detective'),
                'error' => __('Something failed. Check the error log tab.', 'conflict-detective'),
                'confirm_disable' => __('Disable this plugin? If it is essential, you are about to learn a lesson.', 'conflict-detective'),
            ),
        ));
    }
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'conflict-detective'));
        }
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        $safe = conflict_detective_is_safe_mode();
        $toggle_url = wp_nonce_url(add_query_arg('conflict_detective_disable_safe_mode', '1'), 'conflict_detective_toggle_safe_mode');
        echo '<div class="wrap conflict-detective-admin">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        if ($safe) {
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Safe Mode is active.', 'conflict-detective') . '</strong></p>';
            echo '<p>' . esc_html__('Only a reduced plugin set should be loading. Fix the mess, then exit safe mode.', 'conflict-detective') . '</p>';
            echo '<p><a class="button" href="' . esc_url($toggle_url) . '">' . esc_html__('Exit Safe Mode', 'conflict-detective') . '</a></p></div>';
        }
        echo '<nav class="nav-tab-wrapper">';
        $this->tab_link('dashboard', $tab, __('Dashboard', 'conflict-detective'));
        $this->tab_link('scanner', $tab, __('Scanner', 'conflict-detective'));
        $this->tab_link('errors', $tab, __('Errors', 'conflict-detective'));
        $this->tab_link('snapshots', $tab, __('Snapshots', 'conflict-detective'));
        $this->tab_link('settings', $tab, __('Settings', 'conflict-detective'));
        echo '</nav>';
        echo '<div class="conflict-detective-content">';
        if ($tab === 'scanner') {
            $this->render_scanner();
        } elseif ($tab === 'errors') {
            $this->render_errors();
        } elseif ($tab === 'snapshots') {
            $this->render_snapshots();
        } elseif ($tab === 'settings') {
            $this->render_settings();
        } else {
            $this->render_dashboard();
        }
        echo '</div></div>';
    }
    private function tab_link($slug, $active, $label) {
        $url = add_query_arg(array('page' => 'conflict-detective', 'tab' => $slug), admin_url('tools.php'));
        $class = 'nav-tab' . (($slug === $active) ? ' nav-tab-active' : '');
        echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    private function render_dashboard() {
        $stats = $this->db->get_statistics();
        $recent = $this->db->get_recent_errors(3600, 50);
        $conflicts = $this->db->get_active_conflicts(25);
        echo '<div class="cd-grid">';
        echo '<div class="cd-card"><h2>' . esc_html__('Health', 'conflict-detective') . '</h2>';
        echo '<p><strong>' . esc_html__('Active conflicts:', 'conflict-detective') . '</strong> ' . esc_html((string) $stats['active_conflicts']) . '</p>';
        echo '<p><strong>' . esc_html__('Errors last hour:', 'conflict-detective') . '</strong> ' . esc_html((string) count($recent)) . '</p>';
        echo '<p><strong>' . esc_html__('Total scans:', 'conflict-detective') . '</strong> ' . esc_html((string) $stats['total_scans']) . '</p>';
        echo '<p><strong>' . esc_html__('Total errors logged:', 'conflict-detective') . '</strong> ' . esc_html((string) $stats['total_errors']) . '</p>';
        echo '</div>';
        echo '<div class="cd-card"><h2>' . esc_html__('Active conflicts', 'conflict-detective') . '</h2>';
        if (empty($conflicts)) {
            echo '<p>' . esc_html__('None detected. Enjoy the temporary peace.', 'conflict-detective') . '</p>';
        } else {
            foreach ($conflicts as $c) {
                $id = (int) $c['id'];
                $type = isset($c['conflict_type']) ? (string) $c['conflict_type'] : 'unknown';
                $confidence = isset($c['confidence']) ? (float) $c['confidence'] : 0.0;
                $desc = isset($c['description']) ? (string) $c['description'] : '';
                echo '<div class="cd-conflict">';
                echo '<div class="cd-conflict-head"><span class="cd-badge">' . esc_html($type) . '</span>';
                echo '<span class="cd-muted">' . esc_html__('Confidence:', 'conflict-detective') . ' ' . esc_html((string) round($confidence * 100)) . '%</span></div>';
                echo '<p>' . esc_html($desc) . '</p>';
                echo '<p><button class="button cd-resolve" data-conflict-id="' . esc_attr((string) $id) . '">' . esc_html__('Mark resolved', 'conflict-detective') . '</button></p>';
                echo '</div>';
            }
        }
        echo '</div></div>';
    }
    private function render_scanner() {
        $progress = $this->runner->get_scan_progress();
        echo '<div class="cd-card">';
        echo '<h2>' . esc_html__('Scanner', 'conflict-detective') . '</h2>';
        echo '<p>' . esc_html__('This toggles plugins in controlled ways and pings your site. Run it off-peak if you like your hosting account.', 'conflict-detective') . '</p>';
        echo '<div id="cd-scanner">';
        if ($progress && $progress['status'] === 'running') {
            echo '<p><strong>' . esc_html__('Scan running.', 'conflict-detective') . '</strong></p>';
            echo '<div class="cd-progress"><div class="cd-progress-bar" style="width:' . esc_attr((string) $progress['percent']) . '%"></div></div>';
            echo '<p class="cd-muted">' . esc_html($progress['current_step']) . '</p>';
            echo '<p><button class="button" id="cd-cancel-scan">' . esc_html__('Cancel scan', 'conflict-detective') . '</button></p>';
        } else {
            echo '<p><button class="button button-primary" id="cd-start-scan">' . esc_html__('Start automated detection', 'conflict-detective') . '</button></p>';
            echo '<p class="cd-muted">' . esc_html__('If this site is already on fire, enable Safe Mode first via URL. That is what it is for.', 'conflict-detective') . '</p>';
        }
        echo '<div id="cd-scan-results" class="cd-scan-results" style="display:none;"></div>';
        echo '</div></div>';
    }
    private function render_errors() {
        $severity = isset($_GET['severity']) ? sanitize_key($_GET['severity']) : '';
        $plugin = isset($_GET['plugin']) ? sanitize_text_field(wp_unslash($_GET['plugin'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $data = $this->db->get_errors_filtered(array(
            'severity' => $severity,
            'plugin' => $plugin,
            'search' => $search,
            'paged' => $paged,
            'per_page' => 50,
        ));
        echo '<div class="cd-card">';
        echo '<h2>' . esc_html__('Errors', 'conflict-detective') . '</h2>';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="conflict-detective">';
        echo '<input type="hidden" name="tab" value="errors">';
        echo '<div class="cd-filters">';
        echo '<select name="severity">';
        echo '<option value="">' . esc_html__('All severities', 'conflict-detective') . '</option>';
        echo '<option value="critical"' . selected($severity, 'critical', false) . '>' . esc_html__('Critical', 'conflict-detective') . '</option>';
        echo '<option value="warning"' . selected($severity, 'warning', false) . '>' . esc_html__('Warning', 'conflict-detective') . '</option>';
        echo '<option value="notice"' . selected($severity, 'notice', false) . '>' . esc_html__('Notice', 'conflict-detective') . '</option>';
        echo '</select>';
        echo '<input type="text" name="plugin" placeholder="' . esc_attr__('Plugin (folder name)', 'conflict-detective') . '" value="' . esc_attr($plugin) . '">';
        echo '<input type="text" name="s" placeholder="' . esc_attr__('Search message', 'conflict-detective') . '" value="' . esc_attr($search) . '">';
        echo '<button class="button">' . esc_html__('Filter', 'conflict-detective') . '</button>';
        echo '</div></form>';
        if (empty($data['rows'])) {
            echo '<p>' . esc_html__('No errors found with those filters. Either good news or your site is failing silently.', 'conflict-detective') . '</p>';
            echo '</div>';
            return;
        }
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Time', 'conflict-detective') . '</th>';
        echo '<th>' . esc_html__('Severity', 'conflict-detective') . '</th>';
        echo '<th>' . esc_html__('Message', 'conflict-detective') . '</th>';
        echo '<th>' . esc_html__('Plugin', 'conflict-detective') . '</th>';
        echo '<th>' . esc_html__('File', 'conflict-detective') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($data['rows'] as $e) {
            $time = isset($e['timestamp']) ? $e['timestamp'] : '';
            $sev = isset($e['severity']) ? $e['severity'] : '';
            $msg = isset($e['message']) ? $e['message'] : '';
            $pl = isset($e['plugin']) ? $e['plugin'] : '';
            $file = isset($e['file']) ? basename((string) $e['file']) : '';
            $line = isset($e['line']) ? (int) $e['line'] : 0;
            $loc = $file ? ($file . ':' . $line) : '';
            echo '<tr>';
            echo '<td>' . esc_html($time) . '</td>';
            echo '<td><span class="cd-sev cd-sev-' . esc_attr($sev) . '">' . esc_html(ucfirst((string) $sev)) . '</span></td>';
            echo '<td class="cd-msg">' . esc_html($msg) . '</td>';
            echo '<td>' . esc_html($pl ? $pl : __('Unknown', 'conflict-detective')) . '</td>';
            echo '<td class="cd-muted">' . esc_html($loc) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        $total = (int) $data['total'];
        $per_page = (int) $data['per_page'];
        $pages = (int) ceil($total / $per_page);
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            for ($i = 1; $i <= $pages; $i++) {
                $url = add_query_arg(array('page' => 'conflict-detective', 'tab' => 'errors', 'paged' => $i, 'severity' => $severity, 'plugin' => $plugin, 's' => $search), admin_url('tools.php'));
                $cls = ($i === $paged) ? 'cd-page cd-page-active' : 'cd-page';
                echo '<a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . esc_html((string) $i) . '</a>';
            }
            echo '</div></div>';
        }
        echo '</div>';
    }
    private function render_snapshots() {
        $pm = new PluginManager();
        $snaps = $this->db->get_all_snapshots(50);
        echo '<div class="cd-card">';
        echo '<h2>' . esc_html__('Snapshots', 'conflict-detective') . '</h2>';
        echo '<p>' . esc_html__('Snapshots are restore points for active_plugins. Not magic. Just a seatbelt.', 'conflict-detective') . '</p>';
        echo '<p><button class="button" id="cd-create-snapshot">' . esc_html__('Create snapshot', 'conflict-detective') . '</button></p>';
        if (empty($snaps)) {
            echo '<p>' . esc_html__('No snapshots yet.', 'conflict-detective') . '</p></div>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('ID', 'conflict-detective') . '</th>';
        echo '<th>' . esc_html__('Label', 'conflict-detective') . '</th>';
        echo '<th>' . esc_html__('Time', 'conflict-detective') . '</th>';
        echo '<th>' . esc_html__('Action', 'conflict-detective') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($snaps as $s) {
            $id = (int) $s['id'];
            $label = isset($s['label']) ? (string) $s['label'] : '';
            $time = isset($s['timestamp']) ? (string) $s['timestamp'] : '';
            echo '<tr>';
            echo '<td>' . esc_html((string) $id) . '</td>';
            echo '<td>' . esc_html($label ? $label : __('(no label)', 'conflict-detective')) . '</td>';
            echo '<td>' . esc_html($time) . '</td>';
            echo '<td><button class="button cd-restore-snapshot" data-snapshot-id="' . esc_attr((string) $id) . '">' . esc_html__('Restore', 'conflict-detective') . '</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    private function render_settings() {
        if (isset($_POST['cd_settings_save'])) {
            check_admin_referer('conflict_detective_settings');
            $monitoring = isset($_POST['monitoring_enabled']) ? 'yes' : 'no';
            $threshold = isset($_POST['error_threshold']) ? (int) $_POST['error_threshold'] : 5;
            $timeout = isset($_POST['test_timeout']) ? (int) $_POST['test_timeout'] : 30;
            $retention = isset($_POST['retention_days']) ? (int) $_POST['retention_days'] : 30;
            update_option('conflict_detective_monitoring_enabled', $monitoring);
            update_option('conflict_detective_error_threshold', max(1, min(100, $threshold)));
            update_option('conflict_detective_test_timeout', max(10, min(120, $timeout)));
            update_option('conflict_detective_retention_days', max(1, min(365, $retention)));
            echo '<div class="notice notice-success"><p>' . esc_html__('Saved.', 'conflict-detective') . '</p></div>';
        }
        $monitoring_enabled = get_option('conflict_detective_monitoring_enabled', 'yes') === 'yes';
        $threshold = (int) get_option('conflict_detective_error_threshold', 5);
        $timeout = (int) get_option('conflict_detective_test_timeout', 30);
        $retention = (int) get_option('conflict_detective_retention_days', 30);
        echo '<div class="cd-card">';
        echo '<h2>' . esc_html__('Settings', 'conflict-detective') . '</h2>';
        echo '<form method="post">';
        wp_nonce_field('conflict_detective_settings');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Monitoring', 'conflict-detective') . '</th><td>';
        echo '<label><input type="checkbox" name="monitoring_enabled" value="1"' . checked($monitoring_enabled, true, false) . '> ' . esc_html__('Enable error monitoring', 'conflict-detective') . '</label>';
        echo '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Error threshold', 'conflict-detective') . '</th><td>';
        echo '<input type="number" name="error_threshold" value="' . esc_attr((string) $threshold) . '" min="1" max="100"> ';
        echo '<span class="cd-muted">' . esc_html__('Used later for auto-detect logic. Not fully weaponised yet.', 'conflict-detective') . '</span>';
        echo '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Test timeout (seconds)', 'conflict-detective') . '</th><td>';
        echo '<input type="number" name="test_timeout" value="' . esc_attr((string) $timeout) . '" min="10" max="120">';
        echo '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Retention (days)', 'conflict-detective') . '</th><td>';
        echo '<input type="number" name="retention_days" value="' . esc_attr((string) $retention) . '" min="1" max="365">';
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '<p><button class="button button-primary" name="cd_settings_save" value="1">' . esc_html__('Save settings', 'conflict-detective') . '</button></p>';
        echo '</form></div>';
    }
}
