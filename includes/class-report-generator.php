<?php
namespace ConflictDetective;
defined('ABSPATH') || exit;
class ReportGenerator {
    private $db;
    public function __construct() {
        $this->db = new Database();
    }
    public function export_conflicts_json($scan_id) {
        $scan_id = (int) $scan_id;
        $conflicts = $this->db->get_active_conflicts(500);
        $data = array(
            'scan_id' => $scan_id,
            'generated_at' => current_time('mysql'),
            'conflicts' => $conflicts,
        );
        return wp_json_encode($data, JSON_PRETTY_PRINT);
    }
    public function export_conflicts_csv($scan_id) {
        $scan_id = (int) $scan_id;
        $conflicts = $this->db->get_active_conflicts(500);
        $rows = array();
        $rows[] = array('id','type','confidence','plugins','description','recommendation','status','timestamp');
        foreach ($conflicts as $c) {
            $rows[] = array(
                isset($c['id']) ? $c['id'] : '',
                isset($c['conflict_type']) ? $c['conflict_type'] : '',
                isset($c['confidence']) ? $c['confidence'] : '',
                isset($c['plugins']) ? wp_json_encode($c['plugins']) : '[]',
                isset($c['description']) ? $c['description'] : '',
                isset($c['recommendation']) ? $c['recommendation'] : '',
                isset($c['status']) ? $c['status'] : '',
                isset($c['timestamp']) ? $c['timestamp'] : '',
            );
        }
        $out = '';
        foreach ($rows as $r) {
            $escaped = array();
            foreach ($r as $cell) {
                $cell = (string) $cell;
                $cell = str_replace('"', '""', $cell);
                $escaped[] = '"' . $cell . '"';
            }
            $out .= implode(',', $escaped) . "\n";
        }
        return $out;
    }
}
