<?php
/**
 * Shared helper functions for Conflict Detective.
 *
 * @package ConflictDetective
 * @license GPL-3.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Check if Conflict Detective safe mode is active.
 *
 * Safe mode must work even when admin is broken.
 *
 * @return bool
 */
function conflict_detective_is_safe_mode() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( isset( $_GET['conflict_detective_safe_mode'] ) ) {
        return true;
    }

    $state_file = WP_CONTENT_DIR . '/conflict-detective-state.json';

    if ( ! file_exists( $state_file ) ) {
        return false;
    }

    $state = json_decode( file_get_contents( $state_file ), true );

    return ! empty( $state['safe_mode'] );
}
