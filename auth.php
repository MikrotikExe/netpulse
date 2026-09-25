<?php
/** Session autentifikácia + role (administrator > admin > user). */
require_once __DIR__ . '/db.php';

const REMEMBER_SECONDS = 60 * 60 * 24 * 30; // „Zapamätať prihlásenie" = 30 dní
const NP_MIN_PASSWORD   = 8;                 // minimálna dĺžka nového hesla
const NP_LOGIN_MAX_FAILS = 10;               // toľko neúspešných pokusov z jednej IP…
const NP_LOGIN_WINDOW    = 900;              // …za 15 minút => dočasná blokácia

function np_password_ok(string $p): bool {
    return (function_exists('mb_strlen') ? mb_strlen($p, 'UTF-8') : strlen($p)) >= NP_MIN_PASSWORD;
}
function np_client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '?'), 0, 64); }

/** Je IP dočasne zablokovaná po opakovaných neúspešných prihláseniach? */
function np_login_blocked(): bool {
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM login_fail WHERE ip=? AND ts>?');
        $st->execute([np_client_ip(), time() - NP_LOGIN_WINDOW]);
        return (int)$st->fetchColumn() >= NP_LOGIN_MAX_FAILS;
    } catch (Throwable $e) { return false; }
}
function np_login_fail_record(): void {
    try {
        $pdo = db();
        $pdo->prepare('INSERT INTO login_fail(ip,ts) VALUES(?,?)')->execute([np_client_ip(), time()]);
        $pdo->prepare('DELETE FROM login_fail WHERE ts<?')->execute([time() - NP_LOGIN_WINDOW]);
    } catch (Throwable $e) {}
}
function np_login_fail_clear(): void {
    try { db()->prepare('DELETE FROM login_fail WHERE ip=?')->execute([np_client_ip()]); } catch (Throwable $e) {}
}
/** Má prihlásený používateľ stále predvolené heslo „admin"? (upozornenie v aplikácii) */
function np_default_password_active(): bool {
    try {
        $st = db()->prepare('SELECT pass_hash FROM users WHERE id=?'); $st->execute([current_uid()]);
        $h = $st->fetchColumn();
        return $h && password_verify('admin', (string)$h);
    } catch (Throwable $e) { return false; }
}

function np_is_secure(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

if (session_status() === PHP_SESSION_NONE) {
    $sdir = __DIR__ . '/data/sessions';
    if (!is_dir($sdir)) @mkdir($sdir, 0770, true);
    if (is_dir($sdir) && is_writable($sdir)) session_save_path($sdir);
    // ak si používateľ zvolil „zapamätať", session prežije zatvorenie prehliadača
    $remember = (($_COOKIE['np_remember'] ?? '') === '1');
    $life = $remember ? REMEMBER_SECONDS : 0;
    if ($remember) { @ini_set('session.gc_maxlifetime', (string) REMEMBER_SECONDS); }
    session_set_cookie_params([
        'lifetime' => $life, 'path' => '/', 'domain' => '',
        'secure' => np_is_secure(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

function ensure_users_table(): void {
    $pdo = db();
    try { migrate(); } catch (Throwable $e) {}   // tabuľky ako login_fail musia existovať už pri prihlásení
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
        $min = $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();   // bez poddotazu – MySQL 8 by hlásil chybu 1093
        if ($min !== false && $min !== null) $pdo->prepare("UPDATE users SET role='administrator' WHERE id=?")->execute([$min]);
    }
}

function try_login(string $u, string $p, bool $remember = false): bool {
    ensure_users_table();
    if (np_login_blocked()) return false;
    $st = db()->prepare('SELECT id,username,pass_hash,role FROM users WHERE username=?');
    $st->execute([$u]);
    $row = $st->fetch();
    if ($row && password_verify($p, $row['pass_hash'])) {
        session_regenerate_id(true); // proti session fixation
        $_SESSION['uid'] = $row['id'];
        $_SESSION['user'] = $row['username'];
        $_SESSION['role'] = $row['role'] ?: 'user';
        np_login_fail_clear();
        $opt = fn(int $exp) => ['expires'=>$exp, 'path'=>'/', 'domain'=>'', 'secure'=>np_is_secure(),
                                'httponly'=>true, 'samesite'=>'Lax'];
        if ($remember) {
            // zapamätaj voľbu aj predĺž platnosť session cookie
            setcookie('np_remember', '1', $opt(time() + REMEMBER_SECONDS));
            setcookie(session_name(), session_id(), $opt(time() + REMEMBER_SECONDS));
        } else {
            setcookie('np_remember', '', $opt(time() - 3600));
        }
        return true;
    }
    np_login_fail_record();
    usleep(400000);   // spomaľ hádanie hesla
    return false;
}

function logout(): void {
    $_SESSION = [];
    $opt = ['expires'=>time() - 3600, 'path'=>'/', 'domain'=>'', 'secure'=>np_is_secure(), 'httponly'=>true, 'samesite'=>'Lax'];
    setcookie('np_remember', '', $opt);
    if (ini_get('session.use_cookies')) setcookie(session_name(), '', $opt);
    session_destroy();
}
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
