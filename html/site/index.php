<?php
declare(strict_types=1);
/**
 * /site/?site= — the restaurant's settings (screen `site-settings`; settings.manage at the restaurant): how shifts change hands (D2) with a sentence that follows what is typed, the week, reminders, the hours a day
 * of time off (D13) with its live example, overtime. One form, saved whole by save.php. `?preview=1` answers only the sentence and `?preview=day` only the example line — the form asks as you type (nothing saved).
 */
require_once dirname(__DIR__, 2) . '/app/features/site/handler.php';
require_login();
require_human();
$pdo = db();
$siteId = request_integer('site') ?? (int) current_site_id();
require_right('settings.manage', $siteId);
$site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
$s = find_site_settings($pdo, $siteId) ?? refuse(404, 'Not found.');
$preview = request_string('preview');
if ($preview !== '') {
    $raw = [];
    foreach (array_keys(SETTINGS_FIELDS) as $k) {
        if (array_key_exists($k, $_GET)) {
            $raw[$k] = (string) $_GET[$k];
        }
    }
    try {
        $typed = parse_site_settings($raw, $s);
    } catch (DomainException $e) {
        echo '<span class="text-warning" id="site-settings-preview-error"><i class="feather-alert-triangle me-1"></i>' . e($e->getMessage()) . '</span>';
        exit;
    }
    echo $preview === 'day' ? e(day_hours_sentence((float) $typed['time_off_day_hours'])) : view('site/partials/sentence.php', ['s' => $typed]);
    exit;
}
log_screen_view($pdo, 'site-settings');
if (wants_json()) {
    respond_screen(['site_id' => $siteId, 'site' => $site['name'], 'timezone' => $site['timezone'], 'settings' => present_site_settings($s)]);
}
render_screen('Restaurant settings', view('site/settings.php', ['site' => $site, 's' => $s, 'notice' => site_notice($_GET['notice'] ?? null),
    'sites' => array_values(array_filter(held_sites(), static fn (array $x): bool => has_right('settings.manage', $x['scope_id'])))]),
    ['activeNav' => 'site-settings', 'screen' => 'site-settings', 'entity' => 'site_settings', 'recordId' => (string) $siteId]);
