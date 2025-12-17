<?php
/**
 * Plugin Name: Conflict Detective
 * Plugin URI: https://github.com/TABARC-Code/conflict-detective
 * Description: Finds plugin conflicts using error capture and systematic testing, so I do not have to play "disable twenty plugins and pray".
 * Version: 1.0.0.7
 * Author: TABARC-Code
 * Author URI: https://github.com/TABARC-Code
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: conflict-detective
 * Domain Path: /languages
 *
 * I built this because manual conflict debugging is misery.
 * WordPress gives you a white screen and vibes. This gives you receipts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CONFLICT_DETECTIVE_VERSION', '1.0.0.7' );
define( 'CONFLICT_DETECTIVE_PLUGIN_FILE', __FILE__ );
define( 'CONFLICT_DETECTIVE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONFLICT_DETECTIVE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CONFLICT_DETECTIVE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'CONFLICT_DETECTIVE_STATE_FILE', WP_CONTENT_DIR . '/conflict-detective-state.json' );
define( 'CONFLICT_DETECTIVE_EARLY_ERROR_LOG', WP_CONTENT_DIR . '/conflict-detective-early-errors.log' );
define( 'CONFLICT_DETECTIVE_MU_TARGET', WP_CONTENT_DIR . '/mu-plugins/conflict-detective-loader.php' );
define( 'CONFLICT_DETECTIVE_MU_SOURCE', CONFLICT_DETECTIVE_PLUGIN_DIR . 'mu-plugin/conflict-detective-loader.php' );

require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'includes/class-database.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'includes/class-error-monitor.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'includes/class-plugin-manager.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'includes/class-conflict-detector.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'includes/class-test-runner.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'includes/class-recovery-mode.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'includes/class-report-generator.php';

require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'admin/class-settings.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'admin/class-ajax.php';
require_once CONFLICT_DETECTIVE_PLUGIN_DIR . 'admin/class-admin-page.php';

/**
 * Read state from the JSON file.
 * I use the file because databases go down at the exact moment you need them most.
 */
function conflict_detective_get_state() {
    if ( ! file_exists( CONFLICT_DETECTIVE_STATE_FILE ) ) {
        return array();
    }
    $raw = file_get_contents( CONFLICT_DETECTIVE_STATE_FILE );
    $json = json_decode( (string) $raw, true );
    return is_array( $json ) ? $json : array();
}

/**
 * Write state to the JSON file.
 * If this fails, you probably have bigger problems than plugin conflicts.
 */
function conflict_detective_set_state( $updates ) {
    $state = conflict_detective_get_state();
    if ( ! is_array( $state ) ) {
        $state = array();
    }
    $state = array_merge( $state, is_array( $updates ) ? $updates : array() );
    $encoded = wp_json_encode( $state, JSON_PRETTY_PRINT );
    file_put_contents( CONFLICT_DETECTIVE_STATE_FILE, $encoded );
    return true;
}

function conflict_detective_is_safe_mode() {
    $state = conflict_detective_get_state();
    return ! empty( $state['safe_mode'] );
}

function conflict_detective_enable_safe_mode( $reason = '' ) {
    conflict_detective_set_state(
        array(
            'safe_mode' => true,
            'safe_mode_reason' => (string) $reason,
            'safe_mode_since' => current_time( 'mysql' ),
        )
    );
}

function conflict_detective_disable_safe_mode() {
    $state = conflict_detective_get_state();
    $state['safe_mode'] = false;
    $state['testing_mode'] = false;
    $state['disabled_plugins'] = array();
    $state['safe_mode_reason'] = '';
    $state['safe_mode_since'] = '';
    file_put_contents( CONFLICT_DETECTIVE_STATE_FILE, wp_json_encode( $state, JSON_PRETTY_PRINT ) );
}

function conflict_detective_admin_url( $tab = 'dashboard' ) {
    return admin_url( 'tools.php?page=conflict-detective&tab=' . rawurlencode( $tab ) );
}

function conflict_detective_install_mu_loader() {
    if ( ! file_exists( WP_CONTENT_DIR . '/mu-plugins' ) ) {
        wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
    }
    if ( ! file_exists( CONFLICT_DETECTIVE_MU_SOURCE ) ) {
        return false;
    }
    $source = file_get_contents( CONFLICT_DETECTIVE_MU_SOURCE );
    if ( ! $source ) {
        return false;
    }
    file_put_contents( CONFLICT_DETECTIVE_MU_TARGET, $source );
    return true;
}

function conflict_detective_uninstall_mu_loader() {
    if ( file_exists( CONFLICT_DETECTIVE_MU_TARGET ) ) {
        @unlink( CONFLICT_DETECTIVE_MU_TARGET );
    }
}

/**
 * Activation.
 * Creates tables, installs MU loader, initialises state.
 */
function conflict_detective_activate() {
    $db = new \ConflictDetective\Database();
    $db->create_tables();
    conflict_detective_install_mu_loader();
    if ( ! file_exists( CONFLICT_DETECTIVE_STATE_FILE ) ) {
        conflict_detective_set_state(
            array(
                'safe_mode' => false,
                'testing_mode' => false,
                'disabled_plugins' => array(),
                'created_at' => current_time( 'mysql' ),
            )
        );
    }
    if ( ! wp_next_scheduled( 'conflict_detective_daily_health' ) ) {
        wp_schedule_event( time() + 600, 'daily', 'conflict_detective_daily_health' );
    }
}
register_activation_hook( __FILE__, 'conflict_detective_activate' );

function conflict_detective_deactivate() {
    wp_clear_scheduled_hook( 'conflict_detective_daily_health' );
    conflict_detective_set_state(
        array(
            'testing_mode' => false,
            'disabled_plugins' => array(),
        )
    );
}
register_deactivation_hook( __FILE__, 'conflict_detective_deactivate' );

/**
 * Daily health check.
 * This is intentionally boring. If it starts being exciting, you have a broken site.
 */
add_action(
    'conflict_detective_daily_health',
    function() {
        $runner = new \ConflictDetective\TestRunner();
        $result = $runner->run_health_check();
        $db = new \ConflictDetective\Database();
        $db->log_action(
            array(
                'action' => 'health_check',
                'plugin' => 'system',
                'user_id' => 0,
            )
        );
        $threshold = (int) get_option( 'conflict_detective_error_threshold', 10 );
        if ( ! $result['healthy'] && $result['error_count'] >= $threshold ) {
            $auto = get_option( 'conflict_detective_auto_safe_mode', 'no' );
            if ( $auto === 'yes' ) {
                conflict_detective_enable_safe_mode( 'Automatic safe mode: error threshold hit' );
            }
        }
    }
);

/**
 * Early exit safe mode via URL.
 * This is the "I fixed it, let me out" lever.
 */
add_action(
    'init',
    function() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['conflict_detective_disable_safe_mode'] ) && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            conflict_detective_disable_safe_mode();
            wp_safe_redirect( conflict_detective_admin_url( 'dashboard' ) );
            exit;
        }
    },
    1
);

/**
 * Boot core services.
 */
add_action(
    'plugins_loaded',
    function() {
        $monitor = new \ConflictDetective\ErrorMonitor();
        $monitor->init();
        $recovery = new \ConflictDetective\RecoveryMode();
        $recovery->init();
        if ( is_admin() ) {
            $settings = new \ConflictDetective\Admin\Settings();
            $settings->init();
            $ajax = new \ConflictDetective\Admin\Ajax();
            $ajax->init();
            $admin = new \ConflictDetective\Admin\AdminPage();
            $admin->init();
        }
    }
);

/**
 * Add quick links on the Plugins screen.
 */
add_filter(
    'plugin_action_links_' . CONFLICT_DETECTIVE_PLUGIN_BASENAME,
    function( $links ) {
        $links[] = '<a href="' . esc_url( conflict_detective_admin_url( 'dashboard' ) ) . '">Open</a>';
        if ( conflict_detective_is_safe_mode() ) {
            $links[] = '<a href="' . esc_url( add_query_arg( 'conflict_detective_disable_safe_mode', '1', admin_url() ) ) . '">Exit Safe Mode</a>';
        }
        return $links;
    }
);

