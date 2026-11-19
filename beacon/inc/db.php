<?php

function config(?string $key = null) {
    static $cfg;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('BEACON is not configured yet: copy config/config.example.php to config/config.php.');
        }
        $cfg = require $file;
    }
    return $key === null ? $cfg : ($cfg[$key] ?? null);
}

function db(): PDO {
    static $pdo;
    if ($pdo === null) {
        $c = config('db');
        $driver = strtok($c['dsn'], ':');
        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            setup_error("This PHP doesn't have the <b>pdo_$driver</b> extension enabled.",
                'Open the php.ini shown below, remove the <code>;</code> in front of <code>extension=pdo_' . $driver . '</code>, save, and restart PHP. '
                . 'Or run the site with XAMPP\'s own PHP, which already has it on.<br><br>php.ini in use: <code>' . (php_ini_loaded_file() ?: 'none loaded') . '</code><br>PHP binary: <code>' . PHP_BINARY . '</code>');
        }
        try {
            $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            setup_error("BEACON can't connect to the database.",
                'Check that MySQL is running in the XAMPP Control Panel, that you imported <code>database/schema.sql</code>, '
                . 'and that the settings in <code>config/config.php</code> are right.<br><br>Details: <code>' . htmlspecialchars($e->getMessage()) . '</code>');
        }
        if (str_starts_with($c['dsn'], 'sqlite:')) {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
    return $pdo;
}

function db_run(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_one(string $sql, array $params = []): ?array {
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array {
    return db_run($sql, $params)->fetchAll();
}

function db_value(string $sql, array $params = []) {
    $v = db_run($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function setup_error(string $title, string $help): void {
    http_response_code(500);
    if (PHP_SAPI === 'cli') { fwrite(STDERR, strip_tags("$title\n$help\n")); exit(1); }
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Setup needed · BEACON</title>'
       . '<div style="font-family:system-ui,sans-serif;max-width:640px;margin:80px auto;padding:28px 32px;border:1px solid #e2e6ef;border-radius:16px;line-height:1.6;color:#0f172a">'
       . '<p style="margin:0 0 6px;font-size:.75rem;font-weight:800;letter-spacing:.12em;color:#2C2AC4">SETUP NEEDED</p>'
       . "<h1 style=\"font-size:1.3rem;margin:0 0 12px\">$title</h1><p style=\"margin:0;color:#475569;font-size:.92rem\">$help</p></div>";
    exit;
}

function now(): string { return date('Y-m-d H:i:s'); }
