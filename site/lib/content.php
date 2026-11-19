<?php
// Site content edited in the admin page: portfolio, workflow steps, texts, media slots, contacts.
// Master copy: ~/vd-data/content.json. Published to uploads/content.js, which index.html loads before its scripts.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'content.php' && strpos($_SERVER['SCRIPT_FILENAME'] ?? '', '/lib/') !== false) { http_response_code(404); exit; }

const VD_IMG_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
const VD_VID_EXT = ['mp4', 'webm', 'mov'];

// media slots on the page: key => [label, default file, kind]
const VD_MEDIA = [
    'hero'       => ['Первый экран — фон', 'assets/hero.jpg', 'img'],
    'svcCgi'     => ['Услуги — карточка CGI', 'assets/svc-cgi.jpg', 'img'],
    'svcProduct' => ['Услуги — карточка Product', 'assets/svc-product.jpg', 'img'],
    'svcAnim'    => ['Услуги — карточка Animation (видео)', 'assets/svc-animation.mp4', 'vid'],
    'svcInter'   => ['Услуги — карточка Interactive 3D (видео)', 'assets/svc-interactive.mp4', 'vid'],
    'packs'      => ['Пакеты услуг — ролик', 'assets/packs.mp4', 'vid'],
    'about'      => ['О студии — картинка', 'assets/about.jpg', 'img'],
    'contact'    => ['Контакты — картинка над формой', 'assets/contact.jpg', 'img'],
];
const VD_CONTACTS = [
    'tg'        => ['Telegram (ник без @)', 'vasilich_design'],
    'behance'   => ['Behance (ник или ссылка)', 'vasilichdesign'],
    'mail'      => ['Почта', 'vasilichdesign@yandex.ru'],
    'phone'     => ['Телефон', '+7 988 924-43-87'],
    'instagram' => ['Instagram (ссылка)', ''],
    'youtube'   => ['YouTube (ссылка)', ''],
];
const VD_CATS = ['cgi' => 'CGI', 'product' => 'Product', 'animation' => 'Animation'];

function vd_site_dir(): string { return dirname(__DIR__); }
function vd_defaults(): array { static $d; return $d ??= (json_decode((string)file_get_contents(__DIR__ . '/defaults.json'), true) ?: ['works' => [], 'steps' => [], 'texts' => []]); }

function vd_content(): array {
    $c = vd_json_read(vd_data_dir() . '/content.json', []);
    return $c + ['works' => null, 'steps' => null, 'texts' => ['ru' => [], 'en' => []], 'media' => [], 'contacts' => null];
}
function vd_works(array $c): array { return is_array($c['works']) ? $c['works'] : vd_defaults()['works']; }
function vd_steps(array $c): array { return is_array($c['steps']) ? $c['steps'] : vd_defaults()['steps']; }

function vd_content_save(array $c): void {
    vd_json_write(vd_data_dir() . '/content.json', $c);
    vd_publish($c);
    vd_gc_uploads($c);
}

// Writes uploads/content.js — only what differs from the page's built-in content.
function vd_publish(?array $c = null): void {
    $c ??= vd_content();
    $out = [];
    if (is_array($c['works'])) $out['works'] = array_values($c['works']);
    if (is_array($c['steps'])) $out['steps'] = array_values($c['steps']);
    if ($c['texts']['ru'] || $c['texts']['en']) $out['texts'] = $c['texts'];
    if ($c['media']) $out['media'] = $c['media'];
    if (is_array($c['contacts'])) $out['contacts'] = $c['contacts'];
    $dir = vd_site_dir() . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $js = 'window.VD_CONTENT=' . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ";\n";
    $tmp = "$dir/content.js." . bin2hex(random_bytes(3));
    file_put_contents($tmp, $js);
    chmod($tmp, 0644);
    rename($tmp, "$dir/content.js");
}

// Restores uploads/content.js if a deploy removed it.
function vd_publish_if_missing(): void {
    if (!is_file(vd_site_dir() . '/uploads/content.js') && is_file(vd_data_dir() . '/content.json')) vd_publish();
}

// Saves one uploaded file into uploads/. $kind: img | vid | any. Returns the site-relative path or an error string starting with '!'.
function vd_store_upload(array $f, string $kind): string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) return '!Файл «' . $f['name'] . '» больше, чем разрешает хостинг (' . ini_get('upload_max_filesize') . ')';
    if ($f['error'] !== UPLOAD_ERR_OK) return '!Файл «' . $f['name'] . '» не загрузился (код ' . $f['error'] . ')';
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    $ok = $kind === 'img' ? VD_IMG_EXT : ($kind === 'vid' ? VD_VID_EXT : array_merge(VD_IMG_EXT, VD_VID_EXT));
    if (!in_array($ext, $ok, true)) return '!«' . $f['name'] . '»: нужен файл ' . strtoupper(implode(', ', $ok));
    if ($ext === 'jpeg') $ext = 'jpg';
    $dir = vd_site_dir() . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $base = preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', pathinfo((string)$f['name'], PATHINFO_FILENAME)) ?: '')) ?: 'file';
    $name = date('ymd') . '-' . bin2hex(random_bytes(3)) . '-' . trim(substr($base, 0, 40), '-') . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) return '!Не удалось сохранить «' . $f['name'] . '»';
    chmod("$dir/$name", 0644);
    return 'uploads/' . $name;
}

// $_FILES['x'] with multiple files -> list of single-file arrays
function vd_files_list(string $field): array {
    $F = $_FILES[$field] ?? null;
    if (!$F) return [];
    if (!is_array($F['name'])) return [$F];
    $out = [];
    foreach ($F['name'] as $i => $n) $out[] = ['name' => $n, 'type' => $F['type'][$i], 'tmp_name' => $F['tmp_name'][$i], 'error' => $F['error'][$i], 'size' => $F['size'][$i]];
    return $out;
}

// Deletes files in uploads/ that the content no longer uses.
function vd_gc_uploads(array $c): void {
    $used = json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    foreach (glob(vd_site_dir() . '/uploads/*') ?: [] as $f) {
        $n = basename($f);
        if ($n === 'content.js' || $n === 'index.html' || $n[0] === '.' || !is_file($f)) continue;
        if (filemtime($f) > time() - 600) continue;          // just uploaded, maybe not saved yet
        if (strpos($used, 'uploads/' . $n) === false) @unlink($f);
    }
}

function vd_clean_text(string $v, bool $plain = false): string {
    $v = trim(str_replace("\r", '', $v));
    if ($plain) return strip_tags($v);
    $v = strip_tags($v, '<br><b><strong><i><em>');
    $v = preg_replace('/<(br|b|strong|i|em)\b[^>]*>/i', '<$1>', $v);
    return preg_replace('/<\/(br)>/i', '', $v);
}

function vd_is_video(string $p): bool { $p = preg_replace('/^v:/', '', $p); return in_array(strtolower(pathinfo(parse_url($p, PHP_URL_PATH) ?? $p, PATHINFO_EXTENSION)), VD_VID_EXT, true); }
