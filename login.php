<?php
require __DIR__ . '/auth.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (np_login_blocked()) {
        $err = 'Príliš veľa neúspešných pokusov. Skús to znova o 15 minút.';
    } elseif (try_login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''), !empty($_POST['remember']))) {
        header('Location: index.php'); exit;
    } else {
        $err = 'Nesprávne meno alebo heslo.';
    }
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
  <div class="login-lang"><select id="lang-login" class="lang-select" title="Language"></select></div>
  <div class="login-brand">
    <img src="favicon.svg" class="logo-big" alt="">
    <span class="brand-dude"><?= htmlspecialchars(cfg('APP_NAME') ?: 'NetPulse') ?></span>
    <div class="brand-sub" data-i18n="@login_sub">monitoring siete a zariadeni</div>
  </div>
  <?php if ($err): ?><div class="login-err" data-i18n="<?= htmlspecialchars($err) ?>"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <label><span data-i18n="Používateľské meno">Používateľské meno</span>
    <input name="username" required autofocus autocomplete="username">
  </label>
  <label><span data-i18n="Heslo">Heslo</span>
    <input type="password" name="password" required autocomplete="current-password">
  </label>
  <label class="login-remember"><input type="checkbox" name="remember" value="1"> <span data-i18n="Zapamätať prihlásenie">Zapamätať prihlásenie</span></label>
  <button type="submit" data-i18n="Prihlásiť sa">Prihlásiť sa</button>
  <a class="login-credit" href="https://jurajchudy.sk" target="_blank" rel="noopener"><svg class="cic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0 -18z"/></svg>jurajchudy.sk</a>
</form>
<script src="assets/i18n.js?v=<?= @filemtime(__DIR__.'/assets/i18n.js') ?>"></script>
</body></html>
