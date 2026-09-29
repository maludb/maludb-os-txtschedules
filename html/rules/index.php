<?php
declare(strict_types=1);
/**
 * /rules/?site= — the rules the restaurant runs (screen `rules`): a card a rule with its sentence, its severity (hard, soft, off) and its values; the Generic preset; the last 30 days of overrides. Whoever builds the schedule
 * here may read; changing needs settings.manage (the forms show only to those who hold it). Staff have no business here: 403.
 */
require_once dirname(__DIR__, 2) . '/app/features/site/handler.php';
require_login();
require_human();
$pdo = db();
$siteId = request_integer('site') ?? (int) current_site_id();
require_site($siteId);
$may = has_right('settings.manage', $siteId);
if (!$may) {
    require_right('schedule.build', $siteId);
}
$site = find_site_row($pdo, $siteId) ?? refuse(404, 'Not found.');
$rules = find_site_rules($pdo, $siteId);
$overrides = has_right('schedule.build', $siteId) ? recent_overrides($pdo, $siteId, 30) : [];
$settings = find_site_settings($pdo, $siteId);
$presetName = (string) ($pdo->query('SELECT name FROM rule_presets WHERE key = ' . $pdo->quote((string) ($settings['rule_preset'] ?? 'generic')))->fetchColumn() ?: 'Generic');
$certs = has_right('schedule.build', $siteId) ? find_certification_kinds($pdo, $siteId) : [];
log_screen_view($pdo, 'rules');
if (wants_json()) {
    respond_screen(['site_id' => $siteId, 'site' => $site['name'], 'preset' => $settings['rule_preset'] ?? null, 'disclaimer' => RULES_DISCLAIMER, 'can_change' => $may,
        'rules' => array_map('present_rule', $rules), 'overrides' => array_map(static fn (array $o): array => present_override($o, (string) $site['timezone']), $overrides)]);
}
render_screen('Rules', view('rules/list.php', ['site' => $site, 'rules' => $rules, 'overrides' => $overrides, 'may' => $may, 'notice' => site_notice($_GET['notice'] ?? null), 'tz' => (string) $site['timezone'], 'preset' => $presetName, 'certs' => $certs,
    'sites' => array_values(array_filter(held_sites(), static fn (array $x): bool => has_right('settings.manage', $x['scope_id']) || has_right('schedule.build', $x['scope_id'])))]),
    ['activeNav' => 'rules', 'screen' => 'rules', 'entity' => 'site_rules', 'recordId' => (string) $siteId]);
