<?php
// Cron job: retries leads that did not reach Telegram. Run from the hosting scheduler every 5 minutes:
//   /opt/php/8.3/bin/php ~/www/vasilichdesign.art/lib/cron.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require __DIR__ . '/vd.php';
echo vd_retry_pending(10), " retried\n";
