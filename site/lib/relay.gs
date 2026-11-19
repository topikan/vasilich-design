// Реле заявок в Telegram для vasilichdesign.art (Google Apps Script).
// 1) script.google.com → «Новый проект» → вставить этот код целиком вместо содержимого.
// 2) В строке SECRET вписать секрет из админки сайта (Настройки → «Если Telegram не доходит»).
// 3) «Начать развёртывание» → «Новое развёртывание» → тип «Веб-приложение»:
//    «Выполнять от имени» — Я, «У кого есть доступ» — Все. Разрешить доступ.
// 4) Скопировать «URL веб-приложения» (…/exec) и вставить в админке сайта.
const SECRET = 'ВСТАВЬТЕ_СЕКРЕТ_ИЗ_АДМИНКИ';

function doPost(e) {
  const out = o => ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON);
  let req;
  try { req = JSON.parse(e.postData.contents); } catch (_) { return out({ ok: false, description: 'bad json' }); }
  if (!req || req.secret !== SECRET) return out({ ok: false, description: 'реле: неверный секрет' });
  if (!/^\d+:[\w-]+$/.test(req.token || '') || !/^(sendMessage|sendDocument|getUpdates|getMe|deleteWebhook)$/.test(req.method || ''))
    return out({ ok: false, description: 'реле: запрос не разрешён' });
  const payload = Object.assign({}, req.params || {});
  if (req.file && req.file.b64) {
    payload[req.file.field || 'document'] = Utilities.newBlob(Utilities.base64Decode(req.file.b64), req.file.type || 'application/octet-stream', req.file.name || 'file');
  }
  const r = UrlFetchApp.fetch('https://api.telegram.org/bot' + req.token + '/' + req.method, { method: 'post', payload: payload, muteHttpExceptions: true });
  return ContentService.createTextOutput(r.getContentText()).setMimeType(ContentService.MimeType.JSON);
}
