<?php
// Shared code for the request form and the admin page.
// Data lives outside the web folder when the hosting allows it (~/vd-data), otherwise in lib/data with web access denied.
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'vd.php') { http_response_code(404); exit; }

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
    return $c + ['password_hash' => '', 'setup_code' => '', 'tg_token' => '', 'tg_chats' => [], 'tg_link_code' => '', 'tg_offset' => 0,
                 'relay_url' => '', 'relay_secret' => '', 'mail_to' => 'vasilichdesign@yandex.ru', 'mail_mode' => 'fail'];
}
function vd_config_save(array $c): void { vd_json_write(vd_data_dir() . '/config.json', $c); }

function vd_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---------- Telegram ----------
// The hosting is in Russia, where connections to api.telegram.org are often cut. Each call goes:
//   1) straight to Telegram (short timeout; skipped for 30 min after a failure),
//   2) through the relay (a small Google Apps Script, see relay.gs), if one is set in the admin page.
function vd_http_post(string $url, $body, array $headers = [], int $timeout = 60, int $connect = 10): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => $connect,
        CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true, CURLOPT_POSTREDIR => 3]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return [$res, $err];
}

function vd_tg_direct(string $token, string $method, array $params, bool $multipart): array {
    [$res, $err] = vd_http_post("https://api.telegram.org/bot{$token}/{$method}", $multipart ? $params : http_build_query($params), [], 60, 6);
    if ($res === false) return ['ok' => false, 'net' => true, 'description' => $err ?: 'нет связи с Telegram'];
    $j = json_decode($res, true);
    return is_array($j) ? $j : ['ok' => false, 'description' => 'непонятный ответ Telegram'];
}

function vd_tg_relay(array $c, string $method, array $params): array {
    $file = null;
    foreach ($params as $k => $v) if ($v instanceof CURLFile) {
        $file = ['field' => $k, 'name' => $v->getPostFilename(), 'type' => $v->getMimeType(), 'b64' => base64_encode((string)file_get_contents($v->getFilename()))];
        unset($params[$k]);
    }
    $body = json_encode(['secret' => $c['relay_secret'], 'token' => $c['tg_token'], 'method' => $method, 'params' => $params, 'file' => $file], JSON_UNESCAPED_UNICODE);
    [$res, $err] = vd_http_post($c['relay_url'], $body, ['Content-Type: application/json'], 90, 10);
    if ($res === false) return ['ok' => false, 'net' => true, 'description' => 'реле: ' . ($err ?: 'нет связи')];
    $j = json_decode($res, true);
    return is_array($j) ? $j : ['ok' => false, 'description' => 'реле ответило не по делу (проверьте адрес и доступ «Все»)'];
}

function vd_tg(string $token, string $method, array $params = [], bool $multipart = false): array {
    $c = vd_config();
    $c['tg_token'] = $token;
    $flag = vd_data_dir() . '/tg_blocked';
    $hasRelay = $c['relay_url'] !== '' && $c['relay_secret'] !== '';
    $skipDirect = $hasRelay && is_file($flag) && filemtime($flag) > time() - 1800;
    if (!$skipDirect) {
        $r = vd_tg_direct($token, $method, $params, $multipart);
        if (empty($r['net'])) { if (is_file($flag)) @unlink($flag); return $r; }
        @touch($flag);
        if (!$hasRelay) return $r;
    }
    $r = vd_tg_relay($c, $method, $params);
    return $r;
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

// Sends a lead to every linked chat; sets $lead['tg'] (text for the admin page) and $lead['tg_ok'].
// When Telegram fails (or always, if chosen in the admin page) the lead also goes to e-mail.
function vd_notify(array &$lead): void {
    $c = vd_config();
    $ok = false; $errors = [];
    if ($c['tg_token'] && $c['tg_chats']) {
        $ok = true;
        foreach ($c['tg_chats'] as $chat) {
            $r = vd_tg($c['tg_token'], 'sendMessage', ['chat_id' => $chat['id'], 'text' => vd_lead_text($lead), 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']);
            if (empty($r['ok'])) { $ok = false; $errors[] = ($chat['name'] ?? $chat['id']) . ': ' . ($r['description'] ?? 'ошибка'); continue; }
            foreach ($lead['files'] as $f) {
                $path = vd_data_dir() . '/files/' . $lead['id'] . '/' . $f['stored'];
                $limit = $c['relay_url'] ? 20 * 1024 * 1024 : VD_TG_MAX_FILE;
                if (!is_file($path) || filesize($path) > $limit) continue;
                vd_tg($c['tg_token'], 'sendDocument', ['chat_id' => $chat['id'], 'document' => new CURLFile($path, $f['type'] ?: 'application/octet-stream', $f['name'])], true);
            }
        }
        $lead['tg'] = $ok ? 'Отправлено в Telegram' : 'Ошибка: ' . implode('; ', $errors);
    } else {
        $lead['tg'] = 'Telegram не привязан';
    }
    $lead['tg_ok'] = $ok;
    $lead['tg_tries'] = ($lead['tg_tries'] ?? 0) + 1;
    if ($c['mail_to'] && empty($lead['mailed']) && ($c['mail_mode'] === 'always' || (!$ok && $c['mail_mode'] === 'fail'))) {
        if (vd_mail_lead($c['mail_to'], $lead)) { $lead['mailed'] = true; $lead['tg'] .= ' · продублировано на почту'; }
    }
}

function vd_mail_lead(string $to, array $l): bool {
    $host = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'vasilichdesign.art');
    $subject = '=?UTF-8?B?' . base64_encode('Заявка с сайта №' . $l['num'] . ($l['name'] !== '' ? ' — ' . $l['name'] : '')) . '?=';
    $body = strip_tags(str_replace(['<b>', '</b>'], '', vd_lead_text($l, false)));
    if ($l['files']) $body .= "\n\nФайлы можно скачать в админке: https://{$host}/admin/#{$l['id']}";
    $headers = "From: =?UTF-8?B?" . base64_encode('Сайт VASILICH DESIGN') . "?= <noreply@{$host}>\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
    return @mail($to, $subject, $body, $headers, "-fnoreply@{$host}");
}

// Retries leads that did not reach Telegram (up to 30 tries). Called on new leads, admin visits and from cron.
function vd_retry_pending(int $max = 3): int {
    $c = vd_config();
    if (!$c['tg_token'] || !$c['tg_chats']) return 0;
    $lock = fopen(vd_data_dir() . '/retry.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 0;
    $done = 0;
    foreach (vd_leads() as $l) {
        if ($done >= $max) break;
        if (!empty($l['tg_ok']) || ($l['tg_tries'] ?? 0) >= 30 || $l['time'] < time() - 7 * 86400) continue;
        if (($l['tg'] ?? '') === 'Отправлено в Telegram') continue;
        vd_notify($l);
        vd_json_write(vd_data_dir() . '/leads/' . $l['id'] . '.json', $l);
        $done++;
        if (!$l['tg_ok']) break;  // still no connection: stop for now
    }
    flock($lock, LOCK_UN);
    return $done;
}

function vd_leads(): array {
    $out = [];
    foreach (glob(vd_data_dir() . '/leads/*.json') ?: [] as $p) { $l = vd_json_read($p, null); if ($l) $out[] = $l; }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}
