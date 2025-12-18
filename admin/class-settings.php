<?php
/**
 * Settings registration for Conflict Detective.
 *
 * @package ConflictDetective
 * @license GPL-3.0-or-later
 */

namespace ConflictDetective\Admin;

defined( 'ABSPATH' ) || exit;

class Settings {

    public function init() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function register_settings() {
        register_setting(
            'conflict-detective-settings',
            'conflict_detective_test_timeout',
            array(
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 30,
            )
        );

        register_setting(
            'conflict-detective-settings',
            'conflict_detective_retention_days',
            array(
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 30,
            )
        );
    }
}
