<?php
require __DIR__ . '/auth.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (try_login($_POST['username'] ?? '', $_POST['password'] ?? '')) {
        header('Location: index.php'); exit;
    }
    $err = 'Nesprávne meno alebo heslo.';
}
if (current_user()) { header('Location: index.php'); exit; }
?>
<!doctype html>
<html lang="sk"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(cfg('APP_NAME') ?: 'NetPulse') ?> – prihlásenie</title>
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<script>try{document.documentElement.dataset.theme=localStorage.getItem('np-theme')||'auto';}catch(e){document.documentElement.dataset.theme='auto';}</script>
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
</head>
<body class="login-body">
<form class="login-card" method="post" autocomplete="off">
  <div class="login-brand">
    <img src="favicon.svg" class="logo-big" alt="">
    <span class="brand-dude"><?= htmlspecialchars(cfg('APP_NAME') ?: 'NetPulse') ?></span>
    <div class="brand-sub">monitoring siete a zariadení</div>
  </div>
  <?php if ($err): ?><div class="login-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <label>Používateľské meno
    <input name="username" required autofocus>
  </label>
  <label>Heslo
    <input type="password" name="password" required>
  </label>
  <button type="submit">Prihlásiť sa</button>
  <div class="login-hint">Predvolené: <b>admin</b> / <b>admin</b> — po prihlásení zmeň v Nastaveniach.</div>
</form>
</body></html>
