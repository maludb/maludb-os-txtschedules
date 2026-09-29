<?php
declare(strict_types=1);
/**
 * Action `site_settings_save` (log `settings.update`): settings.manage at the restaurant saves its settings whole — the trade settings (D2), week start, currency, availability approval, reminders,
 * the hours a day of time off (D13), overtime. A field left out stays; every field is checked in its own words. `before`/`after` carry only the fields that CHANGED. A change applies to the next thing
 * anyone does: an exchange already open keeps the rules it was made under (except the cutoff, read live) and a time-off request keeps the hours it was counted at.
 */
require_once dirname(__DIR__, 2) . '/app/features/site/handler.php';
people_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$site = settings_gate_site();
$raw = [];
foreach (array_keys(SETTINGS_FIELDS) as $k) {
    if (req_has($k)) {
        $raw[$k] = (string) req_val($k);
    }
}
$r = people_guard($pdo, static function () use ($pdo, $me, $site, $raw): array {
    $pdo->beginTransaction();
    $cur = find_site_settings($pdo, $site) ?? throw new DomainException('Not found.');
    $r = save_site_settings($pdo, $site, parse_site_settings($raw, $cur), $me);
    if ($r['changed'] !== []) {
        log_activity($pdo, 'settings.update', 'site_settings', $site, ['scope_id' => $site,
            'before' => array_map(static fn (array $c) => $c['before'], $r['changed']), 'after' => array_map(static fn (array $c) => $c['after'], $r['changed'])]);
    }
    $pdo->commit();
    return $r;
});
$names = array_keys($r['changed']);
people_done($names === [] ? 'Nothing changed' : 'Saved the settings (' . implode(', ', array_map(static fn (string $k): string => str_replace('_', ' ', $k), $names)) . ')', $site,
    people_land(return_path(site_url($site)), $names === [] ? 'st_unchanged' : 'st_saved'), 'settingsChanged', ['changed' => $names]);
