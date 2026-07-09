<?php
/** Session autentifikácia + role (administrator > admin > user). */
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    $sdir = __DIR__ . '/data/sessions';
    if (!is_dir($sdir)) @mkdir($sdir, 0770, true);
    if (is_dir($sdir) && is_writable($sdir)) session_save_path($sdir);
    session_start();
}

function ensure_users_table(): void {
    $pdo = db();
    if (cfg('DB_DRIVER') === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users(id BIGINT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(64) UNIQUE, pass_hash VARCHAR(255), role VARCHAR(16) DEFAULT 'user',
            created DATETIME) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE, pass_hash TEXT, role TEXT DEFAULT 'user', created TEXT)");
    }
    // migrácia: doplň role ak chýba
    try { $pdo->exec("ALTER TABLE users ADD COLUMN role " . (cfg('DB_DRIVER')==='mysql'?"VARCHAR(16) DEFAULT 'user'":"TEXT DEFAULT 'user'")); } catch (Throwable $e) {}
    // default admin
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO users(username,pass_hash,role,created) VALUES(?,?,?,?)')
            ->execute(['admin', password_hash('admin', PASSWORD_DEFAULT), 'administrator', date('Y-m-d H:i:s')]);
    }
    // zaruč aspoň jedného administrátora
    if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='administrator'")->fetchColumn() === 0) {
        $pdo->exec("UPDATE users SET role='administrator' WHERE id=(SELECT MIN(id) FROM users)");
    }
}

function try_login(string $u, string $p): bool {
    ensure_users_table();
    $st = db()->prepare('SELECT id,username,pass_hash,role FROM users WHERE username=?');
    $st->execute([$u]);
    $row = $st->fetch();
    if ($row && password_verify($p, $row['pass_hash'])) {
        $_SESSION['uid'] = $row['id'];
        $_SESSION['user'] = $row['username'];
        $_SESSION['role'] = $row['role'] ?: 'user';
        return true;
    }
    return false;
}

function logout(): void { $_SESSION = []; session_destroy(); }
function current_user(): ?string { return $_SESSION['user'] ?? null; }
function current_uid(): ?int { return $_SESSION['uid'] ?? null; }
function current_role(): string { return $_SESSION['role'] ?? 'user'; }
function role_rank(?string $r): int { return ['user'=>1,'admin'=>2,'administrator'=>3][$r] ?? 1; }
function has_role(string $min): bool { return role_rank(current_role()) >= role_rank($min); }

function require_login(bool $api = false): void {
    ensure_users_table();
    if (empty($_SESSION['uid'])) {
        if ($api) { http_response_code(401); echo json_encode(['error' => 'neprihlásený']); exit; }
        header('Location: login.php'); exit;
    }
}
function require_role(string $min): void {
    if (!has_role($min)) { http_response_code(403); echo json_encode(['error' => 'nedostatočné oprávnenie']); exit; }
}
