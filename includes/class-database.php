<?php
namespace ConflictDetective;
defined('ABSPATH') || exit;
class Database {
    const DB_VERSION = '1.0.0';
    const TABLE_ERRORS = 'conflict_detective_errors';
    const TABLE_SCANS = 'conflict_detective_scans';
    const TABLE_CONFLICTS = 'conflict_detective_conflicts';
    const TABLE_SNAPSHOTS = 'conflict_detective_snapshots';
    const TABLE_ACTIONS = 'conflict_detective_actions';
    private $wpdb;
    private $tables = array();
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tables['errors'] = $wpdb->prefix . self::TABLE_ERRORS;
        $this->tables['scans'] = $wpdb->prefix . self::TABLE_SCANS;
        $this->tables['conflicts'] = $wpdb->prefix . self::TABLE_CONFLICTS;
        $this->tables['snapshots'] = $wpdb->prefix . self::TABLE_SNAPSHOTS;
        $this->tables['actions'] = $wpdb->prefix . self::TABLE_ACTIONS;
    }
    public function create_tables() {
        $charset_collate = $this->wpdb->get_charset_collate();
        $sql_errors = "CREATE TABLE {$this->tables['errors']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            error_type varchar(50) NOT NULL,
            severity varchar(20) NOT NULL,
            message longtext NOT NULL,
            file varchar(500) DEFAULT NULL,
            line int(11) DEFAULT NULL,
            plugin varchar(200) DEFAULT NULL,
            backtrace longtext DEFAULT NULL,
            url varchar(500) DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            timestamp datetime NOT NULL,
            PRIMARY KEY (id),
            KEY severity (severity),
            KEY plugin (plugin),
            KEY timestamp (timestamp)
        ) $charset_collate;";
        $sql_scans = "CREATE TABLE {$this->tables['scans']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_type varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            plugins_tested int(11) NOT NULL DEFAULT 0,
            conflicts_found int(11) NOT NULL DEFAULT 0,
            start_time datetime NOT NULL,
            end_time datetime DEFAULT NULL,
            duration int(11) DEFAULT NULL,
            results longtext DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY start_time (start_time)
        ) $charset_collate;";
        $sql_conflicts = "CREATE TABLE {$this->tables['conflicts']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned DEFAULT NULL,
            conflict_type varchar(50) NOT NULL,
            plugins longtext NOT NULL,
            confidence decimal(4,3) NOT NULL DEFAULT 0,
            description longtext NOT NULL,
            recommendation longtext DEFAULT NULL,
            evidence longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            resolved_at datetime DEFAULT NULL,
            timestamp datetime NOT NULL,
            PRIMARY KEY (id),
            KEY scan_id (scan_id),
            KEY status (status),
            KEY confidence (confidence),
            KEY timestamp (timestamp)
        ) $charset_collate;";
        $sql_snapshots = "CREATE TABLE {$this->tables['snapshots']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            label varchar(200) DEFAULT NULL,
            active_plugins longtext NOT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            timestamp datetime NOT NULL,
            PRIMARY KEY (id),
            KEY timestamp (timestamp)
        ) $charset_collate;";
        $sql_actions = "CREATE TABLE {$this->tables['actions']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action_type varchar(50) NOT NULL,
            plugin varchar(200) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            timestamp datetime NOT NULL,
            PRIMARY KEY (id),
            KEY action_type (action_type),
            KEY plugin (plugin),
            KEY timestamp (timestamp)
        ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_errors);
        dbDelta($sql_scans);
        dbDelta($sql_conflicts);
        dbDelta($sql_snapshots);
        dbDelta($sql_actions);
        update_option('conflict_detective_db_version', self::DB_VERSION);
        return true;
    }
    public function store_error($error) {
        $data = array(
            'error_type' => isset($error['type']) ? (string) $error['type'] : 'unknown',
            'severity' => isset($error['severity']) ? (string) $error['severity'] : 'notice',
            'message' => isset($error['message']) ? (string) $error['message'] : '',
            'file' => isset($error['file']) ? (string) $error['file'] : null,
            'line' => isset($error['line']) ? (int) $error['line'] : null,
            'plugin' => isset($error['plugin']) ? (string) $error['plugin'] : null,
            'backtrace' => isset($error['backtrace']) ? (string) $error['backtrace'] : null,
            'url' => isset($error['url']) ? (string) $error['url'] : null,
            'user_id' => get_current_user_id(),
            'timestamp' => isset($error['timestamp']) ? (string) $error['timestamp'] : current_time('mysql'),
        );
        $formats = array('%s','%s','%s','%s','%d','%s','%s','%s','%d','%s');
        $result = $this->wpdb->insert($this->tables['errors'], $data, $formats);
        if (false === $result) {
            return false;
        }
        return (int) $this->wpdb->insert_id;
    }
    public function get_recent_errors($seconds = 3600, $limit = 200) {
        $seconds = max(1, (int) $seconds);
        $limit = max(1, min(1000, (int) $limit));
        $cutoff = gmdate('Y-m-d H:i:s', time() - $seconds);
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->tables['errors']} WHERE timestamp > %s ORDER BY timestamp DESC LIMIT %d",
            $cutoff,
            $limit
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        return $rows ? $rows : array();
    }
    public function get_errors_filtered($args = array()) {
        $defaults = array(
            'severity' => '',
            'plugin' => '',
            'search' => '',
            'paged' => 1,
            'per_page' => 50,
        );
        $args = wp_parse_args($args, $defaults);
        $where = array('1=1');
        $params = array();
        if (!empty($args['severity'])) {
            $where[] = 'severity = %s';
            $params[] = (string) $args['severity'];
        }
        if (!empty($args['plugin'])) {
            $where[] = 'plugin = %s';
            $params[] = (string) $args['plugin'];
        }
        if (!empty($args['search'])) {
            $where[] = 'message LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like((string) $args['search']) . '%';
        }
        $paged = max(1, (int) $args['paged']);
        $per_page = max(10, min(200, (int) $args['per_page']));
        $offset = ($paged - 1) * $per_page;
        $where_sql = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$this->tables['errors']} WHERE {$where_sql}";
        $rows_sql = "SELECT * FROM {$this->tables['errors']} WHERE {$where_sql} ORDER BY timestamp DESC LIMIT %d OFFSET %d";
        $count_params = $params;
        $rows_params = array_merge($params, array($per_page, $offset));
        $total = (int) $this->wpdb->get_var($this->wpdb->prepare($count_sql, $count_params));
        $rows = $this->wpdb->get_results($this->wpdb->prepare($rows_sql, $rows_params), ARRAY_A);
        return array(
            'total' => $total,
            'rows' => $rows ? $rows : array(),
            'paged' => $paged,
            'per_page' => $per_page,
        );
    }
    public function store_scan_results($scan_data) {
        $start = isset($scan_data['start_time']) ? (string) $scan_data['start_time'] : current_time('mysql');
        $end = isset($scan_data['end_time']) ? (string) $scan_data['end_time'] : current_time('mysql');
        $duration = isset($scan_data['duration']) ? (int) $scan_data['duration'] : 0;
        $data = array(
            'scan_type' => isset($scan_data['type']) ? (string) $scan_data['type'] : 'automated',
            'status' => isset($scan_data['status']) ? (string) $scan_data['status'] : 'complete',
            'plugins_tested' => isset($scan_data['plugins_tested']) ? (int) $scan_data['plugins_tested'] : 0,
            'conflicts_found' => isset($scan_data['conflicts_found']) ? (int) $scan_data['conflicts_found'] : 0,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $duration,
            'results' => wp_json_encode(isset($scan_data['results']) ? $scan_data['results'] : array()),
        );
        $this->wpdb->insert(
            $this->tables['scans'],
            $data,
            array('%s','%s','%d','%d','%s','%s','%d','%s')
        );
        return (int) $this->wpdb->insert_id;
    }
    public function store_conflict($conflict_data) {
        $data = array(
            'scan_id' => isset($conflict_data['scan_id']) ? (int) $conflict_data['scan_id'] : null,
            'conflict_type' => isset($conflict_data['type']) ? (string) $conflict_data['type'] : 'unknown',
            'plugins' => wp_json_encode(isset($conflict_data['plugins']) ? $conflict_data['plugins'] : array()),
            'confidence' => isset($conflict_data['confidence']) ? (float) $conflict_data['confidence'] : 0.0,
            'description' => isset($conflict_data['description']) ? (string) $conflict_data['description'] : '',
            'recommendation' => isset($conflict_data['recommendation']) ? (string) $conflict_data['recommendation'] : '',
            'evidence' => wp_json_encode(isset($conflict_data['evidence']) ? $conflict_data['evidence'] : array()),
            'status' => isset($conflict_data['status']) ? (string) $conflict_data['status'] : 'active',
            'timestamp' => current_time('mysql'),
        );
        $this->wpdb->insert(
            $this->tables['conflicts'],
            $data,
            array('%d','%s','%s','%f','%s','%s','%s','%s','%s')
        );
        return (int) $this->wpdb->insert_id;
    }
    public function get_active_conflicts($limit = 100) {
        $limit = max(1, min(500, (int) $limit));
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->tables['conflicts']} WHERE status = 'active' ORDER BY confidence DESC, timestamp DESC LIMIT %d",
            $limit
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        if (!$rows) {
            return array();
        }
        foreach ($rows as &$r) {
            $r['plugins'] = json_decode($r['plugins'], true);
            $r['evidence'] = json_decode($r['evidence'], true);
            if (!is_array($r['plugins'])) {
                $r['plugins'] = array();
            }
            if (!is_array($r['evidence'])) {
                $r['evidence'] = array();
            }
        }
        return $rows;
    }
    public function resolve_conflict($conflict_id) {
        $conflict_id = (int) $conflict_id;
        if ($conflict_id < 1) {
            return false;
        }
        $result = $this->wpdb->update(
            $this->tables['conflicts'],
            array('status' => 'resolved', 'resolved_at' => current_time('mysql')),
            array('id' => $conflict_id),
            array('%s','%s'),
            array('%d')
        );
        return false !== $result;
    }
    public function store_snapshot($snapshot) {
        $data = array(
            'label' => isset($snapshot['label']) ? (string) $snapshot['label'] : null,
            'active_plugins' => wp_json_encode(isset($snapshot['active_plugins']) ? $snapshot['active_plugins'] : array()),
            'created_by' => get_current_user_id(),
            'timestamp' => current_time('mysql'),
        );
        $this->wpdb->insert($this->tables['snapshots'], $data, array('%s','%s','%d','%s'));
        return (int) $this->wpdb->insert_id;
    }
    public function get_snapshot($snapshot_id) {
        $snapshot_id = (int) $snapshot_id;
        if ($snapshot_id < 1) {
            return null;
        }
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->tables['snapshots']} WHERE id = %d", $snapshot_id),
            ARRAY_A
        );
        if (!$row) {
            return null;
        }
        $row['active_plugins'] = json_decode($row['active_plugins'], true);
        if (!is_array($row['active_plugins'])) {
            $row['active_plugins'] = array();
        }
        return $row;
    }
    public function get_all_snapshots($limit = 50) {
        $limit = max(1, min(200, (int) $limit));
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare("SELECT * FROM {$this->tables['snapshots']} ORDER BY timestamp DESC LIMIT %d", $limit),
            ARRAY_A
        );
        if (!$rows) {
            return array();
        }
        foreach ($rows as &$r) {
            $r['active_plugins'] = json_decode($r['active_plugins'], true);
            if (!is_array($r['active_plugins'])) {
                $r['active_plugins'] = array();
            }
        }
        return $rows;
    }
    public function log_action($action) {
        $data = array(
            'action_type' => isset($action['action']) ? (string) $action['action'] : 'unknown',
            'plugin' => isset($action['plugin']) ? (string) $action['plugin'] : '',
            'user_id' => isset($action['user_id']) ? (int) $action['user_id'] : get_current_user_id(),
            'timestamp' => current_time('mysql'),
        );
        $this->wpdb->insert($this->tables['actions'], $data, array('%s','%s','%d','%s'));
        return (int) $this->wpdb->insert_id;
    }
    public function get_statistics() {
        $total_errors = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['errors']}");
        $total_scans = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['scans']}");
        $active_conflicts = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['conflicts']} WHERE status = 'active'");
        return array(
            'total_errors' => $total_errors,
            'total_scans' => $total_scans,
            'active_conflicts' => $active_conflicts,
        );
    }
    public function cleanup_old_data($days_to_keep) {
        $days_to_keep = max(1, (int) $days_to_keep);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days_to_keep * DAY_IN_SECONDS));
        $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$this->tables['errors']} WHERE timestamp < %s", $cutoff));
        $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$this->tables['scans']} WHERE start_time < %s", $cutoff));
        return true;
    }
    public function drop_all_tables() {
        foreach ($this->tables as $t) {
            $this->wpdb->query("DROP TABLE IF EXISTS {$t}");
        }
        return true;
    }
}
