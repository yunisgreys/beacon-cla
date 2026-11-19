<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_page('notices_manage');

$reopen = null;
if (is_post()) {
    verify_csrf();
    if (post('action') === 'create') {
        $err = notice_create($user, $_POST);
        if (!$err) { flash('success', 'Notice posted to the Family Portal.'); redirect('notices_manage.php'); }
        flash('error', $err); $reopen = 'modalNotice';
    }
    if (post('action') === 'delete') {
        $err = notice_delete($user, (int)post('notice_id'));
        flash($err ? 'error' : 'success', $err ?: 'Notice removed.');
        redirect('notices_manage.php');
    }
}

$sections = postable_sections($user);
$isTeacher = $user['role'] === 'teacher';
if ($isTeacher) {
    $mine = array_map('intval', array_column($sections, 'id'));
    $notices = db_all('SELECT n.*, u.first_name, u.last_name FROM notices n JOIN users u ON u.id = n.posted_by ORDER BY n.published_at DESC');
    $notices = array_values(array_filter($notices, fn($n) => $n['audience'] !== 'section' || in_array((int)$n['section_id'], $mine, true)));
} else {
    $notices = db_all('SELECT n.*, u.first_name, u.last_name FROM notices n JOIN users u ON u.id = n.posted_by ORDER BY n.published_at DESC');
}
$sectionOpts = [];
foreach ($sections as $s) $sectionOpts[$s['id']] = 'Grade ' . $s['grade_level'] . ' – ' . $s['name'];
$grades = array_column(db_all('SELECT DISTINCT grade_level FROM sections ORDER BY grade_level'), 'grade_level');
$gradeOpts = array_combine($grades, array_map(fn($g) => "Grade $g", $grades)) ?: [];
$audOpts = $isTeacher ? ['section' => 'My section'] : ['all' => 'All families', 'grade' => 'One grade level', 'section' => 'One section'];
$f = $reopen ? $_POST : ['tag' => ['clinic' => 'clinic', 'guidance' => 'guidance'][$user['role']] ?? 'announcement', 'audience' => $isTeacher ? 'section' : 'all'];

layout_start($user, 'notices', 'Family notices', 'Post announcements to the Family Portal. Parents can filter them by tag.');
?>

<div class="card">
    <div class="card-head-flex">
        <div><div class="card-title">Posted notices</div><div class="card-sub"><?= $isTeacher ? 'You can post to your own section. ' : '' ?>Tags help parents find what matters: Important, Announcement, Clinic Update, Guidance Notice.</div></div>
        <button type="button" class="btn btn-primary btn-sm" data-open="modalNotice" <?= $isTeacher && !$sections ? 'disabled title="No section assigned to you"' : '' ?>>+ Post a notice</button>
    </div>
    <?php if (!$notices): ?><div class="empty">No notices yet.</div><?php endif; ?>
    <?php foreach ($notices as $n): [$lbl, $tone] = NOTICE_TAGS[$n['tag']]; [$reach, $acks] = notice_reach($n);
        $canDelete = (int)$n['posted_by'] === (int)$user['id'] || $user['role'] === 'super_admin'; ?>
        <div style="padding: 16px 0; border-top: 1px solid var(--border); display: flex; gap: 16px; justify-content: space-between; align-items: flex-start;">
            <div style="flex: 1;">
                <div class="tags" style="margin-bottom: 6px;"><?= badge($lbl, $tone) ?> <?= badge(notice_audience_label($n), 'gray') ?></div>
                <strong style="font-size: 0.95rem;"><?= e($n['title']) ?></strong>
                <div style="font-size: 0.84rem; color: #475569; margin-top: 4px; line-height: 1.55;"><?= e(mb_strlen($n['body']) > 220 ? mb_substr($n['body'], 0, 217) . '…' : $n['body']) ?></div>
                <div class="muted" style="font-size: 0.74rem; margin-top: 6px;">
                    <?= e($n['first_name'] . ' ' . $n['last_name']) ?> · <?= e(date('M j, Y g:i A', strtotime($n['published_at']))) ?> · reaches <?= $reach ?> famil<?= $reach === 1 ? 'y' : 'ies' ?>
                    <?php if ($n['requires_ack']): ?> · <strong style="color: <?= $acks >= $reach && $reach ? '#15803d' : '#b45309' ?>;"><?= $acks ?>/<?= $reach ?> acknowledged</strong><?php endif; ?>
                </div>
            </div>
            <?php if ($canDelete): ?>
                <form method="post" class="inline-form" data-confirm="Remove this notice from the Family Portal?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="notice_id" value="<?= (int)$n['id'] ?>"><button class="btn btn-danger-soft btn-sm" type="submit">Remove</button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<div id="modalNotice" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ntTitle" aria-hidden="true" <?= $reopen ? 'data-autoopen' : '' ?>>
    <form class="modal-card" method="post" action="notices_manage.php" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="modal-head">
            <div class="modal-icon ic-blue"><?= icon('bell') ?></div>
            <div><h3 id="ntTitle">Post a family notice</h3><p>It appears in the Family Portal right away.</p></div>
            <button type="button" class="modal-close" data-close aria-label="Close">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <div class="field span-2">
                    <span class="field-label">Tag</span>
                    <div class="choice-row" style="grid-template-columns: repeat(4, 1fr);">
                        <?php foreach (POSTABLE_TAGS as $t): ?>
                            <label class="choice"><input type="radio" name="tag" value="<?= $t ?>" <?= ($f['tag'] ?? '') === $t ? 'checked' : '' ?>><span style="font-size: 0.78rem; text-align: center;"><?= e(NOTICE_TAGS[$t][0]) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="field span-2"><label for="nt_title">Title</label><input id="nt_title" name="title" maxlength="150" value="<?= e($f['title'] ?? '') ?>" required></div>
                <div class="field span-2"><label for="nt_body">Message</label><textarea id="nt_body" name="body" rows="5" required placeholder="Write it the way you'd say it to a parent: what, when, and what they need to do."><?= e($f['body'] ?? '') ?></textarea></div>
                <div class="field"><label for="nt_aud">Who should see it?</label><select id="nt_aud" name="audience"><?= opts($audOpts, $f['audience'] ?? '') ?></select></div>
                <div class="field" data-aud="grade"><label for="nt_grade">Grade level</label><select id="nt_grade" name="grade_level"><?= opts($gradeOpts, $f['grade_level'] ?? '') ?></select></div>
                <div class="field" data-aud="section"><label for="nt_sec">Section</label><select id="nt_sec" name="section_id"><?= opts($sectionOpts, $f['section_id'] ?? '') ?></select></div>
                <div class="field span-2"><label class="check"><input type="checkbox" name="requires_ack" value="1" <?= !empty($f['requires_ack']) ? 'checked' : '' ?>> Ask parents to acknowledge they have read it</label></div>
            </div>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-soft" data-close>Cancel</button><button type="submit" class="btn btn-primary">Post notice</button></div>
    </form>
</div>

<script>
(function () {
    var aud = document.getElementById('nt_aud');
    function apply() { document.querySelectorAll('[data-aud]').forEach(function (el) { el.hidden = el.getAttribute('data-aud') !== aud.value; }); }
    aud.addEventListener('change', apply); apply();
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (e) { if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault(); });
    });
})();
</script>

<?php layout_end(); ?>
