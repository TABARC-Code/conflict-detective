<?php
// includes/class-database.php
// Your Database class is mostly fine. I added two missing methods used by the UI: get_scan_by_id and cleanup_old_data.

namespace ConflictDetective;

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
            message text NOT NULL,
            file varchar(500) DEFAULT NULL,
            line int(11) DEFAULT NULL,
            plugin varchar(200) DEFAULT NULL,
            backtrace longtext DEFAULT NULL,
            url varchar(500) DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            timestamp datetime NOT NULL,
            PRIMARY KEY (id),
            KEY error_type (error_type),
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
            KEY scan_type (scan_type),
            KEY status (status),
            KEY start_time (start_time)
        ) $charset_collate;";

        $sql_conflicts = "CREATE TABLE {$this->tables['conflicts']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id bigint(20) unsigned DEFAULT NULL,
            conflict_type varchar(50) NOT NULL,
            plugins longtext NOT NULL,
            confidence decimal(3,2) NOT NULL,
            description text NOT NULL,
            recommendation text DEFAULT NULL,
            evidence longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            resolved_at datetime DEFAULT NULL,
            timestamp datetime NOT NULL,
            PRIMARY KEY (id),
            KEY scan_id (scan_id),
            KEY conflict_type (conflict_type),
            KEY confidence (confidence),
            KEY status (status),
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
        dbDelta( $sql_errors );
        dbDelta( $sql_scans );
        dbDelta( $sql_conflicts );
        dbDelta( $sql_snapshots );
        dbDelta( $sql_actions );

        update_option( 'conflict_detective_db_version', self::DB_VERSION );
        return true;
    }

    public function store_error( $error ) {
        $data = array(
            'error_type' => $error['type'] ?? 'unknown',
            'severity' => $error['severity'] ?? 'notice',
            'message' => $error['message'] ?? '',
            'file' => $error['file'] ?? null,
            'line' => $error['line'] ?? null,
            'plugin' => $error['plugin'] ?? null,
            'backtrace' => $error['backtrace'] ?? null,
            'url' => $error['url'] ?? null,
            'user_id' => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
            'timestamp' => $error['timestamp'] ?? current_time( 'mysql' ),
        );

        $result = $this->wpdb->insert( $this->tables['errors'], $data );
        if ( $result === false ) {
            return false;
        }
        return $this->wpdb->insert_id;
    }

    public function get_recent_errors( $seconds = 3600 ) {
        $cutoff = date( 'Y-m-d H:i:s', time() - (int) $seconds );
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['errors']} WHERE timestamp > %s ORDER BY timestamp DESC",
                $cutoff
            ),
            ARRAY_A
        );
        return $results ?: array();
    }

    public function clear_recent_errors() {
        $cutoff = date( 'Y-m-d H:i:s', time() - 60 );
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->tables['errors']} WHERE timestamp > %s",
                $cutoff
            )
        );
    }

    public function store_scan_results( $scan_data ) {
        $data = array(
            'scan_type' => $scan_data['type'] ?? 'automated',
            'status' => $scan_data['status'] ?? 'complete',
            'plugins_tested' => $scan_data['plugins_tested'] ?? 0,
            'conflicts_found' => $scan_data['conflicts_found'] ?? 0,
            'start_time' => $scan_data['start_time'] ?? current_time( 'mysql' ),
            'end_time' => $scan_data['end_time'] ?? current_time( 'mysql' ),
            'duration' => $scan_data['duration'] ?? 0,
            'results' => wp_json_encode( $scan_data['results'] ?? array() ),
        );
        $this->wpdb->insert( $this->tables['scans'], $data );
        return (int) $this->wpdb->insert_id;
    }

    public function get_scan_history( $limit = 10 ) {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['scans']} ORDER BY start_time DESC LIMIT %d",
                (int) $limit
            ),
            ARRAY_A
        );
        return $results ?: array();
    }

    public function get_scan_by_id( $scan_id ) {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['scans']} WHERE id = %d",
                (int) $scan_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function store_conflict( $conflict_data ) {
        $data = array(
            'scan_id' => $conflict_data['scan_id'] ?? null,
            'conflict_type' => $conflict_data['type'] ?? 'unknown',
            'plugins' => wp_json_encode( $conflict_data['plugins'] ?? array() ),
            'confidence' => $conflict_data['confidence'] ?? 0,
            'description' => $conflict_data['description'] ?? '',
            'recommendation' => $conflict_data['recommendation'] ?? '',
            'evidence' => wp_json_encode( $conflict_data['evidence'] ?? array() ),
            'status' => $conflict_data['status'] ?? 'active',
            'timestamp' => current_time( 'mysql' ),
        );

        $this->wpdb->insert( $this->tables['conflicts'], $data );
        return (int) $this->wpdb->insert_id;
    }

    public function get_active_conflicts() {
        $results = $this->wpdb->get_results(
            "SELECT * FROM {$this->tables['conflicts']} WHERE status = 'active' ORDER BY confidence DESC, timestamp DESC",
            ARRAY_A
        );
        if ( ! $results ) {
            return array();
        }
        foreach ( $results as &$r ) {
            $r['plugins'] = json_decode( (string) $r['plugins'], true );
            $r['evidence'] = json_decode( (string) $r['evidence'], true );
        }
        return $results;
    }

    public function resolve_conflict( $conflict_id ) {
        $result = $this->wpdb->update(
            $this->tables['conflicts'],
            array(
                'status' => 'resolved',
                'resolved_at' => current_time( 'mysql' ),
            ),
            array( 'id' => (int) $conflict_id )
        );
        return $result !== false;
    }

    public function log_action( $action ) {
        $data = array(
            'action_type' => $action['action'] ?? 'unknown',
            'plugin' => $action['plugin'] ?? '',
            'user_id' => $action['user_id'] ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ),
            'timestamp' => current_time( 'mysql' ),
        );
        $this->wpdb->insert( $this->tables['actions'], $data );
        return (int) $this->wpdb->insert_id;
    }

    public function get_statistics() {
        $total_errors = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->tables['errors']}" );
        $total_scans = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->tables['scans']}" );
        $active_conflicts = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->tables['conflicts']} WHERE status = 'active'" );

        return array(
            'total_errors' => $total_errors,
            'total_scans' => $total_scans,
            'active_conflicts' => $active_conflicts,
        );
    }

    public function cleanup_old_data( $days_to_keep ) {
        $days_to_keep = (int) $days_to_keep;
        if ( $days_to_keep < 1 ) {
            $days_to_keep = 1;
        }
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days_to_keep * DAY_IN_SECONDS ) );

        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->tables['errors']} WHERE timestamp < %s",
                $cutoff
            )
        );

        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->tables['scans']} WHERE start_time < %s",
                $cutoff
            )
        );
    }
}
