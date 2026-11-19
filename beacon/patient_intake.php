<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('patient_intake');

clinic_expire_passes();
$students = visible_students($user);
$items    = inventory_items();
$reopen   = null;

$now = date('Y-m-d\TH:i');
$form = ['student_id' => '', 'pass_code' => '', 'pass_id' => '', 'report_id' => '', 'arrived_at' => $now, 'departed_at' => '',
         'complaint' => '', 'assessment' => '', 'temperature_c' => '', 'blood_pressure' => '', 'pulse_bpm' => '',
         'medication_notes' => '', 'stock_item_id' => '', 'stock_qty' => '1', 'case_status' => 'under_observation', 'is_sensitive' => '', 'parent_notified' => ''];
$fromPass = null;
$fromReport = null;

if (!is_post() && ($pid = (int)query('pass', 0))) {
    $fromPass = db_one("SELECT p.*, s.first_name, s.last_name FROM clinic_passes p JOIN students s ON s.id = p.student_id WHERE p.id = ? AND p.status = 'issued'", [$pid]);
    if ($fromPass) {
        $form['student_id'] = $fromPass['student_id']; $form['pass_id'] = $fromPass['id']; $form['complaint'] = $fromPass['reason'];
        $form['is_sensitive'] = $fromPass['reason_type'] === 'psychological' ? '1' : '';
    } else {
        flash('error', 'That pass is no longer active (already used or expired).');
    }
}
if (!is_post() && ($rid = (int)query('report', 0))) {
    $fromReport = db_one("SELECT r.*, s.first_name, s.last_name FROM incident_reports r JOIN students s ON s.id = r.student_id WHERE r.id = ? AND r.routed_to = 'clinic'", [$rid]);
    if ($fromReport) {
        $form['student_id'] = $fromReport['student_id']; $form['report_id'] = $fromReport['id']; $form['complaint'] = $fromReport['title'];
        $form['is_sensitive'] = $fromReport['record_type'] === 'mental_emotional' ? '1' : '';
    }
}

if (is_post()) {
    verify_csrf();
    $action = (string)post('action');

    if ($action === 'check_in') {
        foreach ($form as $k => $_) $form[$k] = trim((string)post($k, ''));
        [$visitId, $err] = clinic_check_in($user, $form);
        if (!$err) {
            $name = db_one('SELECT first_name, last_name FROM students s JOIN clinic_visits v ON v.student_id = s.id WHERE v.id = ?', [$visitId]);
            flash('success', "{$name['first_name']} {$name['last_name']} checked in.");
            redirect('patient_intake.php#queue');
        }
        flash('error', $err);
    }

    if ($action === 'update_visit') {
        $vid = (int)post('visit_id');
        $err = clinic_update_visit($user, $vid, $_POST);
        if (!$err) { flash('success', 'Visit updated.'); redirect('patient_intake.php#queue'); }
        flash('error', $err);
        $reopen = 'visit-' . $vid;
    }
}

$queue = clinic_visits_where('v.departed_at IS NULL');
$today = clinic_visits_where('v.arrived_at >= ? AND v.departed_at IS NOT NULL', [date('Y-m-d 00:00:00')]);
$medsByVisit = [];
$visitIds = array_merge(array_column($queue, 'id'), array_column($today, 'id'));
if ($visitIds) {
    $in = implode(',', array_fill(0, count($visitIds), '?'));
    foreach (db_all("SELECT visit_id, medication_name, dose FROM medications_administered WHERE visit_id IN ($in)", $visitIds) as $m) {
        $medsByVisit[$m['visit_id']][] = $m;
    }
}

$stockOpts = ['' => 'Not from clinic stock'];
foreach ($items as $i) if ((int)$i['quantity'] > 0) $stockOpts[$i['id']] = $i['name'] . ' (' . (int)$i['quantity'] . ' ' . $i['unit'] . ' left)';
$statusOpts = array_map(fn($s) => $s[0], CLINIC_STATUS);

$alerts = [];
foreach (db_all('SELECT student_id, allergies, conditions FROM medical_profiles') as $m) {
    $alerts[$m['student_id']] = trim(($m['allergies'] ? 'Allergy: ' . $m['allergies'] : '') . ($m['allergies'] && $m['conditions'] ? ' · ' : '') . ($m['conditions'] ? $m['conditions'] : ''));
}

layout_start($user, 'intake', 'Patient intake', 'Check students in and out, record medication given, and tag each visit\'s status.');
?>

<div class="lookup-grid" style="grid-template-columns: minmax(0, 430px) minmax(0, 1fr);">

    <div class="card" style="margin-bottom: 0;">
        <div class="card-title" style="margin-bottom: 4px;">Check in a student</div>
        <div class="card-sub" style="margin-bottom: 18px;">
            <?php if ($fromPass): ?>From pass <strong class="mono"><?= e($fromPass['pass_code']) ?></strong> for <?= e($fromPass['first_name'] . ' ' . $fromPass['last_name']) ?>.
            <?php elseif ($fromReport): ?>From a teacher report about <?= e($fromReport['first_name'] . ' ' . $fromReport['last_name']) ?>.
            <?php else: ?>Students with a pass: enter the code on their pass.<?php endif; ?>
        </div>
        <form method="post" action="patient_intake.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="check_in">
            <input type="hidden" name="pass_id" value="<?= e($form['pass_id']) ?>">
            <input type="hidden" name="report_id" value="<?= e($form['report_id']) ?>">

            <div class="form-grid">
                <div class="field span-2">
                    <label for="ci_student">Student</label>
                    <?= student_picker('ci_student', $students, $form['student_id'], false) ?>
                    <span class="hint" id="allergyHint" style="color: #b91c1c; font-weight: 600;"></span>
                </div>
                <?php if (!$form['pass_id']): ?>
                <div class="field span-2">
                    <label for="ci_code">Clinic pass code <span class="muted" style="font-weight: 500;">(if the student has one)</span></label>
                    <input id="ci_code" name="pass_code" class="mono" maxlength="8" placeholder="e.g. 3BA29B" value="<?= e($form['pass_code']) ?>" style="text-transform: uppercase;">
                </div>
                <?php endif; ?>
                <div class="field">
                    <label for="ci_in">Check-in time</label>
                    <input id="ci_in" type="datetime-local" name="arrived_at" max="<?= $now ?>" value="<?= e($form['arrived_at']) ?>" required>
                </div>
                <div class="field">
                    <label for="ci_out">Check-out time</label>
                    <input id="ci_out" type="datetime-local" name="departed_at" max="<?= $now ?>" value="<?= e($form['departed_at']) ?>">
                    <span class="hint">Leave blank while the student is still here.</span>
                </div>
                <div class="field span-2">
                    <label for="ci_complaint">Main complaint</label>
                    <input id="ci_complaint" name="complaint" maxlength="255" placeholder="e.g. Headache since 2nd period" value="<?= e($form['complaint']) ?>" required>
                </div>
                <div class="field" style="grid-column: span 2; display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;">
                    <div><label for="ci_temp">Temp (°C)</label><input id="ci_temp" name="temperature_c" inputmode="decimal" placeholder="36.8" value="<?= e($form['temperature_c']) ?>"></div>
                    <div><label for="ci_bp">BP</label><input id="ci_bp" name="blood_pressure" maxlength="10" placeholder="110/70" value="<?= e($form['blood_pressure']) ?>"></div>
                    <div><label for="ci_pulse">Pulse</label><input id="ci_pulse" name="pulse_bpm" inputmode="numeric" placeholder="bpm" value="<?= e($form['pulse_bpm']) ?>"></div>
                </div>
                <div class="field span-2">
                    <label for="ci_assess">Assessment / notes <span class="muted" style="font-weight: 500;">(optional)</span></label>
                    <textarea id="ci_assess" name="assessment" rows="2" style="min-height: 64px;" placeholder="What you found and did"><?= e($form['assessment']) ?></textarea>
                </div>
                <div class="field span-2">
                    <label for="ci_meds">Medication administered</label>
                    <textarea id="ci_meds" name="medication_notes" rows="2" style="min-height: 64px;" placeholder="Exact name, dose, route and time — e.g. Paracetamol 500mg, 1 tablet orally, 10:15 AM. Leave blank if none."><?= e($form['medication_notes']) ?></textarea>
                </div>
                <div class="field">
                    <label for="ci_stock">Taken from stock</label>
                    <select id="ci_stock" name="stock_item_id"><?= opts($stockOpts, $form['stock_item_id']) ?></select>
                </div>
                <div class="field">
                    <label for="ci_qty">Quantity used</label>
                    <input id="ci_qty" name="stock_qty" type="number" min="1" value="<?= e($form['stock_qty']) ?>">
                </div>
                <div class="field span-2">
                    <label for="ci_status">Case status</label>
                    <select id="ci_status" name="case_status"><?= opts($statusOpts, $form['case_status']) ?></select>
                    <span class="hint">“Referred to guidance” also sends a report to the Guidance Office.</span>
                </div>
                <div class="field span-2" style="display: grid; gap: 8px;">
                    <label class="check"><input type="checkbox" name="parent_notified" value="1" <?= $form['parent_notified'] ? 'checked' : '' ?>> Parent/guardian was notified</label>
                    <label class="check"><input type="checkbox" name="is_sensitive" value="1" <?= $form['is_sensitive'] ? 'checked' : '' ?>> Psychological / sensitive visit <span class="muted">— parents see only that a visit happened</span></label>
                </div>
            </div>
            <div class="form-actions">
                <?php if ($fromPass || $fromReport): ?><a class="btn btn-soft" href="patient_intake.php">Clear</a><?php endif; ?>
                <button class="btn btn-primary" type="submit">Check in</button>
            </div>
        </form>
    </div>

    <div>
        <div class="card" id="queue">
            <div class="card-head-flex">
                <div><div class="card-title">In the clinic now</div><div class="card-sub">Update the status, add medication, or check the student out.</div></div>
                <span class="badge badge-gray"><?= count($queue) ?> student<?= count($queue) === 1 ? '' : 's' ?></span>
            </div>
            <?php if (!$queue): ?><div class="empty">Nobody is in the clinic right now.</div><?php else: ?>
            <table class="table">
                <thead><tr><th>Student</th><th>In</th><th>Complaint</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($queue as $v): ?>
                    <tr>
                        <td style="white-space: nowrap;"><strong><?= e($v['first_name'] . ' ' . $v['last_name']) ?></strong>
                            <div class="muted" style="font-size: 0.72rem;"><?= $v['pass_code'] ? 'Pass ' . e($v['pass_code']) : 'Walk-in' ?><?= $v['is_sensitive'] ? ' · sensitive' : '' ?></div></td>
                        <td class="muted" style="white-space: nowrap;"><?= e(date('g:i A', strtotime($v['arrived_at']))) ?></td>
                        <td style="font-size: 0.84rem;"><?= e($v['complaint']) ?>
                            <?php if ($v['medication_notes']): ?><div class="muted" style="font-size: 0.74rem; margin-top: 2px;">💊 <?= e(strtok($v['medication_notes'], "\n")) ?></div><?php endif; ?></td>
                        <td><?= badge(...CLINIC_STATUS[$v['case_status']]) ?></td>
                        <td style="text-align: right;"><button type="button" class="btn btn-soft btn-sm" data-open="visit-<?= (int)$v['id'] ?>">Update</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="card" id="today">
            <div class="card-head-flex"><div><div class="card-title">Checked out today</div><div class="card-sub">Times, status and medication for each visit.</div></div></div>
            <?php if (!$today): ?><div class="empty">No completed visits yet today.</div><?php else: ?>
            <table class="table">
                <thead><tr><th>Student</th><th>In – out</th><th>Medication</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($today as $v): ?>
                    <tr>
                        <td><strong><?= e($v['first_name'] . ' ' . $v['last_name']) ?></strong><div class="muted" style="font-size: 0.74rem;"><?= e($v['complaint']) ?></div></td>
                        <td class="muted" style="white-space: nowrap; font-size: 0.82rem;"><?= e(date('g:i', strtotime($v['arrived_at']))) ?> – <?= e(date('g:i A', strtotime($v['departed_at']))) ?></td>
                        <td style="font-size: 0.8rem; white-space: pre-line;"><?= e($v['medication_notes'] ?: '—') ?></td>
                        <td><?= badge(...CLINIC_STATUS[$v['case_status']]) ?></td>
                        <td style="text-align: right;"><button type="button" class="btn btn-soft btn-sm" data-open="visit-<?= (int)$v['id'] ?>">Edit</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php foreach (array_merge($queue, $today) as $v):
    $vid = (int)$v['id'];
    $vf = $reopen === "visit-$vid" ? $_POST : ['case_status' => $v['case_status'], 'departed_at' => $v['departed_at'] ? date('Y-m-d\TH:i', strtotime($v['departed_at'])) : '', 'assessment' => $v['assessment']];
?>
<div id="visit-<?= $vid ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="vt-<?= $vid ?>" aria-hidden="true" <?= $reopen === "visit-$vid" ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="patient_intake.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_visit"><input type="hidden" name="visit_id" value="<?= $vid ?>">
        <div class="modal-head">
            <div class="modal-icon ic-red"><?= icon('heart') ?></div>
            <div>
                <h3 id="vt-<?= $vid ?>"><?= e($v['first_name'] . ' ' . $v['last_name']) ?></h3>
                <p>Checked in <?= e(date('M j, g:i A', strtotime($v['arrived_at']))) ?> · <?= e($v['complaint']) ?></p>
            </div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <?php if (!empty($alerts[$v['student_id']])): ?>
                <div class="notice notice-warn" style="margin-bottom: 14px;"><?= icon('bell') ?><span><strong>Medical alert:</strong> <?= e($alerts[$v['student_id']]) ?></span></div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="field span-2"><label>Case status</label><select name="case_status"><?= opts($statusOpts, $vf['case_status'] ?? '') ?></select></div>
                <div class="field span-2">
                    <label>Check-out time</label>
                    <div style="display: flex; gap: 8px;">
                        <input type="datetime-local" name="departed_at" value="<?= e($vf['departed_at'] ?? '') ?>" data-checkout>
                        <button type="button" class="btn btn-soft btn-sm" data-now style="white-space: nowrap;">Now</button>
                    </div>
                    <span class="hint">Blank = still in the clinic. Sent home, cleared, or hospital checks out automatically.</span>
                </div>
                <div class="field span-2"><label>Assessment / notes</label><textarea name="assessment" rows="2" style="min-height: 64px;"><?= e($vf['assessment'] ?? '') ?></textarea></div>
                <div class="field span-2">
                    <label>Medication given so far</label>
                    <div class="info-tile" style="font-size: 0.82rem; white-space: pre-line;"><?= e($v['medication_notes'] ?: 'None recorded.') ?><?php foreach ($medsByVisit[$vid] ?? [] as $m): ?>

<span class="muted">From stock: <?= e($m['medication_name'] . ' — ' . $m['dose']) ?></span><?php endforeach; ?></div>
                </div>
                <div class="field span-2"><label>Add medication administered</label><textarea name="add_medication" rows="2" style="min-height: 56px;" placeholder="e.g. Paracetamol 500mg, 1 tablet orally"><?= e($vf['add_medication'] ?? '') ?></textarea></div>
                <div class="field"><label>Taken from stock</label><select name="stock_item_id"><?= opts($stockOpts, $vf['stock_item_id'] ?? '') ?></select></div>
                <div class="field"><label>Quantity used</label><input type="number" name="stock_qty" min="1" value="<?= e($vf['stock_qty'] ?? '1') ?>"></div>
                <div class="field span-2"><label class="check"><input type="checkbox" name="parent_notified" value="1" <?= $v['parent_notified'] ? 'checked' : '' ?>> Parent/guardian was notified</label></div>
            </div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-soft" data-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</div>
<?php endforeach; ?>

<style>
    .lookup-grid { display: grid; gap: 24px; align-items: start; }
    @media (max-width: 1100px) { .lookup-grid { grid-template-columns: 1fr !important; } }
</style>
<script>
(function () {
    var alerts = <?= json_encode((object)$alerts) ?>;
    var sel = document.getElementById('ci_student'), hint = document.getElementById('allergyHint');
    function show() { hint.textContent = alerts[sel.value] ? '⚠ ' + alerts[sel.value] : ''; }
    if (sel) { sel.addEventListener('change', show); show(); }

    document.querySelectorAll('[data-now]').forEach(function (b) {
        b.addEventListener('click', function () {
            var d = new Date(), pad = function (n) { return String(n).padStart(2, '0'); };
            b.parentNode.querySelector('[data-checkout]').value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        });
    });
})();
</script>

<?php layout_end(); ?>
