<?php
/**
 * Report generator for Conflict Detective.
 *
 * @package ConflictDetective
 * @license GPL-3.0-or-later
 */

namespace ConflictDetective;

defined( 'ABSPATH' ) || exit;

class ReportGenerator {

    public function generate_text_report( array $conflicts ) {
        if ( empty( $conflicts ) ) {
            return "No conflicts detected.\nSystem appears stable.\n";
        }

        $output = "Conflict Detective Report\n";
        $output .= "Generated: " . current_time( 'mysql' ) . "\n\n";

        foreach ( $conflicts as $conflict ) {
            $output .= "Conflict Type: {$conflict['type']}\n";
            $output .= "Confidence: " . round( $conflict['confidence'] * 100 ) . "%\n";
            $output .= "Plugins: " . implode( ', ', $conflict['plugins'] ) . "\n";
            $output .= "Description: {$conflict['description']}\n";

            if ( ! empty( $conflict['recommendation'] ) ) {
                $output .= "Recommendation: {$conflict['recommendation']}\n";
            }

            $output .= "\n";
        }

        return $output;
    }
}
