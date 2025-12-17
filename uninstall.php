<?php
/**
 * Uninstall routine for Conflict Detective.
 *
 * @package ConflictDetective
 * @licence GPL v3
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$tables = array(
    $wpdb->prefix . 'conflict_detective_errors',
    $wpdb->prefix . 'conflict_detective_scans',
    $wpdb->prefix . 'conflict_detective_conflicts',
    $wpdb->prefix . 'conflict_detective_snapshots',
    $wpdb->prefix . 'conflict_detective_actions',
);

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

delete_option( 'conflict_detective_db_version' );
delete_option( 'conflict_detective_error_threshold' );
delete_option( 'conflict_detective_auto_safe_mode' );
delete_option( 'conflict_detective_test_timeout' );
delete_option( 'conflict_detective_retention_days' );
delete_option( 'conflict_detective_essential_plugins' );

$state_file = WP_CONTENT_DIR . '/conflict-detective-state.json';
$early_log = WP_CONTENT_DIR . '/conflict-detective-early-errors.log';
$mu_loader = WP_CONTENT_DIR . '/mu-plugins/conflict-detective-loader.php';

if ( file_exists( $state_file ) ) {
    @unlink( $state_file );
}
if ( file_exists( $early_log ) ) {
    @unlink( $early_log );
}
if ( file_exists( $mu_loader ) ) {
    @unlink( $mu_loader );
}
