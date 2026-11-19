<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('user_overview');

const MERIT_CATEGORIES = ['conduct' => 'Good conduct', 'academic' => 'Academic', 'leadership' => 'Leadership',
                          'service' => 'Service', 'kindness' => 'Kindness', 'other' => 'Other'];
const PASS_REASONS     = ['medical' => 'Feeling unwell', 'injury' => 'Injury', 'psychological' => 'Emotional / needs a calm space', 'other' => 'Other'];
const PASS_URGENCY     = ['routine' => 'Routine', 'urgent' => 'Urgent', 'emergency' => 'Emergency'];

$students   = visible_students($user);
$studentIds = array_map('intval', array_column($students, 'id'));
$openModal  = null;
$old        = [];

if (is_post()) {
    verify_csrf();
    $action    = post('action');
    $studentId = (int)post('student_id', 0);
    $old       = $_POST;
    $student   = in_array($studentId, $studentIds, true) ? find_student($user, $studentId) : null;

    if ($action === 'issue_merit') {
        require_permission('commendation.issue');
        $category = (string)post('category', '');
        $title    = trim((string)post('title', ''));
        $reason   = trim((string)post('description', ''));
        $date     = (string)post('awarded_on', date('Y-m-d'));

        $error = !$student ? 'Please choose a student from your class.'
            : (!isset(MERIT_CATEGORIES[$category]) ? 'Please choose a category.'
            : ($title === '' ? 'Please give the commendation a title.'
            : ($reason === '' ? 'Please write the reason for this commendation.'
            : (!DateTime::createFromFormat('Y-m-d', $date) || $date > date('Y-m-d') ? 'Please choose a date that is today or earlier.' : null))));

        if ($error) {
            flash('error', $error);
            $openModal = 'modalMerit';
        } else {
            db_run('INSERT INTO commendations (student_id, issued_by, category, title, description, awarded_on, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$studentId, $user['id'], $category, mb_substr($title, 0, 120), $reason, $date, now()]);
            audit('create', 'commendations', db()->lastInsertId(), $studentId, ['title', 'description', 'category'], $user);
            flash('success', 'Commendation issued to ' . student_name($student) . '. Their family can see it in the Family Portal.');
            redirect('user_overview.php');
        }
    }

    if ($action === 'clinic_pass') {
        require_permission('clinic_pass.issue');
        $reasonType = (string)post('reason_type', '');
        $urgency    = (string)post('urgency', 'routine');
        $reason     = trim((string)post('reason', ''));

        $error = !$student ? 'Please choose a student from your class.'
            : (!isset(PASS_REASONS[$reasonType]) ? 'Please choose a reason type.'
            : (!isset(PASS_URGENCY[$urgency]) ? 'Please choose how urgent this is.'
            : ($reason === '' ? 'Please describe what the student needs.' : null)));

        if ($error) {
            flash('error', $error);
            $openModal = 'modalClinic';
        } else {
            do {
                $code = strtoupper(substr(str_replace(['0', 'O', '1', 'I'], '', bin2hex(random_bytes(6))), 0, 6));
            } while (strlen($code) < 6 || db_value('SELECT 1 FROM clinic_passes WHERE pass_code = ?', [$code]));

            db_run('INSERT INTO clinic_passes (pass_code, student_id, issued_by, reason_type, reason, urgency, status, issued_at, expires_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$code, $studentId, $user['id'], $reasonType, mb_substr($reason, 0, 255), $urgency, 'issued', now(), date('Y-m-d H:i:s', time() + 3600)]);
            audit('create', 'clinic_passes', db()->lastInsertId(), $studentId, ['reason_type', 'reason', 'urgency'], $user);
            flash('success', 'Clinic pass ' . $code . ' issued for ' . student_name($student) . '. It is valid for 1 hour.');
            redirect('user_overview.php');
        }
    }
}

$section    = advisory_section((int)$user['id']);
$attendance = attendance_today($studentIds);
$welfare    = welfare_indicators($studentIds);
$presentToday = count(array_filter($attendance, fn($s) => in_array($s, ['present', 'late'], true)));

$in = $studentIds ? implode(',', array_fill(0, count($studentIds), '?')) : '0';

$pendingReports = (int)db_value("SELECT COUNT(*) FROM incident_reports WHERE reported_by = ? AND status = 'new'", [$user['id']]);
$urgentPending  = (int)db_value("SELECT COUNT(*) FROM incident_reports WHERE reported_by = ? AND status = 'new' AND severity IN ('high','urgent')", [$user['id']]);

$commendations = db_all(
    "SELECT c.title, c.description, c.category, c.awarded_on, s.first_name, s.last_name,
            u.first_name AS by_first, u.last_name AS by_last
       FROM commendations c
       JOIN students s ON s.id = c.student_id
       JOIN users u    ON u.id = c.issued_by
      WHERE c.student_id IN ($in)
      ORDER BY c.awarded_on DESC, c.id DESC",
    $studentIds
);
$monthStart     = date('Y-m-01');
$commThisMonth  = count(array_filter($commendations, fn($c) => $c['awarded_on'] >= $monthStart));

$passesToday = db_all(
    "SELECT p.pass_code, p.reason_type, p.urgency, p.status, p.issued_at, p.expires_at, s.first_name, s.last_name
       FROM clinic_passes p JOIN students s ON s.id = p.student_id
      WHERE p.issued_by = ? AND p.issued_at >= ?
      ORDER BY p.issued_at DESC",
    [$user['id'], date('Y-m-d 00:00:00')]
);

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$tz = config('timezone') ?: 'Asia/Manila';

layout_start($user, 'overview', "$greeting, {$user['first_name']}",
    $section ? "Here is your Grade {$section['grade_level']} advisory welfare overview." : 'Here is the welfare overview for your classes.');

function student_options(array $students, $selected): void {
    echo '<option value="">Choose a student…</option>';
    foreach ($students as $s) {
        $sel = (string)$selected === (string)$s['id'] ? ' selected' : '';
        echo '<option value="' . (int)$s['id'] . '"' . $sel . '>' . e($s['last_name'] . ', ' . $s['first_name']) . ' · ' . e($s['student_no']) . '</option>';
    }
}
?>

<div class="hero-card">
    <div>
        <div class="hero-date">
            <span data-clock="date" data-server-time="<?= time() ?>" data-tz="<?= e($tz) ?>"><?= e(date('l · F j, Y')) ?></span>
            · <span class="live-clock" data-clock="time" data-server-time="<?= time() ?>" data-tz="<?= e($tz) ?>"><?= e(date('g:i:s A')) ?></span>
        </div>
        <div class="hero-title"><?= $section ? e("Grade {$section['grade_level']} – {$section['name']}") : 'My classes' ?></div>
        <div class="hero-desc">
            <?= count($students) ?> learner<?= count($students) === 1 ? '' : 's' ?>
            · <?= $attendance ? "$presentToday present today" : 'Attendance not yet recorded today' ?>
        </div>
    </div>
    <div class="hero-btns">
        <a class="hero-btn" href="log_conduct.php"><?= icon('clipboard') ?> Log incident</a>
        <button type="button" class="hero-btn" data-open="modalMerit"><?= icon('heart') ?> Issue merit</button>
        <button type="button" class="hero-btn" data-open="modalClinic"><?= icon('shield') ?> Clinic pass</button>
    </div>
</div>

<div class="stat-grid-3">
    <a class="stat-card-box" href="student_search.php">
        <div class="stat-icon-wrap ic-violet"><?= icon('users') ?></div>
        <div class="stat-info">
            <span class="stat-lbl">Assigned students</span>
            <div class="stat-val"><?= count($students) ?></div>
            <span class="stat-note">Open the student lookup</span>
        </div>
    </a>

    <a class="stat-card-box" href="log_conduct.php#my-reports">
        <div class="stat-icon-wrap ic-amber"><?= icon('clipboard') ?></div>
        <div class="stat-info">
            <span class="stat-lbl">Reports awaiting review</span>
            <div class="stat-val"><?= $pendingReports ?></div>
            <span class="stat-note <?= $urgentPending ? 'note-amber' : '' ?>"><?= $urgentPending ? "$urgentPending marked high or urgent" : 'Sent to Guidance or Clinic' ?></span>
        </div>
    </a>

    <button type="button" class="stat-card-box" data-open="modalCommendations" aria-haspopup="dialog">
        <div class="stat-icon-wrap ic-green"><?= icon('heart') ?></div>
        <div class="stat-info">
            <span class="stat-lbl">Commendations</span>
            <div class="stat-val"><?= count($commendations) ?></div>
            <span class="stat-note note-green"><?= $commThisMonth ?> this month · view list</span>
        </div>
    </button>
</div>

<div class="card">
    <div class="card-head-flex">
        <div>
            <div class="card-title">Class conduct &amp; health</div>
            <div class="card-sub">Today's attendance and student welfare indicators</div>
        </div>
        <a class="card-link" href="student_search.php">View all students &gt;</a>
    </div>

    <?php if (!$students): ?>
        <div class="empty">No students are assigned to your sections yet. Ask the administrator to set your advisory class.</div>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr><th>Student</th><th>Student ID</th><th>Attendance</th><th>Welfare indicator</th><th style="width: 40px;"></th></tr>
        </thead>
        <tbody>
        <?php foreach ($students as $s):
            $sid = (int)$s['id'];
            $att = $attendance[$sid] ?? null;
            $attTone = ['present' => 'green', 'late' => 'amber', 'absent' => 'red', 'excused' => 'blue'][$att] ?? 'gray';
        ?>
            <tr>
                <td>
                    <div class="stu-cell">
                        <div class="stu-avatar"><?= e(student_initials($s)) ?></div>
                        <a class="stu-name" href="student_search.php?id=<?= $sid ?>"><?= e(student_name($s)) ?></a>
                    </div>
                </td>
                <td class="mono" style="color: #475569;"><?= e($s['student_no']) ?></td>
                <td><span class="badge badge-<?= $attTone ?>"><?= e($att ? ucfirst($att) : 'Not taken') ?></span></td>
                <td>
                    <div class="tags">
                    <?php foreach ($welfare[$sid] ?? [] as $tag): ?>
                        <span class="badge badge-<?= e($tag['tone']) ?>" title="<?= e($tag['hint']) ?>"><?= e($tag['label']) ?></span>
                    <?php endforeach; ?>
                    <?php if (empty($welfare[$sid])): ?><span class="badge badge-gray">No active flags</span><?php endif; ?>
                    </div>
                </td>
                <td style="text-align: right;"><a href="student_search.php?id=<?= $sid ?>" aria-label="Open <?= e(student_name($s)) ?>" style="color: #94a3b8; text-decoration: none; font-weight: bold;">›</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php if ($passesToday): ?>
<div class="card">
    <div class="card-head-flex">
        <div>
            <div class="card-title">Clinic passes issued today</div>
            <div class="card-sub">The clinic checks the pass code when the student arrives.</div>
        </div>
    </div>
    <table class="table">
        <thead><tr><th>Student</th><th>Pass code</th><th>Reason</th><th>Urgency</th><th>Status</th><th>Issued</th></tr></thead>
        <tbody>
        <?php foreach ($passesToday as $p):
            $expired = $p['status'] === 'issued' && $p['expires_at'] < now();
            $status  = $expired ? 'expired' : $p['status'];
            $tone    = ['issued' => 'blue', 'arrived' => 'amber', 'completed' => 'green', 'expired' => 'gray', 'cancelled' => 'gray'][$status] ?? 'gray';
        ?>
            <tr>
                <td><strong><?= e($p['first_name'] . ' ' . $p['last_name']) ?></strong></td>
                <td class="mono"><strong><?= e($p['pass_code']) ?></strong></td>
                <td><?= e(PASS_REASONS[$p['reason_type']] ?? label($p['reason_type'])) ?></td>
                <td><span class="badge badge-<?= $p['urgency'] === 'routine' ? 'gray' : 'red' ?>"><?= e(ucfirst($p['urgency'])) ?></span></td>
                <td><span class="badge badge-<?= $tone ?>"><?= e($status === 'issued' ? 'On the way' : ucfirst($status)) ?></span></td>
                <td class="muted"><?= e(date('g:i A', strtotime($p['issued_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div id="modalMerit" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="meritTitle" aria-hidden="true" <?= $openModal === 'modalMerit' ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="user_overview.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="issue_merit">
        <div class="modal-head">
            <div class="modal-icon ic-green"><?= icon('heart') ?></div>
            <div>
                <h3 id="meritTitle">Issue a commendation</h3>
                <p>Recognize good conduct. Families see commendations in the Family Portal.</p>
            </div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field span-2">
                    <label for="merit_student">Student</label>
                    <select id="merit_student" name="student_id" required><?php student_options($students, $old['student_id'] ?? ''); ?></select>
                </div>
                <div class="field">
                    <label for="merit_category">Category</label>
                    <select id="merit_category" name="category" required>
                        <?php foreach (MERIT_CATEGORIES as $k => $v): ?>
                            <option value="<?= $k ?>" <?= ($old['category'] ?? 'conduct') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="merit_date">Date</label>
                    <input id="merit_date" type="date" name="awarded_on" max="<?= date('Y-m-d') ?>" value="<?= e($old['awarded_on'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="field span-2">
                    <label for="merit_title">Title</label>
                    <input id="merit_title" name="title" maxlength="120" placeholder="e.g. Peer Helper of the Week" value="<?= e($old['title'] ?? '') ?>" required>
                </div>
                <div class="field span-2">
                    <label for="merit_reason">Reason</label>
                    <textarea id="merit_reason" name="description" rows="3" placeholder="What did the student do? Be specific — this is what the family will read." required><?= e($old['description'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-soft" data-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Issue commendation</button>
        </div>
    </form>
</div>

<div id="modalClinic" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="clinicTitle" aria-hidden="true" <?= $openModal === 'modalClinic' ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="user_overview.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="clinic_pass">
        <div class="modal-head">
            <div class="modal-icon ic-blue"><?= icon('shield') ?></div>
            <div>
                <h3 id="clinicTitle">Issue a digital clinic pass</h3>
                <p>The clinic sees the pass right away and checks the code when the student arrives.</p>
            </div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field span-2">
                    <label for="pass_student">Student</label>
                    <select id="pass_student" name="student_id" required><?php student_options($students, $old['student_id'] ?? ''); ?></select>
                </div>
                <div class="field span-2">
                    <span class="field-label">Reason type</span>
                    <div class="choice-row" style="grid-template-columns: 1fr 1fr;">
                        <?php foreach (PASS_REASONS as $k => $v): ?>
                            <label class="choice">
                                <input type="radio" name="reason_type" value="<?= $k ?>" <?= ($old['reason_type'] ?? 'medical') === $k ? 'checked' : '' ?>>
                                <span><?= e($v) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="field span-2">
                    <label for="pass_reason">What does the student need?</label>
                    <textarea id="pass_reason" name="reason" rows="3" maxlength="255" placeholder="e.g. Headache since second period, asked to rest" required><?= e($old['reason'] ?? '') ?></textarea>
                </div>
                <div class="field">
                    <label for="pass_urgency">Urgency</label>
                    <select id="pass_urgency" name="urgency">
                        <?php foreach (PASS_URGENCY as $k => $v): ?>
                            <option value="<?= $k ?>" <?= ($old['urgency'] ?? 'routine') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <span class="field-label">Valid for</span>
                    <div class="info-tile" style="padding: 10px 13px;"><span class="v" style="margin: 0;">1 hour from issue</span></div>
                </div>
            </div>
            <div class="notice notice-warn" style="margin-top: 16px;">
                <?= icon('bell') ?>
                <span>For an emergency, send someone to the clinic or call the nurse as well — don't rely on the pass alone.</span>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-soft" data-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Issue pass</button>
        </div>
    </form>
</div>

<div id="modalCommendations" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="commTitle" aria-hidden="true">
    <div class="modal-card wide">
        <div class="modal-head">
            <div class="modal-icon ic-green"><?= icon('heart') ?></div>
            <div>
                <h3 id="commTitle">Commendations</h3>
                <p><?= count($commendations) ?> awarded to your students · <?= $commThisMonth ?> this month</p>
            </div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body" style="padding-top: 8px;">
            <?php if (!$commendations): ?>
                <div class="empty">No commendations yet. Use “Issue merit” to recognize a student.</div>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>Student</th><th>Commendation</th><th>Date</th></tr></thead>
                <tbody>
                <?php foreach ($commendations as $c): ?>
                    <tr>
                        <td style="white-space: nowrap;"><strong><?= e($c['first_name'] . ' ' . $c['last_name']) ?></strong></td>
                        <td>
                            <strong><?= e($c['title']) ?></strong>
                            <span class="badge badge-green" style="margin-left: 6px; font-size: 0.68rem;"><?= e(MERIT_CATEGORIES[$c['category']] ?? label($c['category'])) ?></span>
                            <div class="muted" style="font-size: 0.8rem; margin-top: 3px;"><?= e($c['description']) ?></div>
                            <div class="muted" style="font-size: 0.72rem; margin-top: 3px;">by <?= e($c['by_first'] . ' ' . $c['by_last']) ?></div>
                        </td>
                        <td class="muted" style="white-space: nowrap;"><?= e(date('M j, Y', strtotime($c['awarded_on']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-primary" data-open="modalMerit" data-close>Issue a new commendation</button>
        </div>
    </div>
</div>

<?php layout_end(); ?>
