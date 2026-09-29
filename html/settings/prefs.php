<?php
declare(strict_types=1);
/**
 * Action `prefs_save` (log `prefs.save`): a person's own notification choices — by_email and by_sms (yes/no), kinds[] (the events to be told about), reminder_minutes (0–2880, or empty for the
 * restaurant's). A field left out stays as it was. Both channels off is allowed: that person is told nothing. Always about oneself.
 */
require_once dirname(__DIR__, 2) . '/app/features/announcements/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$cur = find_prefs($pdo, $me);
$f = [];
foreach (['by_email', 'by_sms'] as $k) {
    if (req_has($k)) {
        $f[$k] = people_yes($k);
    }
}
$kinds = request_list('kinds');
if ($kinds !== null) {
    $bad = array_diff($kinds, array_keys(NOTICE_KINDS));
    if ($bad !== []) {
        refuse(422, 'Unknown event: ' . implode(', ', array_map(static fn (string $k): string => mb_substr($k, 0, 30), $bad)) . '.');
    }
    $f['kinds'] = array_values(array_unique($kinds));
}
if (req_has('reminder_minutes')) {
    $v = trim((string) req_val('reminder_minutes'));
    if ($v === '') {
        $f['reminder_minutes'] = null;
    } elseif (!ctype_digit($v) || (int) $v > 2880) {
        refuse(422, 'Remind me before a shift: from 0 to 2,880 minutes (48 hours), or leave it to the restaurant.');
    } else {
        $f['reminder_minutes'] = (int) $v;
    }
}
$r = people_guard($pdo, static function () use ($pdo, $me, $f): array {
    $pdo->beginTransaction();
    $r = save_prefs($pdo, $me, $f);
    log_activity($pdo, 'prefs.save', 'notification_prefs', $me, ['before' => $r['before'], 'after' => $r['after']]);
    $pdo->commit();
    return $r;
});
people_done('Saved how you are told', $me, people_land(return_path('/settings/'), 'pf_saved', 'prefs'), 'prefsChanged', ['by_email' => $r['after']['by_email'], 'by_sms' => $r['after']['by_sms']]);
