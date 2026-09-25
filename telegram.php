<?php
/** Telegram notifikácie – natívne cez Telegram Bot API (bez wget).
 *
 *  Doručovanie je oddelené od monitoringu: monitor pri zmene stavu len vloží správu
 *  do fronty (notify_queue) v tej istej transakcii ako udalosť a výpadok. Fronta sa
 *  potom odošle – a keď Telegram nefunguje, správy počkajú a doručia sa neskôr,
 *  bez toho aby sa duplikovali udalosti alebo stratil výpadok.
 */
require_once __DIR__ . '/db.php';

function tg_send(string $token, string $chat, string $text): array {
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $post = ['chat_id'=>$chat, 'text'=>$text, 'disable_web_page_preview'=>'1'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$post,
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>6,
            CURLOPT_SSL_VERIFYPEER=>true]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        $ok = ($code === 200 && strpos((string)$r, '"ok":true') !== false);
        return ['ok'=>$ok, 'resp'=>$r, 'err'=>$err ?: null, 'code'=>$code];
    }
    $ctx = stream_context_create(['http'=>['method'=>'POST',
        'header'=>'Content-Type: application/x-www-form-urlencoded',
        'content'=>http_build_query($post), 'timeout'=>6, 'ignore_errors'=>true]]);
    $r = @file_get_contents($url, false, $ctx);
    return ['ok'=>($r !== false && strpos($r, '"ok":true') !== false), 'resp'=>$r];
}

function tg_enabled(): bool {
    return setting_get('tg_enabled','0') === '1'
        && trim((string)setting_get('tg_token','')) !== ''
        && trim((string)setting_get('tg_chat','')) !== '';
}

/** Text správy o zmene stavu (formát, na ktorý sú používatelia zvyknutí). */
function tg_format_status(string $name, ?string $ip, string $status, string $ts): string {
    $sk = $status === 'up' ? 'funkčné ✅' : ($status === 'down' ? 'nefunkčné ❌' : $status);
    $icon = $status === 'up' ? '🟢' : '🔴';
    return "$icon Čas: $ts; Zariadenie: $name IP:" . ($ip ?: '-') . "; je: $sk";
}

/** Vloží správu do fronty. Volaj VNÚTRI transakcie so zápisom udalosti.
 *  Ak ešte čaká neodoslané „nefunkčné" toho istého zariadenia a prichádza „funkčné",
 *  zlúčia sa do jednej správy (po výpadku Telegramu nepríde záplava). */
function tg_enqueue(PDO $pdo, int $deviceId, string $name, ?string $ip, string $status, string $ts, int $nowTs): void {
    if (!tg_enabled()) return;
    if ($status === 'up') {
        $st = $pdo->prepare("SELECT id,text FROM notify_queue WHERE device_id=? AND status='down' ORDER BY id DESC LIMIT 1");
        $st->execute([$deviceId]); $pend = $st->fetch(); $st->closeCursor();
        if ($pend && preg_match('/Čas: ([^;]+);/u', (string)$pend['text'], $m)) {
            $pdo->prepare('DELETE FROM notify_queue WHERE id=?')->execute([$pend['id']]);
            $text = "🟠 Zariadenie: $name IP:" . ($ip ?: '-') . "; bolo nefunkčné od {$m[1]} do $ts ✅";
            $pdo->prepare('INSERT INTO notify_queue(device_id,status,text,created,attempts,next_try) VALUES(?,?,?,?,0,?)')
                ->execute([$deviceId, 'updown', $text, $nowTs, $nowTs]);
            return;
        }
    }
    $pdo->prepare('INSERT INTO notify_queue(device_id,status,text,created,attempts,next_try) VALUES(?,?,?,?,0,?)')
        ->execute([$deviceId, $status, tg_format_status($name, $ip, $status, $ts), $nowTs, $nowTs]);
}

/** Odošle čakajúce správy. Pri prvom zlyhaní skončí (Telegram je zrejme nedostupný)
 *  a ďalší pokus naplánuje s narastajúcim odstupom – cyklus monitora tak nečaká
 *  na desiatky timeoutov. Vráti [odoslané, zlyhané, čakajúce]. */
function tg_flush(PDO $pdo, int $max = 8, float $budget = 12.0): array {
    $now = time();
    if (!tg_enabled()) {   // vypnutý Telegram: staré správy neposielaj po opätovnom zapnutí
        db_retry(fn() => $pdo->exec('DELETE FROM notify_queue'));
        return [0, 0, 0];
    }
    // správy staršie ako 24 h už nemajú zmysel
    db_retry(function() use ($pdo, $now) {
        $st = $pdo->prepare('DELETE FROM notify_queue WHERE created < ?'); $st->execute([$now - 86400]);
        if ($st->rowCount()) error_log('NetPulse Telegram: '.$st->rowCount().' správ starších ako 24 h zahodených');
        return true;
    });
    // Istič: po zlyhaní sa Telegram nekontaktuje, kým neuplynie odstup – cyklus monitora
    // tak pri výpadku internetu nečaká v každom kole na timeout.
    $blockUntil = (int) setting_get('tg_block_until', '0');
    if ($now < $blockUntil) {
        $left = (int) (db_retry(fn() => $pdo->query('SELECT COUNT(*) FROM notify_queue')->fetchColumn()) ?? 0);
        return [0, 0, $left];
    }
    $token = trim((string)setting_get('tg_token','')); $chat = trim((string)setting_get('tg_chat',''));
    $rows = db_retry(function() use ($pdo, $now, $max) {
        $st = $pdo->prepare("SELECT id,text,attempts FROM notify_queue WHERE next_try <= ? ORDER BY id LIMIT $max");
        $st->execute([$now]); return $st->fetchAll();
    }) ?: [];
    $sent = 0; $failed = 0; $deadline = microtime(true) + $budget;
    foreach ($rows as $r) {
        if (microtime(true) > $deadline) break;
        $res = @tg_send($token, $chat, (string)$r['text']);
        if (!empty($res['ok'])) {
            $sent++;
            if ((int) setting_get('tg_fail_streak', '0') > 0) { try { setting_set('tg_fail_streak', '0'); } catch (Throwable $e) {} }
            db_retry(fn() => $pdo->prepare('DELETE FROM notify_queue WHERE id=?')->execute([$r['id']]));
            continue;
        }
        $failed++;
        $att = (int)$r['attempts'] + 1;
        $delay = min(900, 30 * (2 ** min($att - 1, 5)));   // 30 s, 60, 120 … max 15 min
        // 429 Too Many Requests: Telegram povie, koľko počkať
        if ((int)($res['code'] ?? 0) === 429 && preg_match('/"retry_after"\s*:\s*(\d+)/', (string)($res['resp'] ?? ''), $mm)) {
            $delay = max(1, min(3600, (int)$mm[1] + 1));
        }
        $streak = (int) setting_get('tg_fail_streak', '0') + 1;
        $gdelay = (int)($res['code'] ?? 0) === 429 ? $delay : min(900, 30 * (2 ** min($streak - 1, 5)));
        try { setting_set('tg_fail_streak', (string)$streak); setting_set('tg_block_until', (string)(time() + $gdelay)); } catch (Throwable $e) {}
        db_retry(fn() => $pdo->prepare('UPDATE notify_queue SET attempts=?, next_try=? WHERE id=?')
                              ->execute([$att, time() + $delay, $r['id']]));
        error_log('NetPulse Telegram: odoslanie zlyhalo (pokus '.$att.', ďalší o '.$delay.' s)'
                  . (isset($res['code']) ? ' HTTP '.$res['code'] : ''));
        break;   // Telegram je nedostupný – ostatné nechaj na ďalší cyklus
    }
    $left = (int) (db_retry(fn() => $pdo->query('SELECT COUNT(*) FROM notify_queue')->fetchColumn()) ?? 0);
    return [$sent, $failed, $left];
}
