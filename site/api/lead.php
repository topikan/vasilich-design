<?php
// Receives the contact form: saves the request with its files, then sends it to the linked Telegram chats.
require __DIR__ . '/../lib/vd.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(int $code, array $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok' => false, 'error' => 'method']);
// a request bigger than post_max_size arrives with empty $_POST
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) out(413, ['ok' => false, 'error' => 'too_big']);

// spam: hidden field must stay empty, the form must have been open for at least 2 seconds
if (trim($_POST['website'] ?? '') !== '') out(200, ['ok' => true]);
$opened = (int)($_POST['t'] ?? 0);
if ($opened && (time() * 1000 - $opened) < 2000) out(200, ['ok' => true]);

$name = trim(mb_substr((string)($_POST['name'] ?? ''), 0, 200));
$contact = trim(mb_substr((string)($_POST['contact'] ?? ''), 0, 300));
$task = trim(mb_substr((string)($_POST['task'] ?? ''), 0, 10000));
if ($contact === '') out(422, ['ok' => false, 'error' => 'contact']);

// at most 5 requests per IP in 10 minutes
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$rlPath = vd_data_dir() . '/ratelimit.json';
$rl = vd_json_read($rlPath, []);
$now = time();
foreach ($rl as $k => $ts) { $rl[$k] = array_values(array_filter($ts, fn($t) => $t > $now - 600)); if (!$rl[$k]) unset($rl[$k]); }
$key = hash('sha256', $ip);
if (count($rl[$key] ?? []) >= 5) out(429, ['ok' => false, 'error' => 'rate']);
$rl[$key][] = $now;
vd_json_write($rlPath, $rl);

$id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
$leadFiles = [];
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    $dir = vd_data_dir() . '/files/' . $id;
    $total = 0;
    foreach ($_FILES['files']['name'] as $i => $orig) {
        if (count($leadFiles) >= VD_MAX_FILES) break;
        if (($_FILES['files']['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
        $size = (int)$_FILES['files']['size'][$i];
        if ($total + $size > VD_MAX_BYTES) break;
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $clean = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename((string)$orig)) ?: 'file';
        $clean = mb_substr($clean, -120);
        $stored = (count($leadFiles) + 1) . '_' . $clean;
        if (!move_uploaded_file($_FILES['files']['tmp_name'][$i], "$dir/$stored")) continue;
        $total += $size;
        $leadFiles[] = ['name' => $clean, 'stored' => $stored, 'size' => $size, 'type' => (string)($_FILES['files']['type'][$i] ?? '')];
    }
}

$all = glob(vd_data_dir() . '/leads/*.json') ?: [];
$lead = ['id' => $id, 'num' => count($all) + 1, 'time' => $now, 'name' => $name, 'contact' => $contact, 'task' => $task,
         'files' => $leadFiles, 'status' => 'new', 'lang' => ($_POST['lang'] ?? 'ru') === 'en' ? 'en' : 'ru',
         'page' => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 300), 'tg' => '', 'tg_ok' => false, 'tg_tries' => 0];
$path = vd_data_dir() . "/leads/$id.json";
vd_json_write($path, $lead);

// answer the visitor first, then talk to Telegram (it can take a few seconds with files)
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
vd_notify($lead);
vd_json_write($path, $lead);
if (!empty($lead['tg_ok'])) vd_retry_pending(3);
