<?php
/**
 * Admin Page for Conflict Detective.
 *
 * @package ConflictDetective
 * @licence GPL v3
 */

namespace ConflictDetective\Admin;

use ConflictDetective\Database;
use ConflictDetective\TestRunner;
use ConflictDetective\ReportGenerator;

class AdminPage {
    private $database;
    private $test_runner;
    private $reporter;

    public function __construct() {
        $this->database = new Database();
        $this->test_runner = new TestRunner();
        $this->reporter = new ReportGenerator();
    }

    public function init() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    public function add_admin_menu() {
        add_management_page(
            __( 'Conflict Detective', 'conflict-detective' ),
            __( 'Conflict Detective', 'conflict-detective' ),
            'manage_options',
            'conflict-detective',
            array( $this, 'render_admin_page' )
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'tools_page_conflict-detective' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'conflict-detective-admin',
            CONFLICT_DETECTIVE_PLUGIN_URL . 'admin/css/admin-style.css',
            array(),
            CONFLICT_DETECTIVE_VERSION
        );

        wp_enqueue_script(
            'conflict-detective-admin',
            CONFLICT_DETECTIVE_PLUGIN_URL . 'admin/js/admin-script.js',
            array( 'jquery' ),
            CONFLICT_DETECTIVE_VERSION,
            true
        );

        wp_localize_script(
            'conflict-detective-admin',
            'conflictDetectiveData',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'conflict_detective_ajax' ),
                'strings' => array(
                    'starting' => __( 'Starting scan...', 'conflict-detective' ),
                    'scanning' => __( 'Scanning...', 'conflict-detective' ),
                    'complete' => __( 'Scan complete.', 'conflict-detective' ),
                    'error' => __( 'Something failed. Check the error log tab.', 'conflict-detective' ),
                    'confirm_resolve' => __( 'Mark this conflict as resolved?', 'conflict-detective' ),
                    'confirm_safe_mode' => __( 'Enable safe mode now? This will load only essential plugins.', 'conflict-detective' ),
                ),
            )
        );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'conflict-detective' ) );
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';

        ?>
        <div class="wrap conflict-detective-admin">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <?php if ( \conflict_detective_is_safe_mode() ) : ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e( 'Safe Mode Active', 'conflict-detective' ); ?></strong></p>
                    <p>
                        <?php esc_html_e( 'Only essential plugins are loaded. Run a scan, then decide what gets to come back.', 'conflict-detective' ); ?>
                        <a class="button button-secondary" href="<?php echo esc_url( add_query_arg( 'conflict_detective_disable_safe_mode', '1', admin_url() ) ); ?>">
                            <?php esc_html_e( 'Exit Safe Mode', 'conflict-detective' ); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( \conflict_detective_admin_url( 'dashboard' ) ); ?>" class="nav-tab <?php echo $active_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Dashboard', 'conflict-detective' ); ?>
                </a>
                <a href="<?php echo esc_url( \conflict_detective_admin_url( 'scanner' ) ); ?>" class="nav-tab <?php echo $active_tab === 'scanner' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Scanner', 'conflict-detective' ); ?>
                </a>
                <a href="<?php echo esc_url( \conflict_detective_admin_url( 'errors' ) ); ?>" class="nav-tab <?php echo $active_tab === 'errors' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Errors', 'conflict-detective' ); ?>
                </a>
                <a href="<?php echo esc_url( \conflict_detective_admin_url( 'reports' ) ); ?>" class="nav-tab <?php echo $active_tab === 'reports' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Reports', 'conflict-detective' ); ?>
                </a>
                <a href="<?php echo esc_url( \conflict_detective_admin_url( 'settings' ) ); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Settings', 'conflict-detective' ); ?>
                </a>
            </nav>

            <div class="conflict-detective-content">
                <?php
                if ( $active_tab === 'scanner' ) {
                    $this->render_scanner_tab();
                } elseif ( $active_tab === 'errors' ) {
                    $this->render_errors_tab();
                } elseif ( $active_tab === 'reports' ) {
                    $this->render_reports_tab();
                } elseif ( $active_tab === 'settings' ) {
                    $this->render_settings_tab();
                } else {
                    $this->render_dashboard_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_dashboard_tab() {
        $stats = $this->database->get_statistics();
        $recent_errors = $this->database->get_recent_errors( 3600 );
        $active_conflicts = $this->database->get_active_conflicts();

        ?>
        <div class="cd-panel">
            <h2><?php esc_html_e( 'System health', 'conflict-detective' ); ?></h2>
            <p class="cd-muted">
                <?php esc_html_e( 'This is the overview. If something is on fire, it shows up here first.', 'conflict-detective' ); ?>
            </p>

            <div class="cd-grid">
                <div class="cd-card <?php echo empty( $active_conflicts ) ? 'cd-good' : 'cd-warn'; ?>">
                    <div class="cd-card-title"><?php esc_html_e( 'Active conflicts', 'conflict-detective' ); ?></div>
                    <div class="cd-card-number"><?php echo esc_html( count( $active_conflicts ) ); ?></div>
                </div>
                <div class="cd-card">
                    <div class="cd-card-title"><?php esc_html_e( 'Errors in last hour', 'conflict-detective' ); ?></div>
                    <div class="cd-card-number"><?php echo esc_html( number_format_i18n( count( $recent_errors ) ) ); ?></div>
                </div>
                <div class="cd-card">
                    <div class="cd-card-title"><?php esc_html_e( 'Total scans', 'conflict-detective' ); ?></div>
                    <div class="cd-card-number"><?php echo esc_html( number_format_i18n( (int) $stats['total_scans'] ) ); ?></div>
                </div>
                <div class="cd-card">
                    <div class="cd-card-title"><?php esc_html_e( 'Total logged errors', 'conflict-detective' ); ?></div>
                    <div class="cd-card-number"><?php echo esc_html( number_format_i18n( (int) $stats['total_errors'] ) ); ?></div>
                </div>
            </div>

            <div class="cd-actions">
                <button class="button button-primary" id="cd-start-scan"><?php esc_html_e( 'Run scan now', 'conflict-detective' ); ?></button>
                <button class="button" id="cd-toggle-safe-mode" data-safe="<?php echo \conflict_detective_is_safe_mode() ? '1' : '0'; ?>">
                    <?php echo \conflict_detective_is_safe_mode() ? esc_html__( 'Safe mode is on', 'conflict-detective' ) : esc_html__( 'Enable safe mode', 'conflict-detective' ); ?>
                </button>
                <a class="button" href="<?php echo esc_url( \conflict_detective_admin_url( 'errors' ) ); ?>"><?php esc_html_e( 'View errors', 'conflict-detective' ); ?></a>
            </div>

            <h3><?php esc_html_e( 'Active conflicts', 'conflict-detective' ); ?></h3>
            <?php if ( empty( $active_conflicts ) ) : ?>
                <div class="cd-empty">
                    <?php esc_html_e( 'Nothing obvious right now. Keep it that way.', 'conflict-detective' ); ?>
                </div>
            <?php else : ?>
                <?php foreach ( $active_conflicts as $conflict ) : ?>
                    <div class="cd-conflict">
                        <div class="cd-conflict-head">
                            <strong><?php echo esc_html( ucfirst( (string) $conflict['conflict_type'] ) ); ?></strong>
                            <span class="cd-pill">
                                <?php
                                printf(
                                    esc_html__( 'Confidence %s%%', 'conflict-detective' ),
                                    esc_html( (string) round( (float) $conflict['confidence'] * 100 ) )
                                );
                                ?>
                            </span>
                        </div>
                        <div class="cd-conflict-body">
                            <p><?php echo esc_html( (string) $conflict['description'] ); ?></p>
                            <?php if ( ! empty( $conflict['recommendation'] ) ) : ?>
                                <p><strong><?php esc_html_e( 'Recommendation:', 'conflict-detective' ); ?></strong> <?php echo esc_html( (string) $conflict['recommendation'] ); ?></p>
                            <?php endif; ?>
                            <?php if ( ! empty( $conflict['plugins'] ) && is_array( $conflict['plugins'] ) ) : ?>
                                <p class="cd-muted">
                                    <strong><?php esc_html_e( 'Plugins:', 'conflict-detective' ); ?></strong>
                                    <?php echo esc_html( implode( ', ', $conflict['plugins'] ) ); ?>
                                </p>
                            <?php endif; ?>
                            <button class="button cd-resolve" data-conflict-id="<?php echo esc_attr( (string) $conflict['id'] ); ?>">
                                <?php esc_html_e( 'Mark resolved', 'conflict-detective' ); ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <h3><?php esc_html_e( 'Scan history', 'conflict-detective' ); ?></h3>
            <?php
            $history = $this->database->get_scan_history( 10 );
            if ( empty( $history ) ) :
                ?>
                <div class="cd-empty"><?php esc_html_e( 'No scans yet. Do one. That is literally the point.', 'conflict-detective' ); ?></div>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'When', 'conflict-detective' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'conflict-detective' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'conflict-detective' ); ?></th>
                            <th><?php esc_html_e( 'Plugins tested', 'conflict-detective' ); ?></th>
                            <th><?php esc_html_e( 'Conflicts', 'conflict-detective' ); ?></th>
                            <th><?php esc_html_e( 'Report', 'conflict-detective' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $history as $scan ) : ?>
                            <tr>
                                <td><?php echo esc_html( date_i18n( 'Y-m-d H:i', strtotime( (string) $scan['start_time'] ) ) ); ?></td>
                                <td><?php echo esc_html( (string) $scan['scan_type'] ); ?></td>
                                <td><?php echo esc_html( (string) $scan['status'] ); ?></td>
                                <td><?php echo esc_html( (string) $scan['plugins_tested'] ); ?></td>
                                <td><?php echo esc_html( (string) $scan['conflicts_found'] ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'cd_download_scan' => (int) $scan['id'], 'format' => 'json' ), \conflict_detective_admin_url( 'reports' ) ), 'cd_download_scan' ) ); ?>">
                                        <?php esc_html_e( 'JSON', 'conflict-detective' ); ?>
                                    </a>
                                    <span class="cd-muted">|</span>
                                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'cd_download_scan' => (int) $scan['id'], 'format' => 'csv' ), \conflict_detective_admin_url( 'reports' ) ), 'cd_download_scan' ) ); ?>">
                                        <?php esc_html_e( 'CSV', 'conflict-detective' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_scanner_tab() {
        $progress = $this->test_runner->get_scan_progress();
        ?>
        <div class="cd-panel">
            <h2><?php esc_html_e( 'Scanner', 'conflict-detective' ); ?></h2>
            <p class="cd-muted">
                <?php esc_html_e( 'This runs systematic tests. On slow hosting it will feel like watching paint dry, but the paint eventually tells you which plugin did it.', 'conflict-detective' ); ?>
            </p>

            <div class="cd-scan-box">
                <?php if ( $progress && isset( $progress['status'] ) && $progress['status'] === 'running' ) : ?>
                    <div class="cd-progress">
                        <div class="cd-progress-bar"><div class="cd-progress-fill" style="width: <?php echo esc_attr( (string) $progress['percent'] ); ?>%"></div></div>
                        <p class="cd-muted">
                            <?php
                            printf(
                                esc_html__( '%1$d of %2$d plugins tested.', 'conflict-detective' ),
                                (int) $progress['plugins_tested'],
                                (int) $progress['plugins_total']
                            );
                            ?>
                        </p>
                        <p><strong><?php esc_html_e( 'Current step:', 'conflict-detective' ); ?></strong> <?php echo esc_html( (string) $progress['current_step'] ); ?></p>
                        <button class="button" id="cd-cancel-scan"><?php esc_html_e( 'Cancel', 'conflict-detective' ); ?></button>
                    </div>
                <?php else : ?>
                    <button class="button button-primary button-hero" id="cd-start-scan-hero"><?php esc_html_e( 'Start automated detection', 'conflict-detective' ); ?></button>
                    <p class="cd-muted">
                        <?php esc_html_e( 'This will test active plugins. It uses temporary disabling for tests, and always protects Conflict Detective so it does not delete its own ladder.', 'conflict-detective' ); ?>
                    </p>
                <?php endif; ?>
            </div>

            <div id="cd-scan-output" class="cd-scan-output" style="display:none;"></div>
        </div>
        <?php
    }

    private function render_errors_tab() {
        $errors = $this->database->get_recent_errors( 86400 );
        ?>
        <div class="cd-panel">
            <h2><?php esc_html_e( 'Errors', 'conflict-detective' ); ?></h2>
            <p class="cd-muted"><?php esc_html_e( 'Last 24 hours. If you want older, export a report or increase retention.', 'conflict-detective' ); ?></p>

            <div class="cd-actions">
                <button class="button" id="cd-refresh-errors"><?php esc_html_e( 'Refresh', 'conflict-detective' ); ?></button>
                <a class="button" href="<?php echo esc_url( \conflict_detective_admin_url( 'reports' ) ); ?>"><?php esc_html_e( 'Reports', 'conflict-detective' ); ?></a>
            </div>

            <?php if ( empty( $errors ) ) : ?>
                <div class="cd-empty"><?php esc_html_e( 'No logged errors in the last 24 hours. Suspiciously peaceful.', 'conflict-detective' ); ?></div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'conflict-detective' ); ?></th>
                            <th><?php esc_html_e( 'Severity', 'conflict-detective' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'conflict-detective' ); ?></th>
                            <th><?php esc_html_e( 'Message', 'conflict-detective' ); ?></th>
                            <th><?php esc_html_e( 'Plugin', 'conflict-detective' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $errors as $error ) : ?>
                            <tr class="cd-sev-<?php echo esc_attr( (string) $error['severity'] ); ?>">
                                <td><?php echo esc_html( date_i18n( 'H:i:s', strtotime( (string) $error['timestamp'] ) ) ); ?></td>
                                <td><span class="cd-badge cd-badge-<?php echo esc_attr( (string) $error['severity'] ); ?>"><?php echo esc_html( ucfirst( (string) $error['severity'] ) ); ?></span></td>
                                <td><?php echo esc_html( (string) $error['error_type'] ); ?></td>
                                <td>
                                    <div class="cd-msg"><?php echo esc_html( (string) $error['message'] ); ?></div>
                                    <?php if ( ! empty( $error['file'] ) ) : ?>
                                        <div class="cd-muted"><?php echo esc_html( basename( (string) $error['file'] ) . ':' . (string) $error['line'] ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( ! empty( $error['plugin'] ) ? (string) $error['plugin'] : 'Unknown' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_reports_tab() {
        $scan_id = isset( $_GET['cd_download_scan'] ) ? (int) $_GET['cd_download_scan'] : 0;
        $format = isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : 'json';

        if ( $scan_id > 0 && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( $_GET['_wpnonce'] ), 'cd_download_scan' ) ) {
            $this->reporter->download_scan_report( $scan_id, $format );
            return;
        }

        ?>
        <div class="cd-panel">
            <h2><?php esc_html_e( 'Reports', 'conflict-detective' ); ?></h2>
            <p class="cd-muted">
                <?php esc_html_e( 'Export scan results and conflict summaries. When you need to send proof to a plugin author, this is where the proof lives.', 'conflict-detective' ); ?>
            </p>

            <div class="cd-actions">
                <button class="button" id="cd-export-latest-json"><?php esc_html_e( 'Export latest scan as JSON', 'conflict-detective' ); ?></button>
                <button class="button" id="cd-export-latest-csv"><?php esc_html_e( 'Export latest scan as CSV', 'conflict-detective' ); ?></button>
                <button class="button" id="cd-export-conflicts"><?php esc_html_e( 'Export active conflicts', 'conflict-detective' ); ?></button>
            </div>

            <h3><?php esc_html_e( 'What gets exported', 'conflict-detective' ); ?></h3>
            <ul class="cd-list">
                <li><?php esc_html_e( 'Scan metadata: time, duration, tested plugins count.', 'conflict-detective' ); ?></li>
                <li><?php esc_html_e( 'Detected conflicts: confidence, plugins involved, evidence references.', 'conflict-detective' ); ?></li>
                <li><?php esc_html_e( 'A small digest that a human can read without crying.', 'conflict-detective' ); ?></li>
            </ul>
        </div>
        <?php
    }

    private function render_settings_tab() {
        ?>
        <div class="cd-panel">
            <h2><?php esc_html_e( 'Settings', 'conflict-detective' ); ?></h2>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'conflict-detective-settings' );
                do_settings_sections( 'conflict-detective-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
