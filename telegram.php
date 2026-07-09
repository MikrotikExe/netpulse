<?php
/** Telegram notifikácie – natívne cez Telegram Bot API (bez wget). */
require_once __DIR__ . '/db.php';

function tg_send(string $token, string $chat, string $text): array {
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $post = ['chat_id'=>$chat, 'text'=>$text, 'disable_web_page_preview'=>'1'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$post,
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8, CURLOPT_SSL_VERIFYPEER=>true]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        $ok = ($code === 200 && strpos((string)$r, '"ok":true') !== false);
        return ['ok'=>$ok, 'resp'=>$r, 'err'=>$err ?: null, 'code'=>$code];
    }
    $ctx = stream_context_create(['http'=>['method'=>'POST',
        'header'=>'Content-Type: application/x-www-form-urlencoded',
        'content'=>http_build_query($post), 'timeout'=>8]]);
    $r = @file_get_contents($url, false, $ctx);
    return ['ok'=>($r !== false && strpos($r, '"ok":true') !== false), 'resp'=>$r];
}

/** Pošle notifikáciu o zmene stavu zariadenia (ak je Telegram zapnutý). */
function tg_notify_status(string $name, ?string $ip, string $status, string $ts): void {
    if (setting_get('tg_enabled','0') !== '1') return;
    $token = trim((string)setting_get('tg_token','')); $chat = trim((string)setting_get('tg_chat',''));
    if ($token === '' || $chat === '') return;
    $sk = $status === 'up' ? 'funkčné ✅' : ($status === 'down' ? 'nefunkčné ❌' : $status);
    $icon = $status === 'up' ? '🟢' : '🔴';
    $text = "$icon Čas: $ts; Zariadenie: $name IP:" . ($ip ?: '-') . "; je: $sk";
    @tg_send($token, $chat, $text);
}
