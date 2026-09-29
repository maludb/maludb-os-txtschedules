<?php /** The HTML of a notification email. Data: subject, body (plain text with line breaks and links). */
$html = nl2br(e($body), false);
$html = preg_replace('#(https?://[^\s<]+)#', '<a href="$1">$1</a>', $html);
?>
<!doctype html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#283c50;font-size:15px;line-height:1.5">
<h3 style="margin:0 0 12px"><?= e($subject) ?></h3>
<p style="margin:0 0 16px"><?= $html ?></p>
<p style="color:#748194;font-size:12px;margin:0"><?= e(app_name()) ?> — you can choose how you are told under My settings.</p>
</body></html>
