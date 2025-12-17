<?php
namespace ConflictDetective;
defined('ABSPATH') || exit;
class ConflictDetector {
    private $db;
    private $severity_weights = array(
        'critical' => 10,
        'warning' => 5,
        'notice' => 1,
    );
    public function __construct() {
        $this->db = new Database();
    }
    public function analyse_errors($errors) {
        if (empty($errors) || !is_array($errors)) {
            return array();
        }
        $patterns = $this->identify_error_patterns($errors);
        $conflicts = array();
        foreach ($patterns as $p) {
            if ($p['confidence'] < 0.7) {
                continue;
            }
            $conflicts[] = array(
                'type' => 'pattern_conflict',
                'confidence' => $p['confidence'],
                'plugins' => $p['plugins'],
                'description' => $p['description'],
                'recommendation' => $this->generate_recommendation($p),
                'evidence' => $p['errors'],
            );
        }
        usort($conflicts, function ($a, $b) {
            if ($a['confidence'] === $b['confidence']) {
                return 0;
            }
            return ($a['confidence'] < $b['confidence']) ? 1 : -1;
        });
        return $conflicts;
    }
    public function classify_conflict_type($errors) {
        $types = array();
        foreach ((array) $errors as $e) {
            $types[] = isset($e['error_type']) ? $e['error_type'] : 'unknown';
        }
        $counts = array_count_values($types);
        arsort($counts);
        $top = key($counts);
        return $top ? (string) $top : 'unknown';
    }
    private function identify_error_patterns($errors) {
        $groups = array();
        foreach ($errors as $e) {
            $msg = isset($e['message']) ? (string) $e['message'] : '';
            $norm = $this->normalise_error_message($msg);
            if (!isset($groups[$norm])) {
                $groups[$norm] = array();
            }
            $groups[$norm][] = $e;
        }
        $patterns = array();
        foreach ($groups as $norm => $group_errors) {
            if (count($group_errors) < 2) {
                continue;
            }
            $plugins = array();
            foreach ($group_errors as $ge) {
                if (!empty($ge['plugin'])) {
                    $plugins[] = $ge['plugin'];
                }
            }
            $plugins = array_values(array_unique($plugins));
            if (empty($plugins)) {
                continue;
            }
            $confidence = $this->calculate_pattern_confidence($group_errors);
            $patterns[] = array(
                'message' => $norm,
                'occurrences' => count($group_errors),
                'plugins' => $plugins,
                'confidence' => $confidence,
                'errors' => $group_errors,
                'description' => $this->generate_pattern_description($norm, $plugins, count($group_errors)),
            );
        }
        return $patterns;
    }
    private function normalise_error_message($message) {
        $message = preg_replace('#/[a-zA-Z0-9_\-/]+\.php#', '/path/file.php', (string) $message);
        $message = preg_replace('#\bline \d+\b#i', 'line N', (string) $message);
        $message = preg_replace('#\b\d{3,}\b#', 'ID', (string) $message);
        $message = preg_replace('#0x[0-9a-f]+#i', '0xADDR', (string) $message);
        return trim((string) $message);
    }
    private function calculate_pattern_confidence($errors) {
        $count = count($errors);
        $repetition = min($count / 10, 1.0);
        $critical = 0;
        foreach ($errors as $e) {
            if (!empty($e['severity']) && $e['severity'] === 'critical') {
                $critical++;
            }
        }
        $severity_score = $count > 0 ? ($critical / $count) : 0;
        $score = ($repetition * 0.5) + ($severity_score * 0.5);
        return max(0.0, min(1.0, $score));
    }
    private function generate_pattern_description($message, $plugins, $occurrences) {
        $p = implode(', ', $plugins);
        return sprintf('Repeated error (%d occurrences) linked to %s: %s', (int) $occurrences, $p, $message);
    }
    private function generate_recommendation($pattern) {
        $plugins = isset($pattern['plugins']) ? (array) $pattern['plugins'] : array();
        if (count($plugins) === 1) {
            return 'Disable this plugin temporarily and see if the errors stop. If they do, update it or replace it. If they do not, it was innocent, like most suspects.';
        }
        return 'Disable these plugins one at a time to isolate the offender. Update first, then test. Do not do it live at peak traffic unless you enjoy chaos.';
    }
}
