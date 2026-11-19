<?php
require __DIR__ . '/inc/bootstrap.php';

if ($me = current_user()) redirect(home_for($me));

$email = '';
if (is_post()) {
    verify_csrf();
    $email = (string)post('email', '');
    $result = attempt_login($email, (string)post('password', ''));
    if ($result['ok']) {
        $user = $result['user'];
        redirect($user['must_change_password'] ? 'change_password.php' : home_for($user));
    }
    flash('error', $result['error']);
}

auth_start('Sign in');
?>
<p class="auth-kicker">Welcome back</p>
<h1 class="auth-title">Sign in to your portal</h1>
<p class="auth-sub">Staff and families use the same sign-in.</p>

<?php auth_flashes(); ?>

<form method="post" action="login.php" novalidate>
    <?= csrf_field() ?>
    <div class="auth-field">
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" autocomplete="username" required placeholder="you@example.com" value="<?= e($email) ?>" autofocus>
    </div>
    <div class="auth-field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Enter your password">
    </div>
    <button class="auth-btn" type="submit">
        Sign in securely
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
    </button>
    <p class="auth-secure">
        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        Protected by role-based access and encrypted records
    </p>
</form>

<div class="auth-divider">New parent or guardian?</div>
<p class="auth-foot"><a href="register.php">Create a family account</a></p>
<?php auth_end();
