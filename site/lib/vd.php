<?php
// Shared code for the request form and the admin page.
// Data lives outside the web folder when the hosting allows it (~/vd-data), otherwise in lib/data with web access denied.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'vd.php') { http_response_code(404); exit; }

date_default_timezone_set('Europe/Moscow');
const VD_MAX_FILES = 10;
const VD_MAX_BYTES = 50 * 1024 * 1024;           // all attachments of one request together
const VD_TG_MAX_FILE = 49 * 1024 * 1024;          // Telegram bots can send files up to 50 MB

function vd_data_dir(): string {
    static $dir = null;
    if ($dir) return $dir;
    $home = dirname(__DIR__, 3);
    $cands = [$home . '/vd-data', __DIR__ . '/data'];
    foreach ($cands as $c) {
        if (is_dir($c) || @mkdir($c, 0700, true)) {
            if (is_writable($c)) { $dir = $c; break; }
        }
    }
    if (!$dir) { http_response_code(500); exit('Нет папки для данных'); }
    if ($dir === __DIR__ . '/data' && !file_exists($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    foreach (['leads', 'files'] as $sub) if (!is_dir("$dir/$sub")) mkdir("$dir/$sub", 0700, true);
    return $dir;
}

function vd_json_read(string $path, $default = []) {
    if (!is_file($path)) return $default;
    $v = json_decode((string)file_get_contents($path), true);
    return is_array($v) ? $v : $default;
}
function vd_json_write(string $path, $data): void {
    $tmp = $path . '.tmp' . bin2hex(random_bytes(3));
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, $path);
}

function vd_config(): array {
    $c = vd_json_read(vd_data_dir() . '/config.json', []);
    return $c + ['password_hash' => '', 'setup_code' => '', 'tg_token' => '', 'tg_chats' => [], 'tg_link_code' => '', 'tg_offset' => 0];
}
function vd_config_save(array $c): void { vd_json_write(vd_data_dir() . '/config.json', $c); }

function vd_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---------- Telegram ----------
function vd_tg(string $token, string $method, array $params = [], bool $multipart = false): array {
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS => $multipart ? $params : http_build_query($params)]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) return ['ok' => false, 'description' => $err ?: 'нет связи с Telegram'];
    } else {
        if ($multipart) return ['ok' => false, 'description' => 'curl недоступен'];
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($params), 'timeout' => 30, 'ignore_errors' => true]]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) return ['ok' => false, 'description' => 'нет связи с Telegram'];
    }
    $j = json_decode($res, true);
    return is_array($j) ? $j : ['ok' => false, 'description' => 'непонятный ответ Telegram'];
}

function vd_lead_text(array $l, bool $html = true): string {
    $e = $html ? 'vd_h' : fn($s) => (string)$s;
    $t = "🟠 <b>Новая заявка с сайта</b>\n\n";
    if ($l['name'] !== '') $t .= "<b>Имя:</b> " . $e($l['name']) . "\n";
    $t .= "<b>Контакт:</b> " . $e($l['contact']) . "\n";
    if ($l['task'] !== '') $t .= "\n<b>Задача:</b>\n" . $e(mb_substr($l['task'], 0, 3000)) . "\n";
    if ($l['files']) $t .= "\n📎 Файлов: " . count($l['files']);
    $t .= "\n\n<i>" . $e(date('d.m.Y H:i', $l['time'])) . " · №" . $e($l['num']) . "</i>";
    return $t;
}

// Sends a lead to every linked chat. Returns a short status for the admin page.
function vd_notify(array $lead): string {
    $c = vd_config();
    if (!$c['tg_token'] || !$c['tg_chats']) return 'Telegram не привязан';
    $errors = [];
    foreach ($c['tg_chats'] as $chat) {
        $r = vd_tg($c['tg_token'], 'sendMessage', ['chat_id' => $chat['id'], 'text' => vd_lead_text($lead), 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']);
        if (empty($r['ok'])) { $errors[] = ($chat['name'] ?? $chat['id']) . ': ' . ($r['description'] ?? 'ошибка'); continue; }
        foreach ($lead['files'] as $f) {
            $path = vd_data_dir() . '/files/' . $lead['id'] . '/' . $f['stored'];
            if (!is_file($path) || filesize($path) > VD_TG_MAX_FILE || !class_exists('CURLFile')) continue;
            vd_tg($c['tg_token'], 'sendDocument', ['chat_id' => $chat['id'], 'document' => new CURLFile($path, $f['type'] ?: 'application/octet-stream', $f['name'])], true);
        }
    }
    return $errors ? 'Ошибка: ' . implode('; ', $errors) : 'Отправлено в Telegram';
}

function vd_leads(): array {
    $out = [];
    foreach (glob(vd_data_dir() . '/leads/*.json') ?: [] as $p) { $l = vd_json_read($p, null); if ($l) $out[] = $l; }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}
