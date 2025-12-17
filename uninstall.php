```php
<?php
/**
 * Uninstall routine for Conflict Detective.
 *
 * @package ConflictDetective
 * @license GPL-3.0-or-later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$tables = array(
    $wpdb->prefix . 'conflict_detective_errors',
    $wpdb->prefix . 'conflict_detective_scans',
    $wpdb->prefix . 'conflict_detective_conflicts',
    $wpdb->prefix . 'conflict_detective_snapshots',
    $wpdb->prefix . 'conflict_detective_actions',
);

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

$options = array(
    'conflict_detective_db_version',
    'conflict_detective_monitoring_enabled',
    'conflict_detective_error_threshold',
    'conflict_detective_test_timeout',
    'conflict_detective_auto_detect',
    'conflict_detective_auto_safe_mode',
    'conflict_detective_notification_email',
    'conflict_detective_essential_plugins',
    'conflict_detective_retention_days',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

$files = array(
    WP_CONTENT_DIR . '/conflict-detective-state.json',
    WP_CONTENT_DIR . '/conflict-detective-early-errors.log',
    WP_CONTENT_DIR . '/conflict-detective-fallback.log',
    WP_CONTENT_DIR . '/mu-plugins/conflict-detective-loader.php',
);

foreach ( $files as $file ) {
    if ( file_exists( $file ) ) {
        @unlink( $file );
    }
}
```
