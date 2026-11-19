<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('log_conduct');

const RECORD_TYPES = [
    'conduct' => [
        'label' => 'Behavioral conduct', 'hint' => 'Classroom behavior, conflicts, academic or attendance concerns',
        'categories' => ['behavioral' => 'Classroom behavior', 'peer_conflict' => 'Peer conflict', 'academic' => 'Academic concern',
                         'attendance' => 'Attendance / tardiness', 'other' => 'Other'],
        'routes' => ['guidance'], 'default_route' => 'guidance',
    ],
    'physical' => [
        'label' => 'Physical state', 'hint' => 'Illness, pain, injury, fatigue, hunger',
        'categories' => ['physical' => 'Physical health concern'],
        'routes' => ['clinic', 'guidance'], 'default_route' => 'clinic',
    ],
    'mental_emotional' => [
        'label' => 'Mental / emotional state', 'hint' => 'Distress, anxiety, withdrawal, sudden changes in mood',
        'categories' => ['emotional' => 'Emotional distress', 'mental_health' => 'Mental health concern'],
        'routes' => ['guidance', 'clinic'], 'default_route' => 'guidance',
    ],
    'commendation' => [
        'label' => 'Commendation', 'hint' => 'Positive behavior worth recognizing',
        'categories' => ['conduct' => 'Good conduct', 'academic' => 'Academic', 'leadership' => 'Leadership',
                         'service' => 'Service', 'kindness' => 'Kindness', 'other' => 'Other'],
        'routes' => [], 'default_route' => null,
    ],
];
const ROUTES   = ['guidance' => ['Guidance Office', 'Counselors review it and follow up'],
                  'clinic'   => ['Clinic', 'The nurse reviews it and checks on the student']];
const SEVERITY = ['low' => 'Low', 'moderate' => 'Moderate', 'high' => 'High', 'urgent' => 'Urgent'];

$students   = visible_students($user);
$studentIds = array_map('intval', array_column($students, 'id'));

$old = [
    'student_id'  => (string)query('student_id', ''),
    'record_type' => 'conduct',
    'category'    => '',
    'title'       => '',
    'severity'    => 'low',
    'observed_at' => date('Y-m-d\TH:i'),
    'description' => '',
    'action_taken'=> '',
    'routed_to'   => '',
];

if (is_post()) {
    verify_csrf();
    foreach ($old as $k => $_) $old[$k] = trim((string)post($k, ''));

    $type      = $old['record_type'];
    $def       = RECORD_TYPES[$type] ?? null;
    $studentId = (int)$old['student_id'];
    $student   = in_array($studentId, $studentIds, true) ? find_student($user, $studentId) : null;
    $observed  = DateTime::createFromFormat('Y-m-d\TH:i', $old['observed_at']);
    $route     = $def ? ($def['routes'] ? ($old['routed_to'] ?: $def['default_route']) : null) : null;

    $error = !$student ? 'Please choose a student from your class.'
        : (!$def ? 'Please choose a record type.'
        : (!isset($def['categories'][$old['category']]) ? 'Please choose a category for this record type.'
        : ($old['title'] === '' ? 'Please add a short title.'
        : ($old['description'] === '' ? 'Please describe what you observed.'
        : (!$observed || $observed > new DateTime('+5 minutes') ? 'Please enter when this happened (not in the future).'
        : ($def['routes'] && !in_array($route, $def['routes'], true) ? 'Please choose where to send this report.'
        : ($type !== 'commendation' && !isset(SEVERITY[$old['severity']]) ? 'Please choose a severity.' : null)))))));

    if ($error) {
        flash('error', $error);
    } elseif ($type === 'commendation') {
        require_permission('commendation.issue');
        $reason = $old['description'] . ($old['action_taken'] !== '' ? "\n\n" . $old['action_taken'] : '');
        db_run('INSERT INTO commendations (student_id, issued_by, category, title, description, awarded_on, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$studentId, $user['id'], $old['category'], mb_substr($old['title'], 0, 120), $reason, $observed->format('Y-m-d'), now()]);
        audit('create', 'commendations', db()->lastInsertId(), $studentId, ['title', 'description', 'category'], $user);
        flash('success', 'Commendation saved for ' . student_name($student) . '. Their family can see it in the Family Portal.');
        redirect('log_conduct.php#my-reports');
    } else {
        db_run('INSERT INTO incident_reports (student_id, reported_by, record_type, category, severity, routed_to, title, description, action_taken, observed_at, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$studentId, $user['id'], $type, $old['category'], $old['severity'], $route, mb_substr($old['title'], 0, 150),
             $old['description'], $old['action_taken'] ?: null, $observed->format('Y-m-d H:i:s'), 'new', now()]);
        audit('create', 'incident_reports', db()->lastInsertId(), $studentId, ['record_type', 'category', 'severity', 'routed_to', 'description'], $user);

        $msg = RECORD_TYPES[$type]['label'] . ' report for ' . student_name($student) . ' sent to the ' . ROUTES[$route][0] . '.';
        if ($route === 'clinic') $msg .= ' If the student needs to go now, also issue a clinic pass from your dashboard.';
        flash('success', $msg);
        redirect('log_conduct.php#my-reports');
    }
}

$myReports = db_all(
    "SELECT r.record_type, r.category, r.severity, r.routed_to, r.title, r.status, r.observed_at, s.first_name, s.last_name
       FROM incident_reports r JOIN students s ON s.id = r.student_id
      WHERE r.reported_by = ? ORDER BY r.created_at DESC LIMIT 15",
    [$user['id']]
);

layout_start($user, 'conduct', 'Log conduct', 'Record behavior, a physical or mental/emotional state, or a commendation.');
?>

<div class="card" style="max-width: 860px; margin: 0 auto 24px;">
    <div class="card-head-flex" style="margin-bottom: 22px;">
        <div>
            <div class="card-title">New record</div>
            <div class="card-sub">Reports go straight to the office you choose. Commendations appear in the Family Portal.</div>
        </div>
    </div>

    <?php if (!$students): ?>
        <div class="empty">No students are assigned to your sections yet.</div>
    <?php else: ?>
    <form method="post" action="log_conduct.php" id="conductForm" novalidate>
        <?= csrf_field() ?>

        <div class="field" style="margin-bottom: 20px;">
            <span class="field-label">Record type</span>
            <div class="choice-row" style="grid-template-columns: repeat(4, 1fr);">
                <?php foreach (RECORD_TYPES as $k => $t): ?>
                    <label class="choice">
                        <input type="radio" name="record_type" value="<?= $k ?>" <?= $old['record_type'] === $k ? 'checked' : '' ?>>
                        <span><?= e($t['label']) ?><small><?= e($t['hint']) ?></small></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="student_id">Student</label>
                <select id="student_id" name="student_id" required>
                    <option value="">Choose a student…</option>
                    <?php foreach ($students as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $old['student_id'] === (string)$s['id'] ? 'selected' : '' ?>>
                            <?= e($s['last_name'] . ', ' . $s['first_name']) ?> · <?= e($s['student_no']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="category">Category</label>
                <select id="category" name="category" required>
                    <option value="">Choose a category…</option>
                    <?php foreach (RECORD_TYPES as $k => $t): ?>
                        <optgroup label="<?= e($t['label']) ?>" data-type="<?= $k ?>">
                            <?php foreach ($t['categories'] as $ck => $cv): ?>
                                <option value="<?= $ck ?>" <?= $old['record_type'] === $k && $old['category'] === $ck ? 'selected' : '' ?>><?= e($cv) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="title">Title</label>
                <input id="title" name="title" maxlength="150" placeholder="A short summary, e.g. Complained of a headache" value="<?= e($old['title']) ?>" required>
            </div>

            <div class="field">
                <label for="observed_at">When did this happen?</label>
                <input id="observed_at" type="datetime-local" name="observed_at" max="<?= date('Y-m-d\TH:i') ?>" value="<?= e($old['observed_at']) ?>" required>
            </div>

            <div class="field" data-hide-for="commendation">
                <label for="severity">Severity</label>
                <select id="severity" name="severity">
                    <?php foreach (SEVERITY as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $old['severity'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field span-2">
                <label for="description" data-label-commendation="What did the student do?">What did you observe?</label>
                <textarea id="description" name="description" rows="4" placeholder="Describe what you saw or heard, when, and anything the student said. Stick to facts." required><?= e($old['description']) ?></textarea>
            </div>

            <div class="field span-2">
                <label for="action_taken" data-label-commendation="Anything else to add? (optional)">Action already taken <span class="muted" style="font-weight: 500;">(optional)</span></label>
                <input id="action_taken" name="action_taken" placeholder="e.g. Let the student rest, spoke privately, called the parent" value="<?= e($old['action_taken']) ?>">
            </div>

            <div class="field span-2" id="routeField">
                <span class="field-label">Send this report to</span>
                <div class="choice-row">
                    <?php foreach (ROUTES as $k => [$name, $desc]): ?>
                        <label class="choice" data-route="<?= $k ?>">
                            <input type="radio" name="routed_to" value="<?= $k ?>" <?= $old['routed_to'] === $k ? 'checked' : '' ?>>
                            <span><?= e($name) ?><small><?= e($desc) ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <span class="hint" id="routeHint"></span>
            </div>
        </div>

        <div class="notice notice-warn" id="urgentNotice" style="margin-top: 18px;" hidden>
            <?= icon('bell') ?>
            <span><strong>Urgent?</strong> If the student may be in danger or needs care right now, go to the Guidance Office or Clinic in person (or send another adult) — don't wait for this report to be read.</span>
        </div>

        <div class="form-actions">
            <a class="btn btn-soft" href="user_overview.php">Cancel</a>
            <button class="btn btn-primary" type="submit" id="submitBtn">Save and send</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<div class="card" id="my-reports" style="max-width: 860px; margin: 0 auto;">
    <div class="card-head-flex">
        <div>
            <div class="card-title">My recent reports</div>
            <div class="card-sub">What you've sent and whether the office has picked it up.</div>
        </div>
    </div>
    <?php if (!$myReports): ?>
        <div class="empty">You haven't sent any reports yet.</div>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Student</th><th>Report</th><th>Sent to</th><th>Status</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($myReports as $r):
            $tone = ['new' => 'amber', 'acknowledged' => 'blue', 'linked' => 'purple', 'closed' => 'gray'][$r['status']] ?? 'gray';
            $statusLabel = ['new' => 'Awaiting review', 'acknowledged' => 'Seen by office', 'linked' => 'Being followed up', 'closed' => 'Closed'][$r['status']] ?? $r['status'];
        ?>
            <tr>
                <td style="white-space: nowrap;"><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong></td>
                <td>
                    <?= e($r['title']) ?>
                    <div class="muted" style="font-size: 0.75rem; margin-top: 2px;">
                        <?= e(RECORD_TYPES[$r['record_type']]['label'] ?? label($r['record_type'])) ?> · <?= e(SEVERITY[$r['severity']] ?? $r['severity']) ?>
                    </div>
                </td>
                <td><?= e(ROUTES[$r['routed_to']][0] ?? '') ?></td>
                <td><span class="badge badge-<?= $tone ?>"><?= e($statusLabel) ?></span></td>
                <td class="muted" style="white-space: nowrap;"><?= e(date('M j, g:i A', strtotime($r['observed_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
(function () {
    var form = document.getElementById('conductForm');
    if (!form) return;
    var types = <?= json_encode(array_map(fn($t) => ['routes' => $t['routes'], 'default' => $t['default_route']], RECORD_TYPES)) ?>;
    var category = document.getElementById('category');
    var routeField = document.getElementById('routeField');
    var routeHint = document.getElementById('routeHint');
    var severity = document.getElementById('severity');
    var urgent = document.getElementById('urgentNotice');
    var submit = document.getElementById('submitBtn');

    function currentType() { return form.querySelector('input[name=record_type]:checked').value; }

    function applyType(resetCategory) {
        var t = currentType(), def = types[t];

        category.querySelectorAll('optgroup').forEach(function (g) {
            var match = g.getAttribute('data-type') === t;
            g.hidden = !match; g.disabled = !match;
        });
        var opts = category.querySelectorAll('optgroup[data-type="' + t + '"] option');
        if (resetCategory || (category.selectedOptions[0] && category.selectedOptions[0].parentNode.disabled)) {
            category.value = opts.length === 1 ? opts[0].value : '';
        }

        if (!def.routes.length) {
            routeField.hidden = true;
        } else {
            routeField.hidden = false;
            form.querySelectorAll('[data-route]').forEach(function (el) {
                el.hidden = def.routes.indexOf(el.getAttribute('data-route')) === -1;
            });
            var checked = form.querySelector('input[name=routed_to]:checked');
            if (!checked || def.routes.indexOf(checked.value) === -1 || resetCategory) {
                form.querySelector('input[name=routed_to][value="' + def.default + '"]').checked = true;
            }
            routeHint.textContent = def.routes.length === 1
                ? 'Behavioral reports always go to the Guidance Office.'
                : 'Choose the office best placed to help. You can pick either one.';
        }

        form.querySelectorAll('[data-hide-for]').forEach(function (el) { el.hidden = el.getAttribute('data-hide-for') === t; });
        form.querySelectorAll('[data-label-commendation]').forEach(function (el) {
            if (!el.dataset.labelDefault) el.dataset.labelDefault = el.innerHTML;
            el.innerHTML = t === 'commendation' ? el.getAttribute('data-label-commendation') : el.dataset.labelDefault;
        });
        submit.textContent = t === 'commendation' ? 'Save commendation' : 'Save and send';
        applySeverity();
    }
    function applySeverity() { urgent.hidden = currentType() === 'commendation' || ['high', 'urgent'].indexOf(severity.value) === -1; }

    form.querySelectorAll('input[name=record_type]').forEach(function (r) { r.addEventListener('change', function () { applyType(true); }); });
    severity.addEventListener('change', applySeverity);
    applyType(false);
})();
</script>

<?php layout_end(); ?>
