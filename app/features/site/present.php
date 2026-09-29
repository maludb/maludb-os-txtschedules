<?php
declare(strict_types=1);

/**
 * How the settings, day-parts and rules screens are worded and what their JSON carries (whitelist presenters — never a raw row). No wage or cost lives on these screens: a restaurant's
 * settings hold an overtime threshold and a multiplier, never a person's rate.
 */

function site_url(int $siteId, array $extra = []): string
{
    return '/site/?' . http_build_query(['site' => $siteId] + array_filter($extra, static fn ($v) => $v !== null && $v !== ''));
}

function day_parts_url(int $siteId, array $extra = []): string
{
    return '/site/day-parts?' . http_build_query(['site' => $siteId] + array_filter($extra, static fn ($v) => $v !== null && $v !== ''));
}

const WEEKDAYS = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

/** The banner a settings or day-part action lands with (?notice=), by key — a whitelist, never request text. */
function site_notice(?string $key): ?array
{
    return match ($key) {
        'st_saved' => ['success', 'The settings are saved. They apply from the next thing anyone does.'],
        'st_unchanged' => ['secondary', 'Nothing changed.'],
        'dp_saved' => ['success', 'The day-part is saved.'],
        'dp_archived' => ['secondary', 'The day-part is archived. Its old forecasts are kept.'],
        'rl_saved' => ['success', 'The rule is saved.'],
        'rl_unchanged' => ['secondary', 'Nothing changed.'],
        'rl_preset' => ['success', 'The rules are back to the starting values.'],
        default => null,
    };
}

/** The settings as JSON: exactly the fields the screen shows, typed. */
function present_site_settings(array $s): array
{
    $out = [];
    foreach (SETTINGS_FIELDS as $k => $_) {
        $out[$k] = $s[$k];
    }
    return $out + ['rule_preset' => $s['rule_preset'] ?? null, 'sentence' => trade_sentence($s), 'day_hours_example' => day_hours_sentence((float) $s['time_off_day_hours'])];
}

function present_day_part(array $d): array
{
    return ['day_part_id' => (int) $d['day_part_id'], 'name' => $d['name'], 'key' => $d['key'] ?? null, 'starts_at' => substr((string) $d['starts_at'], 0, 5), 'ends_at' => substr((string) $d['ends_at'], 0, 5),
            'service_name' => $d['service_name'], 'sort_order' => (int) ($d['sort_order'] ?? 0), 'archived' => (bool) ($d['archived'] ?? false)];
}
