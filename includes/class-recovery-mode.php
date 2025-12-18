<?php
/**
 * Recovery mode handler.
 *
 * @package ConflictDetective
 * @license GPL-3.0-or-later
 */

namespace ConflictDetective;

defined( 'ABSPATH' ) || exit;

class RecoveryMode {

    public function enable() {
        $this->write_state( array(
            'safe_mode' => true,
        ) );
    }

    public function disable() {
        $this->write_state( array(
            'safe_mode' => false,
        ) );
    }

    private function write_state( array $data ) {
        $state_file = WP_CONTENT_DIR . '/conflict-detective-state.json';

        $current = array();

        if ( file_exists( $state_file ) ) {
            $current = json_decode( file_get_contents( $state_file ), true ) ?: array();
        }

        $state = array_merge( $current, $data );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $state_file, wp_json_encode( $state, JSON_PRETTY_PRINT ) );
    }
}
