<?php

const STUDENT_SELECT = "
    SELECT s.id, s.student_no, s.lrn, s.first_name, s.middle_name, s.last_name, s.birthdate, s.sex,
           s.address, s.guardian_name, s.guardian_relation, s.guardian_contact, s.section_id,
           sec.grade_level, sec.name AS section_name,
           adv.first_name AS adviser_first, adv.last_name AS adviser_last
      FROM students s
      LEFT JOIN sections sec ON sec.id = s.section_id
      LEFT JOIN users adv    ON adv.id = sec.adviser_id";

function student_name(array $s): string {
    return $s['first_name'] . ' ' . $s['last_name'];
}

function student_initials(array $s): string {
    return strtoupper(mb_substr($s['first_name'], 0, 1) . mb_substr($s['last_name'], 0, 1));
}

function visible_students(array $user): array {
    if ($user['role'] === 'teacher') {
        $sections = teacher_section_ids((int)$user['id']);
        if (!$sections) return [];
        $in = implode(',', array_fill(0, count($sections), '?'));
        return db_all(STUDENT_SELECT . " WHERE s.status = 'enrolled' AND s.section_id IN ($in) ORDER BY s.last_name, s.first_name", $sections);
    }
    if (in_array($user['role'], ['guidance', 'clinic'], true)) {
        return db_all(STUDENT_SELECT . " WHERE s.status = 'enrolled' ORDER BY s.last_name, s.first_name");
    }
    return [];
}

function find_student(array $user, int $id): ?array {
    if (!can_access_student($user, $id)) return null;
    return db_one(STUDENT_SELECT . ' WHERE s.id = ?', [$id]);
}

function advisory_section(int $teacherId): ?array {
    return db_one('SELECT id, grade_level, name, school_year FROM sections WHERE adviser_id = ? ORDER BY school_year DESC, id LIMIT 1', [$teacherId]);
}

function attendance_today(array $studentIds): array {
    if (!$studentIds) return [];
    $in = implode(',', array_fill(0, count($studentIds), '?'));
    $rows = db_all("SELECT student_id, status FROM attendance WHERE att_date = ? AND student_id IN ($in)",
        array_merge([date('Y-m-d')], $studentIds));
    return array_column($rows, 'status', 'student_id');
}

function welfare_indicators(array $studentIds): array {
    if (!$studentIds) return [];
    $in = implode(',', array_fill(0, count($studentIds), '?'));
    $out = array_fill_keys($studentIds, []);

    foreach (db_all("SELECT student_id, conditions, allergies FROM medical_profiles WHERE student_id IN ($in)", $studentIds) as $m) {
        if (trim((string)$m['conditions']) !== '') {
            $label = trim(preg_split('/[(,;]/', $m['conditions'])[0]);
            $out[$m['student_id']][] = ['label' => $label, 'tone' => 'blue', 'hint' => $m['conditions']];
        }
        if (trim((string)$m['allergies']) !== '') {
            $out[$m['student_id']][] = ['label' => 'Allergy', 'tone' => 'blue', 'hint' => 'Allergy: ' . $m['allergies']];
        }
    }

    foreach (db_all("SELECT DISTINCT student_id FROM guidance_cases WHERE status <> 'closed' AND student_id IN ($in)", $studentIds) as $g) {
        $out[$g['student_id']][] = ['label' => 'Guidance support', 'tone' => 'purple', 'hint' => 'Has an active guidance case'];
    }

    foreach (db_all("SELECT * FROM v_wellbeing_signals WHERE student_id IN ($in)", $studentIds) as $w) {
        $signals = (int)$w['incidents_30d'] + (int)$w['clinic_visits_30d'];
        if ($signals >= 3 || (int)$w['sensitive_visits_30d'] >= 1) {
            $out[$w['student_id']][] = ['label' => 'Check in', 'tone' => 'amber',
                'hint' => "{$w['incidents_30d']} reports and {$w['clinic_visits_30d']} clinic visits in the last 30 days"];
        }
        if ((int)$w['absences_30d'] >= 3) {
            $out[$w['student_id']][] = ['label' => 'Frequent absences', 'tone' => 'amber', 'hint' => "{$w['absences_30d']} absences in the last 30 days"];
        }
    }
    return $out;
}

function attendance_summary(int $studentId, int $days = 30): array {
    $since = date('Y-m-d', strtotime("-$days days"));
    $rows = db_all('SELECT att_date, status, remarks FROM attendance WHERE student_id = ? AND att_date >= ? ORDER BY att_date DESC', [$studentId, $since]);
    $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];
    foreach ($rows as $r) $counts[$r['status']]++;
    $total = array_sum($counts);
    return [
        'rows'   => $rows,
        'counts' => $counts,
        'rate'   => $total ? round(($counts['present'] + $counts['late']) / $total * 100) : null,
    ];
}

function medical_profile(int $studentId): ?array {
    return db_one('SELECT blood_type, allergies, conditions, regular_medications, emergency_notes FROM medical_profiles WHERE student_id = ?', [$studentId]);
}

function has_open_guidance_case(int $studentId): bool {
    return (bool)db_value("SELECT 1 FROM guidance_cases WHERE student_id = ? AND status <> 'closed'", [$studentId]);
}

function label(string $value): string {
    static $map = [
        'mental_health' => 'Mental health', 'mental_emotional' => 'Mental / emotional state', 'peer_conflict' => 'Peer conflict',
        'conduct' => 'Behavioral conduct', 'physical' => 'Physical state', 'bip' => 'Behavior plan (BIP)',
        'parent_conference' => 'Parent conference', 'peer_support' => 'Peer support', 'check_in' => 'Check-in',
        'returned_to_class' => 'Returned to class', 'referred_guidance' => 'Referred to guidance',
        'referred_hospital' => 'Referred to hospital', 'sent_home' => 'Sent home', 'new' => 'Awaiting review',
    ];
    return $map[$value] ?? ucfirst(str_replace('_', ' ', $value));
}
