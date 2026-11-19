<?php
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();
$forced = (bool)$user['must_change_password'];

if (is_post()) {
    verify_csrf();
    $error = change_password($user, (string)post('current', ''), (string)post('new', ''), (string)post('confirm', ''));
    if ($error === null) {
        flash('success', 'Your password has been updated.');
        redirect(home_for($user));
    }
    flash('error', $error);
}

auth_start('Change password');
?>
<p class="auth-kicker"><?= $forced ? 'One more step' : 'Account' ?></p>
<h1 class="auth-title"><?= $forced ? 'Set your own password' : 'Change password' ?></h1>
<p class="auth-sub"><?= $forced
    ? 'You signed in with a temporary password. Choose a new one to continue.'
    : "Choose a strong password you don't use anywhere else." ?></p>

<?php auth_flashes(); ?>

<form method="post" action="change_password.php" novalidate data-confirm="Your password will be changed right away. Use the new one the next time you sign in." data-confirm-title="Change your password?" data-confirm-ok="Change password">
    <?= csrf_field() ?>
    <div class="auth-field">
        <label for="current"><?= $forced ? 'Temporary password' : 'Current password' ?></label>
        <input id="current" name="current" type="password" autocomplete="current-password" required>
    </div>
    <div class="auth-field">
        <label for="new">New password</label>
        <input id="new" name="new" type="password" autocomplete="new-password" required minlength="10">
        <span class="hint">At least 10 characters, with letters and numbers.</span>
    </div>
    <div class="auth-field">
        <label for="confirm">Confirm new password</label>
        <input id="confirm" name="confirm" type="password" autocomplete="new-password" required>
    </div>
    <button class="auth-btn" type="submit">Save password</button>
</form>

<div class="auth-divider"></div>
<?php if (!$forced): ?>
    <p class="auth-foot"><a href="<?= e(home_for($user)) ?>">Back to dashboard</a></p>
<?php else: ?>
    <form method="post" action="logout.php" class="auth-foot" data-confirm="You will need to sign in again to keep using BEACON." data-confirm-title="Log out?" data-confirm-ok="Log out">
        <?= csrf_field() ?>
        <button type="submit">Log out</button>
    </form>
<?php endif;
auth_end();
