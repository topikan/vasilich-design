<?php
// Gives a request's attachment to a signed-in admin.
require __DIR__ . '/../lib/vd.php';
session_set_cookie_params(['httponly' => true, 'secure' => vd_https(), 'samesite' => 'Strict', 'path' => dirname($_SERVER['SCRIPT_NAME']) . '/']);
session_name('vdadmin');
session_start();
if (empty($_SESSION['auth'])) { header('Location: ./'); exit; }
$id = preg_replace('/[^0-9a-f-]/', '', (string)($_GET['id'] ?? ''));
$l = vd_json_read(vd_data_dir() . "/leads/$id.json", null);
$f = $l['files'][(int)($_GET['f'] ?? -1)] ?? null;
$path = $f ? vd_data_dir() . "/files/$id/" . basename($f['stored']) : '';
if (!$f || !is_file($path)) { http_response_code(404); exit('Файл не найден'); }
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($f['name']));
header('X-Content-Type-Options: nosniff');
readfile($path);
