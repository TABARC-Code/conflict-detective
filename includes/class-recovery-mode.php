<?php
/**
 * Recovery Mode for Conflict Detective.
 *
 * @package ConflictDetective
 * @licence GPL v3
 */

namespace ConflictDetective;

class RecoveryMode {
    const HEARTBEAT_KEY = 'conflict_detective_heartbeat';
    const HEARTBEAT_TTL = 180;

    public function init() {
        add_action( 'init', array( $this, 'maybe_handle_recovery' ), 0 );
        add_action( 'shutdown', array( $this, 'heartbeat' ) );
        add_action( 'admin_notices', array( $this, 'admin_recovery_notice' ) );
        add_action( 'conflict_detective_cleanup', array( $this, 'cleanup' ) );

        if ( ! wp_next_scheduled( 'conflict_detective_cleanup' ) ) {
            wp_schedule_event( time() + 900, 'daily', 'conflict_detective_cleanup' );
        }
    }

    public function heartbeat() {
        set_transient( self::HEARTBEAT_KEY, time(), self::HEARTBEAT_TTL );
    }

    public function maybe_handle_recovery() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $force = isset( $_GET['conflict_detective_recover'] ) ? sanitize_key( $_GET['conflict_detective_recover'] ) : '';
        if ( $force === '1' && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            \conflict_detective_enable_safe_mode( 'Forced recovery via URL.' );
            wp_safe_redirect( \conflict_detective_admin_url( 'dashboard' ) );
            exit;
        }

        if ( ! \conflict_detective_is_safe_mode() ) {
            return;
        }

        $last = (int) get_transient( self::HEARTBEAT_KEY );
        if ( $last > 0 && ( time() - $last ) < self::HEARTBEAT_TTL ) {
            return;
        }

        $state = \conflict_detective_get_state();
        if ( ! empty( $state['testing_mode'] ) ) {
            \conflict_detective_set_state(
                array(
                    'testing_mode' => false,
                    'cancel_scan' => true,
                    'current_step' => 'recovered',
                )
            );
        }

        // If we are in safe mode and the site was not producing heartbeats, something was dying hard.
        // I keep safe mode on, and I add a breadcrumb so the admin knows what happened.
        \conflict_detective_set_state(
            array(
                'safe_mode_reason' => 'Recovery kept safe mode on because the site looked unstable.',
            )
        );
    }

    public function admin_recovery_notice() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $state = \conflict_detective_get_state();
        if ( empty( $state['safe_mode'] ) ) {
            return;
        }

        $reason = ! empty( $state['safe_mode_reason'] ) ? (string) $state['safe_mode_reason'] : 'Safe mode is active.';
        echo '<div class="notice notice-warning"><p><strong>Conflict Detective:</strong> ' . esc_html( $reason ) . '</p></div>';
    }

    public function cleanup() {
        $days = (int) get_option( 'conflict_detective_retention_days', 14 );
        if ( $days < 1 ) {
            $days = 1;
        }
        $db = new Database();
        $db->cleanup_old_data( $days );

        // Rotate early error log if it grows into a monster.
        if ( file_exists( CONFLICT_DETECTIVE_EARLY_ERROR_LOG ) ) {
            $size = filesize( CONFLICT_DETECTIVE_EARLY_ERROR_LOG );
            if ( $size && $size > 5 * 1024 * 1024 ) {
                @rename( CONFLICT_DETECTIVE_EARLY_ERROR_LOG, CONFLICT_DETECTIVE_EARLY_ERROR_LOG . '.old' );
            }
        }
    }
}
