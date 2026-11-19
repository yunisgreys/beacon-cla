<?php

const NOTICE_TAGS = [
    'important'    => ['Important', 'red'],
    'announcement' => ['Announcement', 'blue'],
    'clinic'       => ['Clinic Update', 'green'],
    'guidance'     => ['Guidance Notice', 'purple'],
    'commendation' => ['Commendation', 'amber'],
];
const POSTABLE_TAGS = ['important', 'announcement', 'clinic', 'guidance'];

function parent_children(array $parent): array {
    return db_all(
        "SELECT s.id, s.first_name, s.last_name, s.student_no, s.section_id, sec.grade_level, sec.name AS section_name,
                adv.first_name AS adviser_first, adv.last_name AS adviser_last, l.relationship
           FROM parent_student_links l
           JOIN students s ON s.id = l.student_id
           LEFT JOIN sections sec ON sec.id = s.section_id
           LEFT JOIN users adv ON adv.id = sec.adviser_id
          WHERE l.parent_id = ? AND l.status = 'approved' ORDER BY s.first_name",
        [$parent['id']]
    );
}

function parent_other_links(array $parent): array {
    return db_all(
        "SELECT l.status, l.review_note, l.created_at, s.first_name, s.last_name
           FROM parent_student_links l JOIN students s ON s.id = l.student_id
          WHERE l.parent_id = ? AND l.status <> 'approved' ORDER BY l.created_at DESC",
        [$parent['id']]
    );
}

function selected_child(array $children): ?array {
    $want = (int)($_GET['child'] ?? 0);
    foreach ($children as $c) if ((int)$c['id'] === $want) return $c;
    return $children[0] ?? null;
}

const NOTICE_AUDIENCE_SQL = "(n.audience = 'all' OR (n.audience = 'grade' AND n.grade_level = ?) OR (n.audience = 'section' AND n.section_id = ?))";

function notices_for_child(array $child): array {
    return db_all(
        'SELECT n.*, u.first_name AS a_first, u.last_name AS a_last, r.label AS a_role
           FROM notices n JOIN users u ON u.id = n.posted_by JOIN roles r ON r.id = u.role_id
          WHERE ' . NOTICE_AUDIENCE_SQL . ' ORDER BY n.published_at DESC',
        [(int)$child['grade_level'], (int)$child['section_id']]
    );
}

function parent_can_see_notice(array $parent, int $noticeId): bool {
    foreach (parent_children($parent) as $c) {
        if (db_value('SELECT 1 FROM notices n WHERE n.id = ? AND ' . NOTICE_AUDIENCE_SQL, [$noticeId, (int)$c['grade_level'], (int)$c['section_id']])) return true;
    }
    return false;
}

function notice_acknowledged(int $noticeId, int $parentId): ?string {
    return db_value('SELECT acknowledged_at FROM notice_acknowledgements WHERE notice_id = ? AND parent_id = ?', [$noticeId, $parentId]);
}

function notice_acknowledge(array $parent, int $noticeId): ?string {
    if ($parent['role'] !== 'parent' || !parent_can_see_notice($parent, $noticeId)) return 'Notice not found.';
    if (notice_acknowledged($noticeId, (int)$parent['id'])) return null;
    db_run('INSERT INTO notice_acknowledgements (notice_id, parent_id, acknowledged_at) VALUES (?, ?, ?)', [$noticeId, $parent['id'], now()]);
    audit('notice_ack', 'notices', $noticeId, null, [], $parent);
    return null;
}

function postable_sections(array $user): array {
    $rows = db_all('SELECT id, grade_level, name FROM sections ORDER BY grade_level, name');
    if ($user['role'] === 'teacher') {
        $mine = teacher_section_ids((int)$user['id']);
        $rows = array_values(array_filter($rows, fn($r) => in_array((int)$r['id'], $mine, true)));
    }
    return $rows;
}

function notice_create(array $user, array $in): ?string {
    if (!can('notices.manage', $user)) return 'Not allowed to post notices.';
    $title = trim($in['title'] ?? ''); $body = trim($in['body'] ?? '');
    $tag = $in['tag'] ?? ''; $aud = $in['audience'] ?? '';
    if ($title === '')                               return 'Please add a title.';
    if ($body === '')                                return 'Please write the notice.';
    if (!in_array($tag, POSTABLE_TAGS, true))        return 'Please choose a tag.';
    if (!in_array($aud, ['all', 'grade', 'section'], true)) return 'Please choose who should see it.';
    $grade = null; $section = null;
    if ($user['role'] === 'teacher' && $aud !== 'section') return 'Teachers can post to their own sections only.';
    if ($aud === 'grade') {
        $grade = (int)($in['grade_level'] ?? 0);
        if (!db_value('SELECT 1 FROM sections WHERE grade_level = ?', [$grade])) return 'Please choose a grade level.';
    }
    if ($aud === 'section') {
        $section = (int)($in['section_id'] ?? 0);
        if (!in_array($section, array_map('intval', array_column(postable_sections($user), 'id')), true)) return 'Please choose one of your sections.';
    }
    db_run('INSERT INTO notices (title, body, tag, audience, grade_level, section_id, requires_ack, posted_by, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [mb_substr($title, 0, 150), $body, $tag, $aud, $grade, $section, !empty($in['requires_ack']) ? 1 : 0, $user['id'], now()]);
    audit('create', 'notices', db()->lastInsertId(), null, ['title', 'tag', 'audience'], $user);
    return null;
}

function notice_delete(array $user, int $id): ?string {
    if (!can('notices.manage', $user)) return 'Not allowed.';
    $n = db_one('SELECT posted_by FROM notices WHERE id = ?', [$id]);
    if (!$n) return 'Notice not found.';
    if ((int)$n['posted_by'] !== (int)$user['id'] && $user['role'] !== 'super_admin') return 'You can only remove notices you posted.';
    db_run('DELETE FROM notices WHERE id = ?', [$id]);
    audit('delete', 'notices', $id, null, [], $user);
    return null;
}

function notice_reach(array $n): array {
    $where = ['all' => '1=1', 'grade' => 'sec.grade_level = ?', 'section' => 's.section_id = ?'][$n['audience']];
    $params = $n['audience'] === 'grade' ? [$n['grade_level']] : ($n['audience'] === 'section' ? [$n['section_id']] : []);
    $families = (int)db_value("SELECT COUNT(DISTINCT l.parent_id) FROM parent_student_links l JOIN students s ON s.id = l.student_id
                                 LEFT JOIN sections sec ON sec.id = s.section_id WHERE l.status = 'approved' AND $where", $params);
    $acks = (int)db_value('SELECT COUNT(*) FROM notice_acknowledgements WHERE notice_id = ?', [$n['id']]);
    return [$families, $acks];
}

function notice_audience_label(array $n): string {
    if ($n['audience'] === 'grade') return 'Grade ' . $n['grade_level'] . ' families';
    if ($n['audience'] === 'section') {
        $s = db_one('SELECT grade_level, name FROM sections WHERE id = ?', [$n['section_id']]);
        return $s ? 'Grade ' . $s['grade_level'] . ' – ' . $s['name'] . ' families' : 'One section';
    }
    return 'All families';
}

function family_feed(array $parent, array $child): array {
    $sid = (int)$child['id'];
    $name = $child['first_name'];
    $items = [];

    foreach (notices_for_child($child) as $n) {
        $items[] = ['tag' => $n['tag'], 'at' => $n['published_at'], 'title' => $n['title'], 'body' => $n['body'],
                    'meta' => 'Posted by ' . $n['a_first'] . ' ' . $n['a_last'] . ' · ' . notice_audience_label($n),
                    'link' => 'notice_detail.php?id=' . $n['id'] . '&child=' . $sid,
                    'ack' => $n['requires_ack'] ? (bool)notice_acknowledged((int)$n['id'], (int)$parent['id']) : null];
    }

    foreach (db_all('SELECT c.*, u.first_name AS by_first, u.last_name AS by_last FROM commendations c JOIN users u ON u.id = c.issued_by WHERE c.student_id = ? ORDER BY c.awarded_on DESC', [$sid]) as $c) {
        $items[] = ['tag' => 'commendation', 'at' => $c['awarded_on'] . ' 12:00:00', 'title' => "$name received a commendation: " . $c['title'],
                    'body' => (string)$c['description'], 'meta' => 'From ' . $c['by_first'] . ' ' . $c['by_last'], 'link' => null, 'ack' => null];
    }

    foreach (parent_clinic_view($parent, $sid) as $v) {
        $when = date('g:i A', strtotime($v['arrived_at'])) . ($v['departed_at'] ? '–' . date('g:i A', strtotime($v['departed_at'])) : '');
        if ($v['sensitive']) {
            $body = "$name visited the school clinic. Details are kept private; you can contact the clinic or Guidance Office if you have questions.";
        } else {
            $parts = [$v['complaint']];
            if ($v['case_status']) $parts[] = 'Outcome: ' . (CLINIC_STATUS[$v['case_status']][0] ?? '');
            if ($v['medication_notes']) $parts[] = 'Medication given: ' . str_replace("\n", '; ', $v['medication_notes']);
            $body = implode(' · ', $parts);
        }
        $items[] = ['tag' => 'clinic', 'at' => $v['arrived_at'], 'title' => "$name visited the school clinic ($when)", 'body' => $body,
                    'meta' => $v['departed_at'] ? 'School clinic' : 'School clinic · still in the clinic', 'link' => 'parent_records.php?child=' . $sid . '#clinic', 'ack' => null];
    }

    $g = parent_guidance_view($parent, $sid);
    foreach ($g['cases'] as $c) {
        $items[] = ['tag' => 'guidance', 'at' => $c['opened_at'], 'title' => 'Guidance support: ' . $c['title'],
                    'body' => (string)($c['parent_summary'] ?: 'The Guidance Office is supporting ' . $name . '.'), 'meta' => 'Guidance Office · ' . (CASE_STATUS[$c['status']] ?? $c['status']),
                    'link' => 'parent_records.php?child=' . $sid . '#guidance', 'ack' => null];
        foreach ($c['notes'] as $n) {
            $items[] = ['tag' => 'guidance', 'at' => $n['created_at'], 'title' => 'Update from the Guidance Office', 'body' => $n['body'],
                        'meta' => 'Guidance Office · ' . $c['title'], 'link' => 'parent_records.php?child=' . $sid . '#guidance', 'ack' => null];
        }
    }

    usort($items, fn($a, $b) => strcmp($b['at'], $a['at']));
    return ['items' => $items, 'locked' => $g['locked_count']];
}
