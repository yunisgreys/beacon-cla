<?php

function auth_start(string $pageTitle, bool $wide = false): void {
    $school = config('school_name') ?? 'Christ Life Academy';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> Â· BEACON</title>
    <link rel="icon" href="assets/img/tab-logo.svg">
    <link rel="stylesheet" href="./auth.css">
</head>
<body class="auth-page">
<div class="auth-split">
    <aside class="auth-brand" aria-label="About BEACON">
        <div class="auth-brand-inner">
            <p class="auth-eyebrow"><?= e($school) ?></p>
            <img class="auth-wordmark" src="assets/img/logo-wordmark-white-shadow.svg" alt="BEACON">
            <p class="auth-tagline">Every learner, known<br>and cared for.</p>
            <ul class="auth-chips">
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6l7-3z"/></svg>
                    Secure &amp; confidential
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg>
                    Student-centered
                </li>
            </ul>
        </div>
        <p class="auth-brand-foot"><?= e(config('system_tagline') ?? 'SVLC Student Welfare & Care System') ?></p>
    </aside>

    <main class="auth-panel">
        <div class="auth-form<?= $wide ? ' wide' : '' ?>">
            <img class="auth-logo-mobile" src="assets/img/logo-wordmark.svg" alt="BEACON">
            <span class="auth-pill"><span class="dot" aria-hidden="true"></span>Academic Year <?= e(str_replace('-', 'â€“', config('school_year') ?? '2026-2027')) ?> Active</span>
<?php
}

function auth_end(): void {
?>
        </div>
    </main>
</div>
<script src="assets/js/beacon.js"></script>
</body>
</html>
<?php
}

function auth_flashes(): void {
    foreach (flash_pull() as $f) {
        echo '<div class="auth-alert ' . e($f['type']) . '" role="alert">' . e($f['msg']) . "</div>\n";
    }
}
