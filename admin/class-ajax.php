<?php
/**
 * AJAX handlers for Conflict Detective admin.
 *
 * @package ConflictDetective
 * @license GPL-3.0-or-later
 */

namespace ConflictDetective\Admin;

use ConflictDetective\Database;
use ConflictDetective\TestRunner;

defined( 'ABSPATH' ) || exit;

class Ajax {

    public function init() {
        add_action( 'wp_ajax_conflict_detective_run_scan', array( $this, 'run_scan' ) );
        add_action( 'wp_ajax_conflict_detective_resolve_conflict', array( $this, 'resolve_conflict' ) );
    }

    public function run_scan() {
        check_ajax_referer( 'conflict_detective_ajax', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $runner = new TestRunner();
        $results = $runner->run_automated_detection();

        wp_send_json_success( $results );
    }

    public function resolve_conflict() {
        check_ajax_referer( 'conflict_detective_ajax', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        $conflict_id = absint( $_POST['conflict_id'] ?? 0 );

        if ( ! $conflict_id ) {
            wp_send_json_error( 'Invalid conflict ID' );
        }

        $db = new Database();
        $db->resolve_conflict( $conflict_id );

        wp_send_json_success();
    }
}
