<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('admin_staff');

$reopen = null;
if (is_post()) {
    verify_csrf();
    $action = (string)post('action');
    $id = (int)post('user_id', 0);

    if ($action === 'create') {
        [$newId, $temp, $err] = admin_create_staff($user, $_POST);
        if (!$err) {
            $_SESSION['new_credentials'] = ['name' => trim(post('first_name') . ' ' . post('last_name')), 'email' => normalize_email((string)post('email')), 'password' => $temp, 'kind' => 'created'];
            redirect('admin_staff.php');
        }
        flash('error', $err); $reopen = 'modalCreate';
    }
    if ($action === 'update') {
        $err = admin_update_staff($user, $id, $_POST);
        if (!$err) { flash('success', 'Account updated.'); redirect('admin_staff.php'); }
        flash('error', $err); $reopen = 'staff-' . $id;
    }
    if ($action === 'reset') {
        [$temp, $err] = admin_reset_password($user, $id);
        if ($err) { flash('error', $err); redirect('admin_staff.php'); }
        $u = db_one('SELECT first_name, last_name, email FROM users WHERE id = ?', [$id]);
        $_SESSION['new_credentials'] = ['name' => $u['first_name'] . ' ' . $u['last_name'], 'email' => $u['email'], 'password' => $temp, 'kind' => 'reset'];
        redirect('admin_staff.php');
    }
    if ($action === 'status') {
        $err = admin_set_status($user, $id, (string)post('status'));
        flash($err ? 'error' : 'success', $err ?: (post('status') === 'disabled' ? 'Account disabled. They are signed out on their next click.' : 'Account enabled.'));
        redirect('admin_staff.php');
    }
}

$creds = $_SESSION['new_credentials'] ?? null;
unset($_SESSION['new_credentials']);

$fRole = isset(STAFF_ROLES[query('role', '')]) ? query('role') : '';
$staff = staff_accounts();
$advisory = advisory_map();
$sections = section_options();
$counts = array_count_values(array_column(array_filter($staff, fn($s) => $s['status'] === 'active'), 'role'));
$shown = $fRole ? array_filter($staff, fn($s) => $s['role'] === $fRole) : $staff;

layout_start($user, 'staff', 'Staff accounts', 'Create and manage logins for Teachers, the Guidance Office and the Clinic.');
?>

<?php if ($creds): ?>
<div class="card card-success">
    <div style="display: flex; gap: 14px; align-items: flex-start; flex-wrap: wrap;">
        <div class="modal-icon ic-green"><?= icon('key') ?></div>
        <div style="flex: 1; min-width: 260px;">
            <div class="card-title" style="font-size: 1rem;"><?= $creds['kind'] === 'created' ? 'Account created for ' : 'Password reset for ' ?><?= e($creds['name']) ?></div>
            <p class="muted" style="font-size: 0.82rem; margin: 4px 0 12px;">Give these to them privately. The password is shown <strong>only once</strong> — they must change it when they first sign in.</p>
            <div class="form-grid" style="gap: 10px; max-width: 620px;">
                <div class="info-tile"><span class="k">E-mail</span><span class="v mono" id="credEmail"><?= e($creds['email']) ?></span></div>
                <div class="info-tile"><span class="k">Temporary password</span><span class="v mono" id="credPass"><?= e($creds['password']) ?></span></div>
            </div>
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="copyCreds">Copy both</button>
    </div>
</div>
<?php endif; ?>

<div class="stat-grid-4">
    <?php foreach (['teacher' => ['users', 'ic-blue'], 'guidance' => ['shield', 'ic-violet'], 'clinic' => ['heart', 'ic-red']] as $r => [$ico, $cls]): ?>
        <a class="stat-card-box" href="admin_staff.php?role=<?= $r ?>"><div class="stat-icon-wrap <?= $cls ?>"><?= icon($ico) ?></div><div class="stat-info"><span class="stat-lbl"><?= e(STAFF_ROLES[$r]) ?></span><div class="stat-val"><?= $counts[$r] ?? 0 ?></div><span class="stat-note">active accounts</span></div></a>
    <?php endforeach; ?>
    <div class="stat-card-box"><div class="stat-icon-wrap ic-amber"><?= icon('key') ?></div><div class="stat-info"><span class="stat-lbl">Waiting for first sign-in</span><div class="stat-val"><?= count(array_filter($staff, fn($s) => $s['must_change_password'] && $s['role'] !== 'super_admin' && $s['status'] === 'active')) ?></div><span class="stat-note">temporary password not yet changed</span></div></div>
</div>

<div class="card">
    <div class="card-head-flex">
        <div><div class="card-title">Staff</div><div class="card-sub">You can create Teacher, Guidance Office and Clinic accounts. Parents register themselves.</div></div>
        <button type="button" class="btn btn-primary btn-sm" data-open="modalCreate">+ Create staff account</button>
    </div>
    <div class="chip-row" style="margin-bottom: 14px;">
        <?php foreach (['' => 'All'] + STAFF_ROLES as $k => $lbl): ?>
            <a class="chip<?= $fRole === $k ? ' active' : '' ?>" href="admin_staff.php<?= $k ? '?role=' . $k : '' ?>" style="text-decoration: none;"><?= e($lbl) ?></a>
        <?php endforeach; ?>
    </div>
    <table class="table">
        <thead><tr><th>Name</th><th>Role</th><th>Advisory class</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($shown as $s): $sid = (int)$s['id']; $editable = isset(STAFF_ROLES[$s['role']]); ?>
            <tr style="<?= $s['status'] === 'disabled' ? 'opacity: .55;' : '' ?>">
                <td><strong><?= e($s['first_name'] . ' ' . $s['last_name']) ?></strong><div class="muted" style="font-size: 0.74rem;"><?= e($s['email']) ?><?= $s['title'] ? ' · ' . e($s['title']) : '' ?></div></td>
                <td><?= badge($s['role_label'], ['super_admin' => 'purple', 'teacher' => 'blue', 'guidance' => 'purple', 'clinic' => 'red'][$s['role']]) ?></td>
                <td class="muted" style="font-size: 0.82rem;"><?= e(implode(', ', $advisory[$sid] ?? []) ?: '—') ?></td>
                <td>
                    <?php if ($s['status'] === 'disabled'): ?><?= badge('Disabled', 'gray') ?>
                    <?php elseif ($s['must_change_password']): ?><?= badge('Temporary password', 'amber') ?>
                    <?php else: ?><?= badge('Active', 'green') ?><?php endif; ?>
                    <?php if (is_online($s['last_seen_at'])): ?><div style="font-size: 0.7rem; color: #15803d; font-weight: 700; margin-top: 3px;">● Online</div><?php endif; ?>
                </td>
                <td class="muted" style="font-size: 0.8rem; white-space: nowrap;"><?= $s['last_login_at'] ? e(date('M j, g:i A', strtotime($s['last_login_at']))) : 'Never' ?></td>
                <td>
                    <?php if ($editable): ?>
                    <div class="row-actions">
                        <button type="button" class="btn btn-soft btn-sm" data-open="staff-<?= $sid ?>">Edit</button>
                        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="user_id" value="<?= $sid ?>"><button class="btn btn-soft btn-sm" type="submit">Reset password</button></form>
                        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="user_id" value="<?= $sid ?>"><input type="hidden" name="status" value="<?= $s['status'] === 'active' ? 'disabled' : 'active' ?>">
                            <button class="btn btn-sm <?= $s['status'] === 'active' ? 'btn-danger-soft' : 'btn-soft' ?>" type="submit"><?= $s['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form>
                    </div>
                    <?php else: ?><span class="muted" style="font-size: 0.75rem;"><?= $sid === (int)$user['id'] ? 'You' : 'Protected' ?></span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
function staff_form_fields(array $v, array $sections, string $prefix): void { ?>
    <div class="form-grid">
        <div class="field span-2">
            <span class="field-label">Role</span>
            <div class="choice-row" style="grid-template-columns: repeat(3, 1fr);">
                <?php foreach (STAFF_ROLES as $k => $lbl): ?>
                    <label class="choice"><input type="radio" name="role" value="<?= $k ?>" <?= ($v['role'] ?? 'teacher') === $k ? 'checked' : '' ?> data-role-radio><span><?= e($lbl) ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="field"><label for="<?= $prefix ?>_first">First name</label><input id="<?= $prefix ?>_first" name="first_name" maxlength="80" value="<?= e($v['first_name'] ?? '') ?>" required></div>
        <div class="field"><label for="<?= $prefix ?>_last">Last name</label><input id="<?= $prefix ?>_last" name="last_name" maxlength="80" value="<?= e($v['last_name'] ?? '') ?>" required></div>
        <div class="field span-2"><label for="<?= $prefix ?>_email">School e-mail <span class="muted" style="font-weight: 500;">(their username)</span></label><input id="<?= $prefix ?>_email" name="email" type="email" maxlength="190" value="<?= e($v['email'] ?? '') ?>" required></div>
        <div class="field"><label for="<?= $prefix ?>_title">Job title <span class="muted" style="font-weight: 500;">(optional)</span></label><input id="<?= $prefix ?>_title" name="title" maxlength="100" placeholder="e.g. Teacher · Adviser" value="<?= e($v['title'] ?? '') ?>"></div>
        <div class="field"><label for="<?= $prefix ?>_phone">Phone <span class="muted" style="font-weight: 500;">(optional)</span></label><input id="<?= $prefix ?>_phone" name="phone" maxlength="30" value="<?= e($v['phone'] ?? '') ?>"></div>
        <div class="field span-2" data-teacher-only>
            <label for="<?= $prefix ?>_section">Advisory class</label>
            <select id="<?= $prefix ?>_section" name="section_id"><?= opts($sections, $v['section_id'] ?? '') ?></select>
            <span class="hint">Teachers only see students in their advisory class. Picking a class that already has an adviser moves it to this teacher.</span>
        </div>
    </div>
<?php }
?>

<div id="modalCreate" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="crTitle" aria-hidden="true" <?= $reopen === 'modalCreate' ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="admin_staff.php" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="modal-head">
            <div class="modal-icon ic-blue"><?= icon('key') ?></div>
            <div><h3 id="crTitle">Create a staff account</h3><p>A temporary password is generated. They set their own at first sign-in.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body"><?php staff_form_fields($reopen === 'modalCreate' ? $_POST : [], $sections, 'cr'); ?></div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary">Create account</button></div>
    </form>
</div>

<?php foreach ($staff as $s): if (!isset(STAFF_ROLES[$s['role']])) continue; $sid = (int)$s['id'];
    $current = db_value('SELECT id FROM sections WHERE adviser_id = ?', [$sid]);
    $v = $reopen === "staff-$sid" ? $_POST : $s + ['section_id' => $current]; ?>
<div id="staff-<?= $sid ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ed-<?= $sid ?>" aria-hidden="true" <?= $reopen === "staff-$sid" ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="admin_staff.php" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?= $sid ?>">
        <div class="modal-head">
            <div class="modal-icon ic-blue"><?= icon('users') ?></div>
            <div><h3 id="ed-<?= $sid ?>">Edit <?= e($s['first_name'] . ' ' . $s['last_name']) ?></h3><p>Changing the role signs them out so the new access applies.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body"><?php staff_form_fields($v, $sections, 'ed' . $sid); ?></div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary">Save changes</button></div>
    </form>
</div>
<?php endforeach; ?>

<script>
(function () {
    document.querySelectorAll('.modal-card').forEach(function (form) {
        var radios = form.querySelectorAll('[data-role-radio]'), box = form.querySelector('[data-teacher-only]');
        if (!radios.length || !box) return;
        function apply() { var r = form.querySelector('[data-role-radio]:checked'); box.hidden = !r || r.value !== 'teacher'; if (box.hidden) box.querySelector('select').value = ''; }
        radios.forEach(function (r) { r.addEventListener('change', apply); });
        apply();
    });
    var copy = document.getElementById('copyCreds');
    if (copy) copy.addEventListener('click', function () {
        var text = 'BEACON sign-in\nE-mail: ' + document.getElementById('credEmail').textContent + '\nTemporary password: ' + document.getElementById('credPass').textContent;
        (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { copy.textContent = 'Copied'; }, function () { copy.textContent = 'Select and copy manually'; });
    });
})();
</script>

<?php layout_end(); ?>
