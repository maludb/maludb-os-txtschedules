<?php
declare(strict_types=1);
/**
 * /settings/?tab=notify|calendar — the person's own settings (screen `settings`; schedule.view_own): HOW I AM TOLD (email, text, which events, how long before a shift) and MY CALENDAR LINK (a private URL,
 * shown once when made). Texts go to the phone verified in the operating system — this application never sees it; what the kernel last said about it is shown in words.
 */
require_once dirname(__DIR__, 2) . '/app/features/announcements/handler.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
require_right('schedule.view_own');
$tab = request_string('tab') === 'calendar' ? 'calendar' : 'notify';
$prefs = find_prefs($pdo, $me);
$default = default_reminder_minutes($pdo, $me);
$refusal = last_text_refusal($pdo, $me);
$feed = find_feed($pdo, $me);
$once = null;
if (!empty($_SESSION['calendar_link_once'])) {
    $once = app_url('/api/v1/calendar/' . $_SESSION['calendar_link_once'] . '.ics');
    unset($_SESSION['calendar_link_once']);                       // shown once — a reload does not show it again
}
log_screen_view($pdo, 'settings');
if (wants_json()) {
    respond_screen(['notify' => ['by_email' => $prefs['by_email'], 'by_sms' => $prefs['by_sms'], 'kinds' => $prefs['kinds'], 'reminder_minutes' => $prefs['reminder_minutes'], 'restaurant_default_minutes' => $default,
        'text_note' => $refusal], 'calendar' => ['has_link' => $feed !== null, 'made' => $feed === null ? null : json_ts($feed['made'])]]);
}
render_screen('My settings', view('settings/index.php', ['tab' => $tab, 'prefs' => $prefs, 'default' => $default, 'refusal' => $refusal, 'feed' => $feed, 'once' => $once,
    'osChannels' => rtrim((string) env('OS_LAUNCHER_URL', '/'), '/') . '/settings/channels', 'notice' => notify_notice($_GET['notice'] ?? null)]),
    ['activeNav' => 'settings', 'screen' => 'settings', 'entity' => 'settings']);
