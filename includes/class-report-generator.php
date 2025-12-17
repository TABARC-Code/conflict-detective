<?php
/**
 * Report Generator for Conflict Detective.
 *
 * @package ConflictDetective
 * @licence GPL v3
 */

namespace ConflictDetective;

class ReportGenerator {
    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function download_scan_report( $scan_id, $format = 'json' ) {
        $scan = $this->db->get_scan_by_id( (int) $scan_id );
        if ( ! $scan ) {
            wp_die( 'Scan not found.' );
        }

        $format = $format === 'csv' ? 'csv' : 'json';
        $filename = 'conflict-detective-scan-' . (int) $scan_id . '.' . $format;

        if ( $format === 'csv' ) {
            $this->output_csv( $filename, $scan );
            exit;
        }

        $payload = $this->build_scan_payload( $scan );
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT );
        exit;
    }

    public function download_active_conflicts( $format = 'json' ) {
        $conflicts = $this->db->get_active_conflicts();
        $format = $format === 'csv' ? 'csv' : 'json';
        $filename = 'conflict-detective-conflicts.' . $format;

        if ( $format === 'csv' ) {
            $rows = array();
            foreach ( $conflicts as $c ) {
                $rows[] = array(
                    'id' => $c['id'],
                    'type' => $c['conflict_type'],
                    'confidence' => $c['confidence'],
                    'description' => $c['description'],
                    'recommendation' => $c['recommendation'],
                    'plugins' => is_array( $c['plugins'] ) ? implode( ', ', $c['plugins'] ) : '',
                    'timestamp' => $c['timestamp'],
                );
            }
            $this->output_csv_rows( $filename, $rows );
            exit;
        }

        $payload = array(
            'generated_at' => current_time( 'mysql' ),
            'site' => home_url(),
            'conflicts' => $conflicts,
        );

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT );
        exit;
    }

    private function build_scan_payload( $scan ) {
        $results = array();
        if ( ! empty( $scan['results'] ) ) {
            $decoded = json_decode( (string) $scan['results'], true );
            $results = is_array( $decoded ) ? $decoded : array();
        }

        return array(
            'generated_at' => current_time( 'mysql' ),
            'site' => home_url(),
            'scan' => array(
                'id' => (int) $scan['id'],
                'scan_type' => $scan['scan_type'],
                'status' => $scan['status'],
                'plugins_tested' => (int) $scan['plugins_tested'],
                'conflicts_found' => (int) $scan['conflicts_found'],
                'start_time' => $scan['start_time'],
                'end_time' => $scan['end_time'],
                'duration' => (int) $scan['duration'],
            ),
            'results' => $results,
            'active_conflicts' => $this->db->get_active_conflicts(),
            'notes' => array(
                'This report is evidence, not gospel.',
                'Confidence is probabilistic. If your site is doing something weird, trust your eyes.',
            ),
        );
    }

    private function output_csv( $filename, $scan ) {
        $payload = $this->build_scan_payload( $scan );
        $rows = array();
        $rows[] = array(
            'scan_id' => $payload['scan']['id'],
            'scan_type' => $payload['scan']['scan_type'],
            'status' => $payload['scan']['status'],
            'plugins_tested' => $payload['scan']['plugins_tested'],
            'conflicts_found' => $payload['scan']['conflicts_found'],
            'start_time' => $payload['scan']['start_time'],
            'end_time' => $payload['scan']['end_time'],
            'duration' => $payload['scan']['duration'],
        );

        $this->output_csv_rows( $filename, $rows );
    }

    private function output_csv_rows( $filename, $rows ) {
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        $out = fopen( 'php://output', 'w' );

        if ( empty( $rows ) ) {
            fputcsv( $out, array( 'empty' ) );
            fclose( $out );
            return;
        }

        fputcsv( $out, array_keys( $rows[0] ) );
        foreach ( $rows as $row ) {
            fputcsv( $out, $row );
        }
        fclose( $out );
    }
}
