<?php

const STAFF_ROLES   = ['teacher' => 'Teacher', 'guidance' => 'Guidance Office', 'clinic' => 'Clinic'];
const ONLINE_WINDOW = 5 * 60;

function require_admin(array $user, string $perm = 'admin.staff.manage'): void {
    if (!can($perm, $user)) throw new RuntimeException('Only the Super Admin can do this.');
}

function is_online(?string $lastSeen): bool {
    return $lastSeen !== null && strtotime($lastSeen) >= time() - ONLINE_WINDOW;
}

function temp_password(): string {
    $words = ['amber', 'beacon', 'cedar', 'delta', 'ember', 'harbor', 'island', 'jasmine', 'kite', 'lumen', 'maple', 'north', 'orbit', 'pearl', 'river', 'solar', 'tide', 'violet', 'willow'];
    return ucfirst($words[random_int(0, count($words) - 1)]) . '-' . random_int(1000, 9999) . '-' . $words[random_int(0, count($words) - 1)];
}

function staff_accounts(): array {
    return db_all(
        "SELECT u.id, u.email, u.first_name, u.last_name, u.title, u.phone, u.status, u.must_change_password, u.last_login_at, u.last_seen_at, u.created_at,
                r.code AS role, r.label AS role_label
           FROM users u JOIN roles r ON r.id = u.role_id
          WHERE r.code IN ('super_admin','teacher','guidance','clinic')
          ORDER BY CASE r.code WHEN 'super_admin' THEN 0 WHEN 'teacher' THEN 1 WHEN 'guidance' THEN 2 ELSE 3 END, u.last_name"
    );
}

function advisory_map(): array {
    $map = [];
    foreach (db_all('SELECT id, grade_level, name, adviser_id FROM sections ORDER BY grade_level, name') as $s) {
        if ($s['adviser_id']) $map[(int)$s['adviser_id']][] = 'Grade ' . $s['grade_level'] . ' – ' . $s['name'];
    }
    return $map;
}

function section_options(): array {
    $opts = ['' => 'No advisory class'];
    foreach (db_all('SELECT s.id, s.grade_level, s.name, u.first_name, u.last_name FROM sections s LEFT JOIN users u ON u.id = s.adviser_id ORDER BY s.grade_level, s.name') as $s) {
        $opts[$s['id']] = 'Grade ' . $s['grade_level'] . ' – ' . $s['name'] . ($s['first_name'] ? ' (now: ' . $s['first_name'] . ' ' . $s['last_name'] . ')' : '');
    }
    return $opts;
}

function staff_input_error(array $in, int $exceptId = 0): ?string {
    $email = normalize_email($in['email'] ?? '');
    if (!isset(STAFF_ROLES[$in['role'] ?? '']))         return 'Staff accounts can only be Teacher, Guidance Office or Clinic.';
    if (trim($in['first_name'] ?? '') === '' || trim($in['last_name'] ?? '') === '') return 'Please enter the first and last name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))     return 'Please enter a valid e-mail address.';
    if (db_value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $exceptId])) return 'Another account already uses that e-mail.';
    if (($in['section_id'] ?? '') !== '' && $in['role'] !== 'teacher') return 'Only teachers can be assigned an advisory class.';
    if (($in['section_id'] ?? '') !== '' && !db_value('SELECT 1 FROM sections WHERE id = ?', [(int)$in['section_id']])) return 'Please choose a valid section.';
    return null;
}

function assign_advisory(int $teacherId, $sectionId): void {
    db_run('UPDATE sections SET adviser_id = NULL WHERE adviser_id = ?', [$teacherId]);
    if ($sectionId !== '' && $sectionId !== null) db_run('UPDATE sections SET adviser_id = ? WHERE id = ?', [$teacherId, (int)$sectionId]);
}

function admin_create_staff(array $admin, array $in): array {
    require_admin($admin);
    if ($err = staff_input_error($in)) return [null, null, $err];
    $temp = temp_password();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('INSERT INTO users (role_id, email, password_hash, first_name, last_name, title, phone, status, must_change_password, created_by, created_at, updated_at)
                VALUES ((SELECT id FROM roles WHERE code = ?), ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
            [$in['role'], normalize_email($in['email']), password_hash($temp, PASSWORD_DEFAULT), trim($in['first_name']), trim($in['last_name']),
             trim($in['title'] ?? '') ?: null, trim($in['phone'] ?? '') ?: null, 'active', $admin['id'], now(), now()]);
        $id = (int)$pdo->lastInsertId();
        if ($in['role'] === 'teacher') assign_advisory($id, $in['section_id'] ?? '');
        audit('staff_create', 'users', $id, null, ['role', 'email'], $admin);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$id, $temp, null];
}

function admin_update_staff(array $admin, int $id, array $in): ?string {
    require_admin($admin);
    $u = db_one("SELECT u.*, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?", [$id]);
    if (!$u || !isset(STAFF_ROLES[$u['role']])) return 'Only Teacher, Guidance and Clinic accounts can be edited here.';
    if ($err = staff_input_error($in, $id)) return $err;

    $new = ['email' => normalize_email($in['email']), 'first_name' => trim($in['first_name']), 'last_name' => trim($in['last_name']),
            'title' => trim($in['title'] ?? '') ?: null, 'phone' => trim($in['phone'] ?? '') ?: null, 'role' => $in['role']];
    $changed = changed_fields($u, $new);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('UPDATE users SET role_id = (SELECT id FROM roles WHERE code = ?), email = ?, first_name = ?, last_name = ?, title = ?, phone = ?, updated_at = ? WHERE id = ?',
            [$new['role'], $new['email'], $new['first_name'], $new['last_name'], $new['title'], $new['phone'], now(), $id]);
        $currentSection = db_value('SELECT id FROM sections WHERE adviser_id = ?', [$id]);
        $wanted = $new['role'] === 'teacher' ? ($in['section_id'] ?? '') : '';
        if ((string)$currentSection !== (string)$wanted) { assign_advisory($id, $wanted); $changed[] = 'advisory_section'; }
        if ($changed) audit('staff_update', 'users', $id, null, $changed, $admin);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return null;
}

function admin_reset_password(array $admin, int $id): array {
    require_admin($admin);
    $role = db_value('SELECT r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$id]);
    if (!isset(STAFF_ROLES[$role])) return [null, 'Passwords can only be reset here for Teacher, Guidance and Clinic accounts.'];
    $temp = temp_password();
    db_run('UPDATE users SET password_hash = ?, must_change_password = 1, updated_at = ? WHERE id = ?', [password_hash($temp, PASSWORD_DEFAULT), now(), $id]);
    db_run('DELETE FROM login_attempts WHERE email = (SELECT email FROM users WHERE id = ?) AND succeeded = 0', [$id]);
    audit('password_reset', 'users', $id, null, ['password_hash'], $admin);
    return [$temp, null];
}

function admin_set_status(array $admin, int $id, string $status): ?string {
    require_admin($admin);
    if (!in_array($status, ['active', 'disabled'], true)) return 'Unknown status.';
    if ($id === (int)$admin['id']) return "You can't disable your own account.";
    $role = db_value('SELECT r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$id]);
    if (!$role)                 return 'Account not found.';
    if ($role === 'super_admin') return 'Super Admin accounts cannot be changed here.';
    if ($role === 'parent' && !can('admin.parents.manage', $admin)) return 'Not allowed.';
    db_run('UPDATE users SET status = ?, last_seen_at = CASE WHEN ? = \'disabled\' THEN NULL ELSE last_seen_at END, updated_at = ? WHERE id = ?', [$status, $status, now(), $id]);
    audit($status === 'disabled' ? 'account_disable' : 'account_enable', 'users', $id, null, ['status'], $admin);
    return null;
}

function parent_link_requests(string $filter = 'pending'): array {
    $where = ['pending' => "l.status = 'pending'", 'approved' => "l.status = 'approved'", 'closed' => "l.status IN ('rejected','revoked')", 'all' => '1=1'][$filter] ?? "l.status = 'pending'";
    return db_all(
        "SELECT l.*, p.first_name AS p_first, p.last_name AS p_last, p.email, p.phone, p.status AS account_status, p.created_at AS registered_at,
                s.first_name AS s_first, s.last_name AS s_last, s.student_no, s.guardian_name, s.guardian_relation, s.guardian_contact,
                sec.grade_level, sec.name AS section_name, rv.first_name AS rv_first, rv.last_name AS rv_last
           FROM parent_student_links l
           JOIN users p    ON p.id = l.parent_id
           JOIN students s ON s.id = l.student_id
           LEFT JOIN sections sec ON sec.id = s.section_id
           LEFT JOIN users rv ON rv.id = l.reviewed_by
          WHERE $where
          ORDER BY CASE l.status WHEN 'pending' THEN 0 ELSE 1 END, l.created_at DESC"
    );
}

function guardian_match(array $link): string {
    $name = strtolower(trim($link['p_first'] . ' ' . $link['p_last']));
    $onFile = strtolower(trim((string)$link['guardian_name']));
    $phone = preg_replace('/\D/', '', (string)$link['phone']);
    $filePhone = preg_replace('/\D/', '', (string)$link['guardian_contact']);
    if ($onFile !== '' && $onFile === $name) return 'name';
    if ($phone !== '' && $phone === $filePhone) return 'phone';
    if ($onFile !== '' && strtolower(trim($link['p_last'])) !== '' && str_contains($onFile, strtolower(trim($link['p_last'])))) return 'surname';
    return 'none';
}

function admin_review_link(array $admin, int $linkId, string $action, ?string $note = null): ?string {
    require_admin($admin, 'admin.parents.manage');
    $l = db_one('SELECT * FROM parent_student_links WHERE id = ?', [$linkId]);
    if (!$l) return 'Request not found.';
    $allowed = ['approve' => ['pending', 'rejected', 'revoked'], 'reject' => ['pending'], 'revoke' => ['approved'], 'reopen' => ['rejected', 'revoked']];
    if (!isset($allowed[$action]))                       return 'Unknown action.';
    if (!in_array($l['status'], $allowed[$action], true)) return 'This request is already ' . $l['status'] . '.';
    if (in_array($action, ['reject', 'revoke'], true) && trim((string)$note) === '') return 'Please add a short reason (the parent may ask why).';

    $status = ['approve' => 'approved', 'reject' => 'rejected', 'revoke' => 'revoked', 'reopen' => 'pending'][$action];
    db_run('UPDATE parent_student_links SET status = ?, reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?',
        [$status, $admin['id'], now(), trim((string)$note) ?: null, $linkId]);
    audit('link_' . $action, 'parent_student_links', $linkId, (int)$l['student_id'], ['status'], $admin);
    return null;
}

function parent_accounts(): array {
    return db_all(
        "SELECT u.id, u.email, u.first_name, u.last_name, u.phone, u.status, u.created_at, u.last_login_at, u.last_seen_at,
                SUM(CASE WHEN l.status = 'approved' THEN 1 ELSE 0 END) AS approved_links,
                SUM(CASE WHEN l.status = 'pending' THEN 1 ELSE 0 END) AS pending_links
           FROM users u JOIN roles r ON r.id = u.role_id
           LEFT JOIN parent_student_links l ON l.parent_id = u.id
          WHERE r.code = 'parent'
          GROUP BY u.id, u.email, u.first_name, u.last_name, u.phone, u.status, u.created_at, u.last_login_at, u.last_seen_at
          ORDER BY u.created_at DESC"
    );
}

const ACTIVITY_LABELS = [
    'incident_reports'     => ['create' => 'sent a report', 'update' => 'reviewed a report'],
    'commendations'        => ['create' => 'issued a commendation'],
    'clinic_passes'        => ['create' => 'issued a clinic pass'],
    'guidance_cases'       => ['create' => 'opened a case', 'update' => 'updated a case', 'consent_grant' => 'recorded student consent to share', 'consent_withdraw' => 'recorded withdrawal of consent'],
    'case_notes'           => ['create' => 'added a case note'],
    'interventions'        => ['create' => 'added an intervention plan', 'update' => 'updated an intervention plan'],
    'intervention_updates' => ['create' => 'logged intervention progress'],
    'counseling_sessions'  => ['create' => 'scheduled a counseling session', 'update' => 'updated a session'],
    'clinic_visits'        => ['create' => 'checked in a student', 'update' => 'updated a clinic visit'],
    'inventory_items'      => ['create' => 'added a supply item', 'update' => 'updated inventory'],
];

function activity_feed(string $role = '', int $limit = 25): array {
    $entities = array_keys(ACTIVITY_LABELS);
    $in = implode(',', array_fill(0, count($entities), '?'));
    $params = $entities;
    $roleSql = "a.actor_role IN ('teacher','guidance','clinic')";
    if (isset(STAFF_ROLES[$role])) { $roleSql = 'a.actor_role = ?'; $params[] = $role; }
    $rows = db_all(
        "SELECT a.action, a.entity, a.actor_role, a.created_at, u.first_name, u.last_name
           FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
          WHERE a.entity IN ($in) AND $roleSql AND a.action IN ('create','update','consent_grant','consent_withdraw')
          ORDER BY a.created_at DESC, a.id DESC LIMIT " . (int)$limit,
        $params
    );
    foreach ($rows as &$r) $r['label'] = ACTIVITY_LABELS[$r['entity']][$r['action']] ?? ($r['action'] . ' ' . $r['entity']);
    unset($r);
    return $rows;
}

function recent_logins(int $limit = 15): array {
    return db_all(
        "SELECT la.email, la.ip_address, la.succeeded, la.attempted_at, u.first_name, u.last_name, r.label AS role_label
           FROM login_attempts la
           LEFT JOIN users u ON u.email = la.email
           LEFT JOIN roles r ON r.id = u.role_id
          ORDER BY la.attempted_at DESC, la.id DESC LIMIT " . (int)$limit
    );
}
