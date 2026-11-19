<?php
// Admin page: requests from the site + Telegram settings. Password protected.
require __DIR__ . '/../lib/vd.php';
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Strict', 'path' => dirname($_SERVER['SCRIPT_NAME']) . '/']);
session_name('vdadmin');
session_start();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'");

$c = vd_config();
if (!$c['password_hash'] && !$c['setup_code']) { $c['setup_code'] = strtoupper(bin2hex(random_bytes(4))); vd_config_save($c); }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
function go(string $msg = '', string $q = '') { $_SESSION['flash'] = $msg; header('Location: ./' . $q); exit; }
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) go('Форма устарела, попробуйте ещё раз');
$act = $post ? (string)($_POST['act'] ?? '') : '';

// ---------- first run: create the password with the setup code ----------
if (!$c['password_hash']) {
    $err = '';
    if ($act === 'setup') {
        $p1 = (string)($_POST['p1'] ?? ''); $p2 = (string)($_POST['p2'] ?? '');
        if (!hash_equals($c['setup_code'], strtoupper(trim((string)($_POST['code'] ?? ''))))) $err = 'Неверный код установки';
        elseif (mb_strlen($p1) < 8) $err = 'Пароль — минимум 8 символов';
        elseif ($p1 !== $p2) $err = 'Пароли не совпадают';
        else { $c['password_hash'] = password_hash($p1, PASSWORD_DEFAULT); $c['setup_code'] = ''; vd_config_save($c); session_regenerate_id(true); $_SESSION['auth'] = 1; go('Пароль сохранён', '?tab=settings'); }
    }
    page('Настройка', function () use ($csrf, $err) { ?>
      <form method="post" class="card narrow">
        <h1>Первый вход</h1>
        <p class="muted">Введите код установки и придумайте пароль для этой страницы.</p>
        <?php if ($err) echo '<p class="err">' . vd_h($err) . '</p>'; ?>
        <input type="hidden" name="csrf" value="<?= vd_h($csrf) ?>"><input type="hidden" name="act" value="setup">
        <label>Код установки<input name="code" autocomplete="off" required></label>
        <label>Пароль<input type="password" name="p1" autocomplete="new-password" required minlength="8"></label>
        <label>Пароль ещё раз<input type="password" name="p2" autocomplete="new-password" required minlength="8"></label>
        <button>Сохранить</button>
      </form>
    <?php }); exit;
}

// ---------- login ----------
if ($act === 'logout') { $_SESSION = []; session_destroy(); header('Location: ./'); exit; }
if (empty($_SESSION['auth'])) {
    $err = '';
    if ($act === 'login') {
        $att = vd_json_read(vd_data_dir() . '/login.json', []);
        $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
        $recent = array_filter($att[$key] ?? [], fn($t) => $t > time() - 900);
        if (count($recent) >= 8) $err = 'Слишком много попыток. Подождите 15 минут.';
        elseif (password_verify((string)($_POST['p'] ?? ''), $c['password_hash'])) { session_regenerate_id(true); $_SESSION['auth'] = 1; unset($att[$key]); vd_json_write(vd_data_dir() . '/login.json', $att); go(); }
        else { $recent[] = time(); $att[$key] = array_values($recent); vd_json_write(vd_data_dir() . '/login.json', $att); $err = 'Неверный пароль'; }
    }
    page('Вход', function () use ($csrf, $err) { ?>
      <form method="post" class="card narrow">
        <h1>Заявки VASILICH DESIGN</h1>
        <?php if ($err) echo '<p class="err">' . vd_h($err) . '</p>'; ?>
        <input type="hidden" name="csrf" value="<?= vd_h($csrf) ?>"><input type="hidden" name="act" value="login">
        <label>Пароль<input type="password" name="p" autocomplete="current-password" required autofocus></label>
        <button>Войти</button>
      </form>
    <?php }); exit;
}

// ---------- actions ----------
$leadPath = fn($id) => vd_data_dir() . '/leads/' . preg_replace('/[^0-9a-f-]/', '', (string)$id) . '.json';
switch ($act) {
    case 'status':
        $p = $leadPath($_POST['id'] ?? ''); $l = vd_json_read($p, null);
        if ($l && in_array($_POST['status'] ?? '', ['new', 'work', 'done'], true)) { $l['status'] = $_POST['status']; vd_json_write($p, $l); }
        go('', '#' . ($l['id'] ?? ''));
    case 'delete':
        $p = $leadPath($_POST['id'] ?? ''); $l = vd_json_read($p, null);
        if ($l) { $d = vd_data_dir() . '/files/' . $l['id']; foreach (glob("$d/*") ?: [] as $f) unlink($f); if (is_dir($d)) rmdir($d); unlink($p); }
        go('Заявка удалена');
    case 'resend':
        $p = $leadPath($_POST['id'] ?? ''); $l = vd_json_read($p, null);
        if ($l) { $l['tg_tries'] = 0; vd_notify($l); vd_json_write($p, $l); go($l['tg'], '#' . $l['id']); }
        go();
    case 'token':
        $t = trim((string)($_POST['token'] ?? ''));
        if (!preg_match('/^\d{6,12}:[A-Za-z0-9_-]{30,50}$/', $t)) go('Это не похоже на токен бота', '?tab=settings');
        $r = vd_tg($t, 'getMe');
        if (empty($r['ok'])) go('Telegram не принял токен: ' . ($r['description'] ?? ''), '?tab=settings');
        $c['tg_token'] = $t; $c['tg_bot'] = $r['result']['username'] ?? ''; $c['tg_link_code'] = bin2hex(random_bytes(6)); $c['tg_offset'] = 0;
        vd_tg($t, 'deleteWebhook');
        vd_config_save($c); go('Бот @' . $c['tg_bot'] . ' подключён', '?tab=settings');
    case 'link':
        if (!$c['tg_token']) go('Сначала сохраните токен бота', '?tab=settings');
        $r = vd_tg($c['tg_token'], 'getUpdates', ['offset' => (int)$c['tg_offset'], 'timeout' => 0, 'allowed_updates' => json_encode(['message'])]);
        if (empty($r['ok'])) go('Telegram: ' . ($r['description'] ?? 'ошибка'), '?tab=settings');
        $added = [];
        foreach ($r['result'] as $u) {
            $c['tg_offset'] = $u['update_id'] + 1;
            $m = $u['message'] ?? null; if (!$m) continue;
            if (trim($m['text'] ?? '') !== '/start ' . $c['tg_link_code']) continue;
            $chat = $m['chat']; $who = trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? '')) ?: ($chat['title'] ?? '');
            if (!empty($chat['username'])) $who .= ' (@' . $chat['username'] . ')';
            $exists = array_filter($c['tg_chats'], fn($x) => $x['id'] == $chat['id']);
            if (!$exists) { $c['tg_chats'][] = ['id' => $chat['id'], 'name' => $who]; $added[] = $who; }
            vd_tg($c['tg_token'], 'sendMessage', ['chat_id' => $chat['id'], 'text' => 'Готово: сюда будут приходить заявки с сайта vasilichdesign.art.']);
        }
        vd_config_save($c);
        go($added ? 'Привязано: ' . implode(', ', $added) : 'Новых привязок нет. Откройте ссылку в Telegram, нажмите «Запустить» и потом проверьте ещё раз.', '?tab=settings');
    case 'unlink':
        $c['tg_chats'] = array_values(array_filter($c['tg_chats'], fn($x) => (string)$x['id'] !== (string)($_POST['chat'] ?? '')));
        vd_config_save($c); go('Отвязано', '?tab=settings');
    case 'test':
        if (!$c['tg_token'] || !$c['tg_chats']) go('Сначала подключите бота и привяжите Telegram', '?tab=settings');
        $res = [];
        foreach ($c['tg_chats'] as $ch) { $r = vd_tg($c['tg_token'], 'sendMessage', ['chat_id' => $ch['id'], 'text' => 'Проверка: заявки с сайта приходят сюда.']); $res[] = $ch['name'] . ': ' . (!empty($r['ok']) ? 'доставлено' : ($r['description'] ?? 'ошибка')); }
        go(implode('; ', $res), '?tab=settings');
    case 'relay':
        $u = trim((string)($_POST['relay_url'] ?? ''));
        if ($u !== '' && !preg_match('#^https://script\.google\.com/macros/s/[A-Za-z0-9_-]+/exec$#', $u)) go('Адрес реле должен выглядеть как https://script.google.com/macros/s/…/exec', '?tab=settings');
        $c['relay_url'] = $u;
        vd_config_save($c); @unlink(vd_data_dir() . '/tg_blocked');
        go($u ? 'Адрес реле сохранён. Нажмите «Отправить проверочное сообщение».' : 'Реле отключено', '?tab=settings');
    case 'mail':
        $m = trim((string)($_POST['mail_to'] ?? ''));
        if ($m !== '' && !filter_var($m, FILTER_VALIDATE_EMAIL)) go('Почта указана с ошибкой', '?tab=settings');
        $c['mail_to'] = $m; $c['mail_mode'] = in_array($_POST['mail_mode'] ?? '', ['fail', 'always', 'off'], true) ? $_POST['mail_mode'] : 'fail';
        vd_config_save($c); go('Настройки почты сохранены', '?tab=settings');
    case 'mailtest':
        if (!$c['mail_to']) go('Сначала укажите почту', '?tab=settings');
        $ok = vd_mail_lead($c['mail_to'], ['num' => 0, 'name' => 'Проверка', 'contact' => 'это тестовое письмо', 'task' => 'Если вы его видите, заявки на почту доходят. Проверьте и папку «Спам».', 'files' => [], 'time' => time(), 'id' => 'test']);
        go($ok ? 'Письмо отправлено на ' . $c['mail_to'] . ' — проверьте почту и «Спам»' : 'Хостинг не смог отправить письмо', '?tab=settings');
    case 'password':
        if (!password_verify((string)($_POST['old'] ?? ''), $c['password_hash'])) go('Текущий пароль неверный', '?tab=settings');
        if (mb_strlen((string)($_POST['new'] ?? '')) < 8) go('Новый пароль — минимум 8 символов', '?tab=settings');
        $c['password_hash'] = password_hash((string)$_POST['new'], PASSWORD_DEFAULT); vd_config_save($c); go('Пароль изменён', '?tab=settings');
}

// ---------- pages ----------
if (!$c['relay_secret']) { $c['relay_secret'] = bin2hex(random_bytes(16)); vd_config_save($c); }
vd_retry_pending(2);
$tab = ($_GET['tab'] ?? '') === 'settings' ? 'settings' : 'leads';
$leads = vd_leads();
$filter = in_array($_GET['s'] ?? '', ['new', 'work', 'done'], true) ? $_GET['s'] : '';
$ST = ['new' => 'Новая', 'work' => 'В работе', 'done' => 'Закрыта'];
page('Заявки', function () use ($tab, $leads, $filter, $ST, $c, $csrf, $flash) {
    $count = fn($s) => count(array_filter($leads, fn($l) => $l['status'] === $s)); ?>
  <header class="top">
    <b>VASILICH <span>DESIGN</span></b>
    <nav><a href="./" class="<?= $tab === 'leads' ? 'on' : '' ?>">Заявки<?php if ($n = $count('new')) echo " <i>$n</i>"; ?></a><a href="?tab=settings" class="<?= $tab === 'settings' ? 'on' : '' ?>">Настройки</a></nav>
    <form method="post"><input type="hidden" name="csrf" value="<?= vd_h($csrf) ?>"><button name="act" value="logout" class="ghost">Выйти</button></form>
  </header>
  <?php if ($flash) echo '<p class="flash">' . vd_h($flash) . '</p>'; ?>
  <?php if ($tab === 'leads'): ?>
    <div class="chips">
      <a href="./" class="<?= !$filter ? 'on' : '' ?>">Все <?= count($leads) ?></a>
      <?php foreach ($ST as $k => $v) echo '<a href="?s=' . $k . '" class="' . ($filter === $k ? 'on' : '') . '">' . $v . ' ' . $count($k) . '</a>'; ?>
    </div>
    <?php if (!$c['tg_chats']) echo '<p class="warn">Telegram ещё не привязан — заявки сохраняются только здесь. <a href="?tab=settings">Привязать</a></p>'; ?>
    <?php $shown = array_filter($leads, fn($l) => !$filter || $l['status'] === $filter);
    if (!$shown) echo '<p class="muted empty">Заявок пока нет.</p>';
    foreach ($shown as $l): ?>
      <article class="card lead s-<?= vd_h($l['status']) ?>" id="<?= vd_h($l['id']) ?>">
        <div class="lead__head">
          <span class="num">№<?= vd_h($l['num']) ?></span>
          <time><?= vd_h(date('d.m.Y H:i', $l['time'])) ?></time>
          <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= vd_h($csrf) ?>"><input type="hidden" name="act" value="status"><input type="hidden" name="id" value="<?= vd_h($l['id']) ?>">
            <select name="status" onchange="this.form.submit()"><?php foreach ($ST as $k => $v) echo '<option value="' . $k . '"' . ($l['status'] === $k ? ' selected' : '') . '>' . $v . '</option>'; ?></select>
            <noscript><button>OK</button></noscript></form>
        </div>
        <dl>
          <?php if ($l['name'] !== '') echo '<dt>Имя</dt><dd>' . vd_h($l['name']) . '</dd>'; ?>
          <dt>Контакт</dt><dd class="contact"><?= vd_h($l['contact']) ?></dd>
          <?php if ($l['task'] !== '') echo '<dt>Задача</dt><dd class="task">' . nl2br(vd_h($l['task'])) . '</dd>'; ?>
          <?php if ($l['files']) { echo '<dt>Файлы</dt><dd class="files">'; foreach ($l['files'] as $i => $f) echo '<a href="file.php?id=' . urlencode($l['id']) . '&amp;f=' . $i . '">' . vd_h($f['name']) . '</a> <small>' . vd_h($f['size'] >= 1048576 ? round($f['size'] / 1048576, 1) . ' МБ' : max(1, round($f['size'] / 1024)) . ' КБ') . '</small><br>'; echo '</dd>'; } ?>
        </dl>
        <div class="lead__foot">
          <small class="muted">Telegram: <?= vd_h($l['tg'] ?: 'отправляется…') ?></small>
          <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= vd_h($csrf) ?>"><input type="hidden" name="id" value="<?= vd_h($l['id']) ?>">
            <button name="act" value="resend" class="ghost">Отправить в Telegram ещё раз</button>
            <button name="act" value="delete" class="ghost danger" onclick="return confirm('Удалить заявку №<?= vd_h($l['num']) ?> вместе с файлами? Восстановить её будет нельзя.')">Удалить</button></form>
        </div>
      </article>
    <?php endforeach; ?>
  <?php else: ?>
    <section class="card">
      <h2>1. Бот</h2>
      <?php if ($c['tg_token']): ?>
        <p>Подключён бот <b>@<?= vd_h($c['tg_bot'] ?? '') ?></b>.</p>
      <?php else: ?>
        <p class="muted">Создайте бота в Telegram у <b>@BotFather</b> (команда /newbot) и вставьте сюда токен, который он выдаст.</p>
      <?php endif; ?>
      <form method="post" class="row"><input type="hidden" name="csrf" value="<?= vd_h($csrf) ?>"><input type="hidden" name="act" value="token">
        <input name="token" placeholder="<?= $c['tg_token'] ? 'Новый токен, если нужно сменить бота' : '123456789:AA…' ?>" autocomplete="off" required><button>Сохранить</button></form>
    </section>
    <section class="card">
      <h2>2. Кому приходят заявки</h2>
      <?php if ($c['tg_chats']): ?><ul class="chats"><?php foreach ($c['tg_chats'] as $ch): ?>
        <li><?= vd_h($ch['name'] ?: $ch['id']) ?>
          <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= vd_h($csrf) ?>"><input type="hidden" name="act" value="unlink"><input type="hidden" name="chat" value="<?= vd_h($ch['id']) ?>"><button class="ghost danger">Отвязать</button></form></li>
      <?php endforeach; ?></ul><?php else: ?><p class="muted">Пока никто не привязан.</p><?php endif; ?>
      <?php if ($c['tg_token'] && !empty($c['tg_bot'])): $link = 'https://t.me/' . $c['tg_bot'] . '?start=' . $c['tg_link_code']; ?>
        <p>Чтобы добавить человека: он открывает ссылку в своём Telegram и нажимает «Запустить», потом нажмите «Проверить».</p>
        <p><a class="tglink" href="<?= vd_h($link) ?>" target="_blank" rel="noopener"><?= vd_h($link) ?></a></p>
        <form method="post" class="row"><input type="hidden" name="csrf" value="<?= vd_h($csrf) ?>"><button name="act" value="link">Проверить привязку</button><button name="act" value="test" class="ghost">Отправить проверочное сообщение</button></form>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2>3. Если Telegram не доходит</h2>
      <p class="muted">Хостинг в России, и связь с Telegram там часто режется. Неотправленные заявки сайт сам пробует отправить ещё раз (при новых заявках, при открытии этой страницы и по расписанию). Надёжный обход — реле через Google: заявки уходят в Telegram с серверов Google.</p>
      <p><b>Статус:</b> <?= $c['relay_url'] ? 'реле подключено' : 'реле не подключено' ?><?php if (is_file(vd_data_dir() . '/tg_blocked') && filemtime(vd_data_dir() . '/tg_blocked') > time() - 1800) echo ' · прямое соединение с Telegram сейчас не работает'; ?></p>
      <p class="muted">Секрет для скрипта реле: <code><?= vd_h($c['relay_secret']) ?></code></p>
      <form method="post" class="row"><input type="hidden" name="csrf" value="<?= vd_h($csrf) ?>"><input type="hidden" name="act" value="relay">
        <input name="relay_url" value="<?= vd_h($c['relay_url']) ?>" placeholder="https://script.google.com/macros/s/…/exec" autocomplete="off"><button>Сохранить</button></form>
    </section>
    <section class="card">
      <h2>4. Дубль на почту</h2>
      <form method="post" class="row"><input type="hidden" name="csrf" value="<?= vd_h($csrf) ?>"><input type="hidden" name="act" value="mail">
        <input name="mail_to" type="email" value="<?= vd_h($c['mail_to']) ?>" placeholder="почта">
        <select name="mail_mode" style="flex:0 1 auto;width:auto"><?php foreach (['fail' => 'когда Telegram не доступен', 'always' => 'всегда', 'off' => 'не отправлять'] as $k => $v) echo '<option value="' . $k . '"' . ($c['mail_mode'] === $k ? ' selected' : '') . '>' . $v . '</option>'; ?></select>
        <button>Сохранить</button><button name="act" value="mailtest" class="ghost">Тестовое письмо</button></form>
    </section>
    <section class="card">
      <h2>Пароль от этой страницы</h2>
      <form method="post" class="row"><input type="hidden" name="csrf" value="<?= vd_h($csrf) ?>"><input type="hidden" name="act" value="password">
        <input type="password" name="old" placeholder="Текущий пароль" autocomplete="current-password" required><input type="password" name="new" placeholder="Новый пароль, от 8 символов" autocomplete="new-password" required minlength="8"><button>Сменить</button></form>
    </section>
  <?php endif;
});

function page(string $title, callable $body) { ?><!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= vd_h($title) ?> · VASILICH DESIGN</title><link rel="icon" href="../favicon.svg" type="image/svg+xml">
<style>
:root{--bg:#121110;--card:#1b1917;--line:#2c2926;--text:#eee7dc;--muted:#9d958a;--amber:#e2873a;--red:#e05a4f}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:20px;max-width:900px;margin:0 auto}
a{color:var(--amber)}h1{font-size:1.4rem;margin:0 0 8px}h2{font-size:1.05rem;margin:0 0 10px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:14px}
.narrow{max-width:380px;margin:12vh auto}label{display:block;margin:12px 0 0;font-size:.85rem;color:var(--muted)}
input,select,textarea{width:100%;margin-top:4px;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:#0f0e0d;color:var(--text);font:inherit}
button{margin-top:14px;padding:10px 16px;border:0;border-radius:10px;background:var(--amber);color:#1b120a;font:inherit;font-weight:600;cursor:pointer}
button.ghost{background:none;border:1px solid var(--line);color:var(--text);font-weight:400}button.danger{color:var(--red)}
.row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}.row input{flex:1 1 220px}.row button{margin-top:4px}
.inline{display:inline}.inline button{margin:0 0 0 6px;padding:6px 10px;font-size:.85rem}.inline select{width:auto;margin:0;padding:5px 8px}
.top{display:flex;align-items:center;gap:18px;margin-bottom:16px}.top b span{color:var(--muted);font-weight:400}.top nav{flex:1;display:flex;gap:6px}
.top nav a,.chips a{padding:6px 12px;border-radius:99px;text-decoration:none;color:var(--text);border:1px solid var(--line)}.top nav a.on,.chips a.on{background:var(--text);color:#121110}
.top nav i{font-style:normal;background:var(--amber);color:#1b120a;border-radius:99px;padding:0 7px;font-size:.8rem;margin-left:4px}.top form button{margin:0;padding:6px 12px}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}.chips a{font-size:.85rem}
.flash{background:#24201b;border:1px solid var(--amber);padding:10px 14px;border-radius:10px}.warn{color:var(--amber)}.err{color:var(--red)}.muted{color:var(--muted)}.empty{padding:40px 0;text-align:center}
.lead__head{display:flex;gap:12px;align-items:center;margin-bottom:10px}.lead__head time{color:var(--muted);flex:1}.num{font-weight:700}
.lead.s-new{border-color:rgba(226,135,58,.6)}.lead.s-done{opacity:.6}
dl{display:grid;grid-template-columns:90px 1fr;gap:6px 12px;margin:0}dt{color:var(--muted);font-size:.85rem}dd{margin:0;word-break:break-word}.contact{font-weight:600}
.lead__foot{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px;border-top:1px solid var(--line);padding-top:10px}
.chats{list-style:none;padding:0}.chats li{padding:6px 0;border-bottom:1px solid var(--line)}.tglink{word-break:break-all}
@media(max-width:560px){body{padding:12px}dl{grid-template-columns:1fr}.lead__foot .inline button{margin:6px 6px 0 0}}
</style></head><body><?php $body(); ?></body></html><?php }
