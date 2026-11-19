<?php
// Admin: portfolio, texts, media and contacts. Included by admin/index.php after login.
if (!defined('VD_ADMIN')) { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/content.php';

function vd_L(string $p): array { return ['ru' => vd_clean_text((string)($_POST[$p . '_ru'] ?? '')), 'en' => vd_clean_text((string)($_POST[$p . '_en'] ?? ''))]; }
function vd_find_work(array $works, string $id): int { foreach ($works as $i => $w) if (($w['id'] ?? '') === $id) return $i; return -1; }
function vd_thumb(string $path, string $cls = 'thumb'): string {
    $p = preg_replace('/^v:/', '', $path);
    if ($p === '') return '<span class="' . $cls . ' empty">нет</span>';
    $src = '../' . vd_h($p);
    return vd_is_video($p) ? '<video class="' . $cls . '" src="' . $src . '" muted preload="metadata"></video>' : '<img class="' . $cls . '" src="' . $src . '" alt="" loading="lazy">';
}
function vd_limits(): string { return 'Один файл — до ' . ini_get('upload_max_filesize') . ', всё за раз — до ' . ini_get('post_max_size') . '. Картинки: JPG, PNG, WEBP. Видео: MP4 (H.264).'; }

// ---------------- actions ----------------
function vd_content_action(string $act): void {
    $C = vd_content();
    $works = vd_works($C);
    $id = (string)($_POST['id'] ?? '');
    switch ($act) {
        case 'work_new':
            $nid = 'w' . date('ymdHis');
            $works[] = ['id' => $nid, 't' => ['ru' => 'Новая работа', 'en' => 'New work'], 'c' => 'cgi', 'img' => '', 'loop' => '', 'pos' => '', 'task' => ['ru' => '', 'en' => ''], 'res' => ['ru' => '', 'en' => ''], 'fmt' => ['ru' => '', 'en' => ''], 'media' => [], 'hidden' => true];
            $C['works'] = $works; vd_content_save($C);
            go('Работа создана и пока скрыта с сайта. Заполните её и сохраните.', '?tab=works&edit=' . $nid);
        case 'work_move':
            $i = vd_find_work($works, $id); $j = $i + ((int)($_POST['dir'] ?? 0) < 0 ? -1 : 1);
            if ($i >= 0 && isset($works[$j])) { [$works[$i], $works[$j]] = [$works[$j], $works[$i]]; $C['works'] = $works; vd_content_save($C); }
            go('', '?tab=works#' . $id);
        case 'work_toggle':
            $i = vd_find_work($works, $id);
            if ($i >= 0) {
                if ($works[$i]['hidden'] && !$works[$i]['img']) go('Сначала добавьте превью — без него работу нельзя показать на сайте', '?tab=works&edit=' . $id);
                $works[$i]['hidden'] = !$works[$i]['hidden']; $C['works'] = $works; vd_content_save($C);
                go($works[$i]['hidden'] ? 'Работа скрыта с сайта' : 'Работа показана на сайте', '?tab=works#' . $id);
            }
            go('', '?tab=works');
        case 'work_delete':
            $i = vd_find_work($works, $id);
            if ($i >= 0) { array_splice($works, $i, 1); $C['works'] = $works; vd_content_save($C); }
            go('Работа удалена', '?tab=works');
        case 'work_save':
            $i = vd_find_work($works, $id);
            if ($i < 0) go('Работа не найдена', '?tab=works');
            $w = $works[$i]; $errs = [];
            $w['t'] = vd_L('t'); $w['task'] = vd_L('task'); $w['res'] = vd_L('res'); $w['fmt'] = vd_L('fmt');
            if ($w['t']['ru'] === '') $errs[] = 'Укажите название';
            $w['c'] = array_key_exists($_POST['c'] ?? '', VD_CATS) ? $_POST['c'] : 'cgi';
            $w['pos'] = preg_match('/^[a-z0-9 %.]{0,30}$/i', (string)($_POST['pos'] ?? '')) ? trim((string)$_POST['pos']) : '';
            $up = vd_store_upload($_FILES['img'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'img');
            if ($up !== '' && $up[0] === '!') $errs[] = substr($up, 1); elseif ($up !== '') $w['img'] = $up;
            if (!empty($_POST['loop_remove'])) $w['loop'] = '';
            $up = vd_store_upload($_FILES['loop'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'vid');
            if ($up !== '' && $up[0] === '!') $errs[] = substr($up, 1); elseif ($up !== '') $w['loop'] = $up;
            // gallery: keep the posted order, drop removed, append new uploads
            $keep = array_values(array_filter((array)($_POST['media'] ?? []), fn($m) => is_string($m) && in_array($m, $w['media'], true)));
            $keep = array_values(array_diff($keep, (array)($_POST['media_del'] ?? [])));
            foreach ($w['media'] as $m) if (is_array($m)) $keep[] = $m; // grid groups from the old layout stay at the end
            foreach (vd_files_list('media_add') as $f) {
                $up = vd_store_upload($f, 'any');
                if ($up !== '' && $up[0] === '!') $errs[] = substr($up, 1); elseif ($up !== '') $keep[] = (vd_is_video($up) ? 'v:' : '') . $up;
            }
            $w['media'] = $keep;
            if (!$w['img']) $w['hidden'] = true;
            $works[$i] = $w; $C['works'] = $works; vd_content_save($C);
            go($errs ? 'Сохранено, но: ' . implode('; ', $errs) : 'Работа сохранена' . ($w['hidden'] ? ' (скрыта с сайта)' : ''), '?tab=works&edit=' . $id);
        case 'works_reset':
            $C['works'] = null; vd_content_save($C); go('Портфолио возвращено к исходному', '?tab=works');
        case 'texts_save':
            $D = vd_defaults()['texts']; $sec = (string)($_POST['sec'] ?? '');
            foreach ($D as $t) {
                if ($t['sec'] !== $sec) continue;
                foreach (['ru', 'en'] as $lang) {
                    if (!isset($_POST['tx'][$lang][$t['k']])) continue;
                    $v = vd_clean_text((string)$_POST['tx'][$lang][$t['k']], !empty($t['ph']));
                    $def = trim((string)$t[$lang]);
                    if ($v === $def || $v === '') unset($C['texts'][$lang][$t['k']]); else $C['texts'][$lang][$t['k']] = $v;
                }
            }
            if ($sec === 'Этапы работы' && isset($_POST['st'])) {
                $steps = [];
                foreach ((array)$_POST['st'] as $s) {
                    if (!empty($s['del'])) continue;
                    $row = ['t' => ['ru' => vd_clean_text($s['t_ru'] ?? ''), 'en' => vd_clean_text($s['t_en'] ?? '')], 'd' => ['ru' => vd_clean_text($s['d_ru'] ?? ''), 'en' => vd_clean_text($s['d_en'] ?? '')], 'o' => ['ru' => vd_clean_text($s['o_ru'] ?? ''), 'en' => vd_clean_text($s['o_en'] ?? '')]];
                    if ($row['t']['ru'] !== '') $steps[] = $row;
                }
                $C['steps'] = $steps === vd_defaults()['steps'] ? null : $steps;
            }
            vd_content_save($C); go('Тексты сохранены', '?tab=texts&sec=' . urlencode($sec));
        case 'media_save':
            $k = (string)($_POST['key'] ?? '');
            if (!isset(VD_MEDIA[$k])) go('', '?tab=site');
            if (!empty($_POST['reset'])) { unset($C['media'][$k]); vd_content_save($C); go('Возвращено исходное: ' . VD_MEDIA[$k][0], '?tab=site#m-' . $k); }
            $up = vd_store_upload($_FILES['file'] ?? ['error' => UPLOAD_ERR_NO_FILE], VD_MEDIA[$k][2]);
            if ($up === '') go('Выберите файл', '?tab=site#m-' . $k);
            if ($up[0] === '!') go(substr($up, 1), '?tab=site#m-' . $k);
            $C['media'][$k] = $up; vd_content_save($C); go('Заменено: ' . VD_MEDIA[$k][0], '?tab=site#m-' . $k);
        case 'contacts_save':
            $out = [];
            foreach (VD_CONTACTS as $k => $_) {
                $v = trim(strip_tags((string)($_POST['ct'][$k] ?? '')));
                if (in_array($k, ['instagram', 'youtube'], true) && $v !== '' && !preg_match('#^https?://#', $v)) $v = 'https://' . $v;
                if ($k === 'mail' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) go('Почта указана с ошибкой', '?tab=site');
                $out[$k] = mb_substr($v, 0, 200);
            }
            $C['contacts'] = $out; vd_content_save($C); go('Контакты сохранены. Пустые поля скрыты на сайте.', '?tab=site');
    }
}

// ---------------- pages ----------------
function vd_content_render(string $tab): void {
    global $csrf;
    $C = vd_content();
    $hid = fn($act, $extra = '') => '<input type="hidden" name="csrf" value="' . vd_h($csrf) . '"><input type="hidden" name="act" value="' . $act . '">' . $extra;
    if ($tab === 'works') {
        $works = vd_works($C);
        $edit = (string)($_GET['edit'] ?? '');
        $i = $edit !== '' ? vd_find_work($works, $edit) : -1;
        if ($i >= 0) { vd_render_work_form($works[$i], $hid); return; }
        echo '<div class="row between"><p class="muted">Порядок здесь = порядок на сайте. Скрытые работы на сайте не видны.</p><form method="post">' . $hid('work_new') . '<button>+ Добавить работу</button></form></div>';
        foreach ($works as $n => $w) {
            $idA = '<input type="hidden" name="id" value="' . vd_h($w['id']) . '">';
            echo '<article class="card work' . ($w['hidden'] ? ' is-hidden' : '') . '" id="' . vd_h($w['id']) . '">' . vd_thumb($w['img'], 'cover') .
                 '<div class="work__info"><b>' . vd_h($w['t']['ru']) . '</b><span class="muted">' . vd_h(VD_CATS[$w['c']] ?? $w['c']) . ' · ' . count($w['media']) . ' в галерее' . ($w['hidden'] ? ' · <span class="warn">скрыта</span>' : '') . '</span>' .
                 '<div class="work__acts"><a class="btnlink" href="?tab=works&amp;edit=' . urlencode($w['id']) . '">Редактировать</a>' .
                 '<form method="post" class="inline">' . $hid('work_move') . $idA . '<button name="dir" value="-1" class="ghost" ' . ($n === 0 ? 'disabled' : '') . ' title="Выше">↑</button><button name="dir" value="1" class="ghost" ' . ($n === count($works) - 1 ? 'disabled' : '') . ' title="Ниже">↓</button></form>' .
                 '<form method="post" class="inline">' . $hid('work_toggle') . $idA . '<button class="ghost">' . ($w['hidden'] ? 'Показать' : 'Скрыть') . '</button></form>' .
                 '<form method="post" class="inline">' . $hid('work_delete') . $idA . '<button class="ghost danger" data-confirm="Удалить работу «' . vd_h($w['t']['ru']) . '» из портфолио? Файлы, загруженные через админку, тоже удалятся.">Удалить</button></form></div></div></article>';
        }
        if (is_array($C['works'])) echo '<form method="post" class="reset">' . $hid('works_reset') . '<button class="ghost danger" data-confirm="Вернуть портфолио к исходному виду? Все добавленные и изменённые работы пропадут.">Вернуть портфолио к исходному</button></form>';
        return;
    }
    if ($tab === 'texts') {
        $D = vd_defaults()['texts'];
        $secs = array_values(array_unique(array_column($D, 'sec')));
        $sec = in_array($_GET['sec'] ?? '', $secs, true) ? $_GET['sec'] : $secs[0];
        echo '<div class="chips">'; foreach ($secs as $s) echo '<a href="?tab=texts&amp;sec=' . urlencode($s) . '" class="' . ($s === $sec ? 'on' : '') . '">' . vd_h($s) . '</a>'; echo '</div>';
        echo '<p class="muted">Слева русский текст, справа английский. Пустое поле = исходный текст. Перенос строки — &lt;br&gt;, жирный — &lt;b&gt;…&lt;/b&gt;.</p>';
        echo '<form method="post" class="card">' . $hid('texts_save', '<input type="hidden" name="sec" value="' . vd_h($sec) . '">');
        foreach ($D as $t) {
            if ($t['sec'] !== $sec) continue;
            $ru = $C['texts']['ru'][$t['k']] ?? $t['ru']; $en = $C['texts']['en'][$t['k']] ?? $t['en'];
            $changed = isset($C['texts']['ru'][$t['k']]) || isset($C['texts']['en'][$t['k']]);
            $rows = max(1, min(6, (int)ceil(mb_strlen(strip_tags($t['ru'])) / 60)));
            echo '<div class="tx' . ($changed ? ' is-changed' : '') . '"><textarea name="tx[ru][' . vd_h($t['k']) . ']" rows="' . $rows . '" placeholder="' . vd_h(strip_tags($t['ru'])) . '">' . vd_h($ru) . '</textarea><textarea name="tx[en][' . vd_h($t['k']) . ']" rows="' . $rows . '" placeholder="' . vd_h(strip_tags($t['en'])) . '">' . vd_h($en) . '</textarea></div>';
        }
        if ($sec === 'Этапы работы') {
            echo '<h2 style="margin-top:22px">Шаги слайдера</h2><p class="muted">Название, описание и «на выходе». Чтобы убрать шаг — отметьте «удалить». Последняя пустая строка — для нового шага.</p>';
            $steps = vd_steps($C); $steps[] = ['t' => ['ru' => '', 'en' => ''], 'd' => ['ru' => '', 'en' => ''], 'o' => ['ru' => '', 'en' => '']];
            foreach ($steps as $n => $s) {
                echo '<fieldset class="step"><legend>' . ($s['t']['ru'] === '' ? 'Новый шаг' : 'Шаг ' . ($n + 1)) . ($s['t']['ru'] !== '' ? ' <label class="del"><input type="checkbox" name="st[' . $n . '][del]" value="1"> удалить</label>' : '') . '</legend>';
                foreach (['t' => 'Название', 'd' => 'Описание', 'o' => 'На выходе'] as $f => $lab)
                    echo '<div class="tx"><textarea name="st[' . $n . '][' . $f . '_ru]" rows="' . ($f === 'd' ? 2 : 1) . '" placeholder="' . $lab . '">' . vd_h($s[$f]['ru']) . '</textarea><textarea name="st[' . $n . '][' . $f . '_en]" rows="' . ($f === 'd' ? 2 : 1) . '" placeholder="' . $lab . ' (EN)">' . vd_h($s[$f]['en']) . '</textarea></div>';
                echo '</fieldset>';
            }
        }
        echo '<button>Сохранить раздел</button></form>';
        return;
    }
    if ($tab === 'site') {
        $ct = is_array($C['contacts']) ? $C['contacts'] : array_map(fn($x) => $x[1], VD_CONTACTS);
        echo '<section class="card"><h2>Контакты</h2><p class="muted">Используются в блоке контактов и в подвале. Пустое поле — пункт скрывается.</p><form method="post">' . $hid('contacts_save');
        foreach (VD_CONTACTS as $k => [$lab, $def]) echo '<label>' . vd_h($lab) . '<input name="ct[' . $k . ']" value="' . vd_h($ct[$k] ?? '') . '" placeholder="' . vd_h($def) . '"></label>';
        echo '<button>Сохранить контакты</button></form></section>';
        echo '<section class="card"><h2>Картинки и видео на страницах</h2><p class="muted">' . vd_h(vd_limits()) . '</p>';
        foreach (VD_MEDIA as $k => [$lab, $def, $kind]) {
            $cur = $C['media'][$k] ?? $def;
            echo '<div class="slot" id="m-' . $k . '">' . vd_thumb($cur, 'cover') . '<div><b>' . vd_h($lab) . '</b><br><span class="muted">' . (isset($C['media'][$k]) ? 'заменено' : 'исходное') . '</span>' .
                 '<form method="post" enctype="multipart/form-data" class="row">' . $hid('media_save', '<input type="hidden" name="key" value="' . $k . '">') .
                 '<input type="file" name="file" accept="' . ($kind === 'img' ? 'image/*' : 'video/mp4,video/webm,video/quicktime') . '"><button>Заменить</button>' .
                 (isset($C['media'][$k]) ? '<button name="reset" value="1" class="ghost">Вернуть исходное</button>' : '') . '</form></div></div>';
        }
        echo '</section>';
    }
}

function vd_render_work_form(array $w, callable $hid): void {
    $L = fn($name, $label, $val, $rows = 1) => '<div class="tx"><label>' . $label . '<textarea name="' . $name . '_ru" rows="' . $rows . '">' . vd_h($val['ru'] ?? '') . '</textarea></label><label>' . $label . ' (EN)<textarea name="' . $name . '_en" rows="' . $rows . '">' . vd_h($val['en'] ?? '') . '</textarea></label></div>';
    echo '<p><a href="?tab=works">← Все работы</a></p><form method="post" enctype="multipart/form-data" class="card" id="wf">' . $hid('work_save', '<input type="hidden" name="id" value="' . vd_h($w['id']) . '">');
    echo '<h1>' . vd_h($w['t']['ru']) . ($w['hidden'] ? ' <span class="warn small">скрыта</span>' : '') . '</h1>';
    echo $L('t', 'Название', $w['t']);
    echo '<div class="tx"><label>Направление<select name="c">'; foreach (VD_CATS as $k => $v) echo '<option value="' . $k . '"' . ($w['c'] === $k ? ' selected' : '') . '>' . $v . '</option>'; echo '</select></label>';
    echo '<label>Кадрирование превью <small class="muted">(необязательно, например <code>center 30%</code>)</small><input name="pos" value="' . vd_h($w['pos']) . '"></label></div>';
    echo $L('task', 'Задача', $w['task'], 2) . $L('res', 'Результат', $w['res'], 2) . $L('fmt', 'Формат', $w['fmt']);
    echo '<h2>Превью и петля</h2><p class="muted">' . vd_h(vd_limits()) . '</p><div class="tx">';
    echo '<div class="slot">' . vd_thumb($w['img'], 'cover') . '<label>Превью в сетке (картинка 4:5)<input type="file" name="img" accept="image/*"></label></div>';
    echo '<div class="slot">' . vd_thumb($w['loop'], 'cover') . '<label>Короткая петля при наведении (MP4 без звука, необязательно)<input type="file" name="loop" accept="video/mp4,video/webm"></label>' . ($w['loop'] ? '<label class="del"><input type="checkbox" name="loop_remove" value="1"> убрать петлю</label>' : '') . '</div></div>';
    echo '<h2>Галерея кейса</h2><p class="muted">Картинки и видео в том порядке, как они идут в открытом кейсе. Стрелками меняется порядок.</p><ul class="gallery" id="gal">';
    foreach ($w['media'] as $m) {
        if (is_array($m)) { echo '<li class="muted">Группа из ' . count($m['grid'] ?? []) . ' картинок (из исходной вёрстки)</li>'; continue; }
        echo '<li><input type="hidden" name="media[]" value="' . vd_h($m) . '">' . vd_thumb($m) . '<span class="fname">' . vd_h(basename(preg_replace('/^v:/', '', $m))) . '</span>' .
             '<button type="button" class="ghost" data-move="-1">↑</button><button type="button" class="ghost" data-move="1">↓</button><label class="del"><input type="checkbox" name="media_del[]" value="' . vd_h($m) . '"> удалить</label></li>';
    }
    echo '</ul><label>Добавить в галерею (можно несколько файлов)<input type="file" name="media_add[]" accept="image/*,video/mp4,video/webm,video/quicktime" multiple></label>';
    echo '<button>Сохранить работу</button></form>';
}
