<?php
declare(strict_types=1);

/**
 * How the forecast and budget screens are worded and what their JSON carries (whitelist presenters — never a raw row). A COST or a budget amount is passed to a view or to JSON only by a
 * screen that asked has_right('labor.view'); nothing here prints a wage rate (there is none in this slice's reads).
 */

const COVER_SOURCES = ['manual' => 'Typed', 'copied' => 'Copied', 'reservations' => 'Reservations'];

function forecast_url(int $siteId, string $weekStart, array $extra = []): string
{
    return '/forecast?' . http_build_query(['site' => $siteId, 'week' => $weekStart] + array_filter($extra, static fn ($v) => $v !== null && $v !== ''));
}

function budget_url(int $siteId, string $weekStart, array $extra = []): string
{
    return '/budget?' . http_build_query(['site' => $siteId, 'week' => $weekStart] + array_filter($extra, static fn ($v) => $v !== null && $v !== ''));
}

/** "$1,234.50" — the restaurant's currency symbol when it has one. */
function money_label($amount, string $currency = 'USD'): string
{
    $sym = ['USD' => '$', 'CAD' => 'CA$', 'EUR' => '€', 'GBP' => '£', 'AUD' => 'A$', 'MXN' => 'MX$'][$currency] ?? '';
    return ($sym !== '' ? $sym : $currency . ' ') . number_format((float) $amount, 2);
}

/** "11 am–3 pm" from two HH:MM(:SS) texts. */
function day_part_hours(string $from, string $to): string
{
    $f = static fn (string $t): string => ltrim(date('g:i a', (int) strtotime('2000-01-01 ' . $t)), '0');
    return str_replace(':00', '', $f($from)) . '–' . str_replace(':00', '', $f($to));
}

/** The banner a slice-5 action lands with (?notice=), by key — a whitelist, never request text. */
function labor_notice(?string $key): ?array
{
    return match ($key) {
        'fc_saved' => ['success', 'The forecast is saved.'],
        'fc_unchanged' => ['secondary', 'Nothing changed.'],
        'fc_copied' => ['success', 'Copied — typed covers from that week are in this one.'],
        'fc_filled' => ['success', 'Filled from Reservations\' booked covers.'],
        'fc_fill_refused' => ['warning', 'Reservations could not be asked — see below. The forecast you type stands.'],
        'rt_saved' => ['success', 'The staffing ratio is saved.'],
        'bd_saved' => ['success', 'The budget is saved.'],
        'bd_cleared' => ['secondary', 'The budget is removed.'],
        default => null,
    };
}

/** The outcome of a fill, kept for the forecast's next view (shown once, in words). */
function remember_labor_result(array $result): void
{
    $_SESSION['labor_result'] = $result;
}

function take_labor_result(int $siteId, string $weekStart): ?array
{
    $r = $_SESSION['labor_result'] ?? null;
    if ($r === null || (int) ($r['site_id'] ?? 0) !== $siteId || ($r['week_start'] ?? '') !== $weekStart) {
        return null;
    }
    unset($_SESSION['labor_result']);
    return $r;
}

/** The sentences a fill's result says: [[kind, text]] — what was written, what was left, what could not be placed, or why Reservations could not be asked. */
function fill_result_lines(array $r): array
{
    $lines = [];
    if (($r['refusal'] ?? null) !== null) {
        return [['warning', reservation_refusal_words((string) $r['refusal'])]];
    }
    $lines[] = ['success', ($r['written'] === 0 ? 'No cell changed' : 'Filled ' . $r['written'] . ' cell' . ($r['written'] === 1 ? '' : 's')) . ' from Reservations\' booked covers'
        . ($r['unchanged'] > 0 ? ' (' . $r['unchanged'] . ' already the same)' : '') . '. These are booked covers, not walk-ins — type over one to change it.'];
    if ($r['skipped'] > 0) {
        $lines[] = ['info', $r['skipped'] . ' cell' . ($r['skipped'] === 1 ? '' : 's') . ' someone typed ' . ($r['skipped'] === 1 ? 'was' : 'were') . ' left alone.'];
    }
    foreach ($r['unmapped'] as $u) {
        $lines[] = ['warning', $u['service'] . ' — ' . $u['covers'] . ' covers not mapped — set a service name on a day-part.'];
    }
    return $lines;
}

/**
 * The staffing needs grouped for the screens: only positions that HAVE a ratio (covers per person or a minimum). Rows keyed 'dayPartId:positionId' =>
 * [day_part, position, cells => [date => [expected, recommended, scheduled, open, gap]]], and by date for the phone.
 */
function needs_rows(array $needs, array $ratios): array
{
    $has = [];
    foreach ($ratios as $r) {
        if ($r['covers_per_staff'] !== null || $r['min_staff'] > 0) {
            $has[$r['position_id']] = true;
        }
    }
    $rows = [];
    foreach ($needs as $n) {
        if (!isset($has[$n['position_id']])) {
            continue;
        }
        $k = $n['day_part_id'] . ':' . $n['position_id'];
        $rows[$k]['day_part'] = $n['day_part'];
        $rows[$k]['position'] = $n['position_name'];
        $rows[$k]['cells'][$n['on_date']] = ['expected' => $n['expected_covers'], 'recommended' => $n['recommended'], 'scheduled' => $n['scheduled'], 'open' => $n['open_shifts'],
                                             'gap' => $n['recommended'] - $n['scheduled']];
    }
    return $rows;
}

/** One need cell in words: "3 of 4 — 1 short", "4 of 4", "5 of 4 (+1)", plus "· 2 open"; [text, class]. */
function need_cell_words(array $c): array
{
    $t = $c['scheduled'] . ' of ' . $c['recommended'];
    $class = 'text-body';
    if ($c['gap'] > 0) {
        $t .= ' — ' . $c['gap'] . ' short';
        $class = 'text-danger fw-semibold';
    } elseif ($c['gap'] < 0) {
        $t .= ' (+' . (-$c['gap']) . ')';
        $class = 'text-muted';
    }
    if ($c['open'] > 0) {
        $t .= ' · ' . $c['open'] . ' open';
    }
    return [$t, $class];
}

/** The forecast screen's JSON: whitelist. */
function present_forecast(int $siteId, string $ws, array $forecast, array $ratios, array $needs, array $may, bool $reservations): array
{
    $cells = [];
    foreach ($forecast['cells'] as $dp => $days) {
        foreach ($days as $date => $c) {
            $cells[] = ['date' => $date, 'day_part_id' => $dp, 'expected_covers' => $c['covers'], 'source' => $c['source']];
        }
    }
    return ['site_id' => $siteId, 'week_start' => $ws,
        'day_parts' => array_map(static fn (array $d): array => ['day_part_id' => $d['day_part_id'], 'name' => $d['name'], 'service_name' => $d['service_name'], 'starts_at' => substr($d['starts_at'], 0, 5), 'ends_at' => substr($d['ends_at'], 0, 5)], $forecast['day_parts']),
        'cells' => $cells,
        'ratios' => array_map(static fn (array $r): array => ['position_id' => $r['position_id'], 'position' => $r['name'], 'covers_per_staff' => $r['covers_per_staff'], 'min_staff' => $r['min_staff']], $ratios),
        'needs' => array_map(static fn (array $n): array => ['date' => $n['on_date'], 'day_part_id' => $n['day_part_id'], 'day_part' => $n['day_part'], 'position_id' => $n['position_id'], 'position' => $n['position_name'],
            'expected_covers' => $n['expected_covers'], 'recommended' => $n['recommended'], 'scheduled' => $n['scheduled'], 'open_shifts' => $n['open_shifts'], 'gap' => $n['recommended'] - $n['scheduled']],
            array_values(array_filter($needs, static function (array $n) use ($ratios): bool { foreach ($ratios as $r) { if ($r['position_id'] === $n['position_id']) { return $r['covers_per_staff'] !== null || $r['min_staff'] > 0; } } return false; }))),
        'reservations' => ['available' => $reservations], 'may' => $may];
}

/** The budget screen's JSON (labor.view only — the caller has asked). */
function present_budget(int $siteId, string $ws, array $areas, array $byDay, string $currency, array $may): array
{
    $f = static fn (?float $v): ?float => $v === null ? null : round($v, 2);
    return ['site_id' => $siteId, 'week_start' => $ws, 'currency' => $currency,
        'areas' => array_values(array_map(static fn (array $a): array => ['area' => $a['area'], 'scheduled_hours' => $f($a['scheduled_hours']), 'scheduled_cost' => $f($a['scheduled_cost']),
            'budget_hours' => $f($a['budget_hours']), 'budget_amount' => $f($a['budget_amount'])], $areas)),
        'by_day' => array_map(static fn (string $d, array $r): array => ['date' => $d, 'scheduled_hours' => $r['hours'], 'scheduled_cost' => $r['cost'], 'shifts' => $r['shifts'], 'open_shifts' => $r['open']], array_keys($byDay), array_values($byDay)),
        'note' => 'Cost is paid hours times the effective rate (the person\'s own, else the position\'s default). An open shift costs nothing until someone holds it. Overtime multipliers are not applied.',
        'may' => $may];
}
