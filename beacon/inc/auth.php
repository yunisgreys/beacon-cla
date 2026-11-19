<?php

const DUMMY_PASSWORD_HASH = '$2y$10$uKpNCe3YnWc69M4BU92kYeowXzAmSCEJLfed5xSmOFpvtOEdxW/Eu';

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (PHP_SAPI === 'cli') { $_SESSION = $_SESSION ?? []; return; }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('BEACONSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (bool)config('https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function regenerate_session(): void {
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function current_user(bool $refresh = false): ?array {
    static $cache = false;
    if ($refresh) $cache = false;
    if ($cache !== false) return $cache;

    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return $cache = null;

    $t = config('session');
    $now = time();
    if ($now - ($_SESSION['last_seen'] ?? 0) > $t['idle_timeout'] || $now - ($_SESSION['login_at'] ?? 0) > $t['absolute_timeout']) {
        end_session();
        flash('error', 'Your session expired. Please log in again.');
        return $cache = null;
    }
    $_SESSION['last_seen'] = $now;

    $row = db_one(
        'SELECT u.id, u.email, u.first_name, u.last_name, u.title, u.status, u.must_change_password, u.last_seen_at, r.code AS role, r.label AS role_label
           FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?',
        [$uid]
    );
    if (!$row || $row['status'] !== 'active' || $row['role'] !== ($_SESSION['role'] ?? null)) {
        end_session();
        return $cache = null;
    }
    if (!$row['last_seen_at'] || strtotime($row['last_seen_at']) < $now - 60) {
        db_run('UPDATE users SET last_seen_at = ? WHERE id = ?', [now(), $row['id']]);
    }
    $row['name'] = $row['first_name'] . ' ' . $row['last_name'];
    $row['initials'] = strtoupper(mb_substr($row['first_name'], 0, 1) . mb_substr($row['last_name'], 0, 1));
    return $cache = $row;
}

function normalize_email(string $email): string {
    return strtolower(trim($email));
}

function login_is_throttled(string $email): bool {
    $t = config('login_throttle');
    $since = date('Y-m-d H:i:s', time() - $t['window_minutes'] * 60);
    $byEmail = (int)db_value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND succeeded = 0 AND attempted_at >= ?', [$email, $since]);
    $byIp    = (int)db_value('SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND succeeded = 0 AND attempted_at >= ?', [client_ip(), $since]);
    return $byEmail >= $t['max_per_email'] || $byIp >= $t['max_per_ip'];
}

function attempt_login(string $email, string $password): array {
    $email = normalize_email($email);
    $generic = 'Incorrect e-mail or password.';

    if ($email === '' || $password === '') {
        return ['ok' => false, 'error' => $generic];
    }
    if (login_is_throttled($email)) {
        $mins = config('login_throttle')['window_minutes'];
        return ['ok' => false, 'error' => "Too many failed attempts. Please wait $mins minutes and try again."];
    }

    $user = db_one(
        'SELECT u.id, u.password_hash, u.status, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ?',
        [$email]
    );
    $valid = password_verify($password, $user['password_hash'] ?? DUMMY_PASSWORD_HASH) && $user !== null;

    db_run('INSERT INTO login_attempts (email, ip_address, succeeded, attempted_at) VALUES (?, ?, ?, ?)',
        [$email, client_ip(), $valid ? 1 : 0, now()]);

    if (!$valid) {
        audit('login_failed', 'users', $user['id'] ?? null, null, [], ['id' => null, 'role' => null]);
        return ['ok' => false, 'error' => $generic];
    }
    if ($user['status'] !== 'active') {
        audit('login_blocked_disabled', 'users', $user['id'], null, [], ['id' => $user['id'], 'role' => $user['role']]);
        return ['ok' => false, 'error' => 'This account has been disabled. Please contact the school administrator.'];
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    db_run('UPDATE users SET last_login_at = ?, last_seen_at = ? WHERE id = ?', [now(), now(), $user['id']]);

    regenerate_session();
    $_SESSION['uid']       = (int)$user['id'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['login_at']  = time();
    $_SESSION['last_seen'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $me = current_user(true);
    audit('login', 'users', $me['id'], null, [], $me);
    return ['ok' => true, 'user' => $me];
}

function end_session(): void {
    $_SESSION = [];
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        session_destroy();
        session_start();
        session_regenerate_id(true);
    }
}

function logout(): void {
    if ($me = current_user()) {
        audit('logout', 'users', $me['id'], null, [], $me);
        db_run('UPDATE users SET last_seen_at = NULL WHERE id = ?', [$me['id']]);
    }
    end_session();
}

function password_problem(string $password, string $email = ''): ?string {
    if (strlen($password) < 10)                      return 'Use at least 10 characters.';
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password))
                                                     return 'Use a mix of letters and numbers.';
    if ($email !== '' && stripos($password, strstr($email, '@', true) ?: $email) !== false)
                                                     return 'Your password should not contain your e-mail name.';
    return null;
}

function change_password(array $user, string $current, string $new, string $confirm): ?string {
    $hash = db_value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
    if (!password_verify($current, (string)$hash)) return 'Your current password is incorrect.';
    if ($new !== $confirm)                          return 'The new passwords do not match.';
    if (password_verify($new, (string)$hash))       return 'Choose a password different from your current one.';
    if ($err = password_problem($new, $user['email'])) return $err;

    db_run('UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = ? WHERE id = ?',
        [password_hash($new, PASSWORD_DEFAULT), now(), $user['id']]);
    regenerate_session();
    audit('password_change', 'users', $user['id'], null, ['password_hash'], $user);
    current_user(true);
    return null;
}

function request_student_link(int $parentId, string $studentNo, string $birthdate, string $relationship): ?string {
    $student = db_one('SELECT id FROM students WHERE student_no = ? AND birthdate = ? AND status = ?',
        [strtoupper(trim($studentNo)), $birthdate, 'enrolled']);
    if (!$student) {
        return "We couldn't match that Student ID and birthdate. Please check them, or ask your child's adviser.";
    }
    $existing = db_one('SELECT status FROM parent_student_links WHERE parent_id = ? AND student_id = ?', [$parentId, $student['id']]);
    if ($existing) {
        return $existing['status'] === 'rejected' || $existing['status'] === 'revoked'
            ? 'This link was not approved. Please contact the school office.'
            : 'You have already requested access to this student.';
    }
    db_run('INSERT INTO parent_student_links (parent_id, student_id, relationship, status, created_at) VALUES (?, ?, ?, ?, ?)',
        [$parentId, $student['id'], $relationship, 'pending', now()]);
    audit('link_request', 'parent_student_links', db()->lastInsertId(), (int)$student['id'], [],
        ['id' => $parentId, 'role' => 'parent']);
    return null;
}

function register_parent(array $in): ?string {
    $email = normalize_email($in['email'] ?? '');
    $first = trim($in['first_name'] ?? '');
    $last  = trim($in['last_name'] ?? '');
    $rel   = trim($in['relationship'] ?? '');
    $pw    = (string)($in['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Please enter a valid e-mail address.';
    if ($first === '' || $last === '')              return 'Please enter your first and last name.';
    if (!in_array($rel, ['Mother', 'Father', 'Guardian', 'Grandparent', 'Other'], true)) return 'Please choose your relationship to the student.';
    if ($pw !== ($in['password_confirm'] ?? ''))    return 'The passwords do not match.';
    if ($err = password_problem($pw, $email))       return $err;
    $d = DateTime::createFromFormat('Y-m-d', $in['birthdate'] ?? '');
    if (!$d || $d->format('Y-m-d') !== $in['birthdate']) return "Please enter your child's birthdate.";
    if (db_value('SELECT 1 FROM users WHERE email = ?', [$email])) {
        return 'An account with this e-mail already exists. Log in to link another child.';
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('INSERT INTO users (role_id, email, password_hash, first_name, last_name, phone, created_at, updated_at)
                VALUES ((SELECT id FROM roles WHERE code = ?), ?, ?, ?, ?, ?, ?, ?)',
            ['parent', $email, password_hash($pw, PASSWORD_DEFAULT), $first, $last, trim($in['phone'] ?? '') ?: null, now(), now()]);
        $parentId = (int)$pdo->lastInsertId();
        audit('parent_register', 'users', $parentId, null, [], ['id' => $parentId, 'role' => 'parent']);

        if ($err = request_student_link($parentId, (string)($in['student_no'] ?? ''), $in['birthdate'], $rel)) {
            $pdo->rollBack();
            return $err;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return null;
}
