<?php
declare(strict_types=1);

/**
 * How the week builder is worded and what its JSON answer carries (whitelist presenters — never a raw row). No wage anywhere; a cost only where the
 * caller holds labor.view and only as the day's or the week's total. Times are the restaurant's zone.
 */

/** The builder's URL for a restaurant's week (the canonical form every link and every landing uses). */
function builder_url(int $siteId, string $weekStart, array $extra = []): string
{
    return '/builder?' . http_build_query(['site' => $siteId, 'week' => $weekStart] + array_filter($extra, static fn ($v) => $v !== null && $v !== ''));
}

/** The week's seven days: [date, "Mon", "5", isToday]. */
function week_days(string $weekStart, string $today): array
{
    $out = [];
    $d0 = new DateTimeImmutable($weekStart, new DateTimeZone('UTC'));
    for ($i = 0; $i < 7; $i++) {
        $d = $d0->modify("+$i days");
        $out[] = [$d->format('Y-m-d'), $d->format('D'), $d->format('j'), $d->format('Y-m-d') === $today];
    }
    return $out;
}

/** "Oct 5 – Oct 11". */
function week_label(string $weekStart): string
{
    $d = new DateTimeImmutable($weekStart, new DateTimeZone('UTC'));
    return $d->format('M j') . ' – ' . $d->modify('+6 days')->format('M j');
}

/** The week's state badge: [label, colour, key]. Draft → secondary; published → success; published and changed after → warning. */
function week_badge(?array $week, array $shifts, string $tz): array
{
    if ($week === null || $week['status'] === 'draft') {
        return ['Draft', 'secondary', 'draft'];
    }
    $when = 'Published ' . format_ts((string) $week['published_at'], $tz, 'M j · g:i a');
    foreach ($shifts as $s) {
        if ($s['changed_after_publish_at'] !== null) {
            return ['Published — changed after', 'warning', 'changed'];
        }
    }
    return [$when, 'success', 'published'];
}

/** A flat bar drawn as SVG (no style attribute): $pct 0–100+, class primary | warning | danger. */
function svg_bar(float $pct, string $kind = 'primary', int $height = 6): string
{
    $w = max(0.0, min(100.0, $pct));
    return '<svg class="bar-svg" width="100%" height="' . $height . '" viewBox="0 0 100 ' . $height . '" preserveAspectRatio="none" role="img" aria-label="' . e(round($pct)) . ' percent">'
        . '<rect class="bar-track" width="100" height="' . $height . '"/><rect class="bar-fill bar-' . e($kind) . '" width="' . e(round($w, 1)) . '" height="' . $height . '"/></svg>';
}

/** A person's hours against their limit and the overtime threshold: [label, bar HTML, kind]. */
function hours_bar(?array $h, float $threshold = 40.0): array
{
    $hours = $h === null ? 0.0 : (float) $h['scheduled_hours'];
    $limit = $h !== null && $h['max_hours_week'] !== null ? min((float) $h['max_hours_week'], (float) $h['overtime_weekly_hours']) : (float) ($h['overtime_weekly_hours'] ?? $threshold);
    $limit = $limit > 0 ? $limit : $threshold;
    $pct = $hours / $limit * 100;
    $kind = $pct > 100 ? 'danger' : ($pct >= 90 ? 'warning' : 'primary');
    return [days_label($hours) . ' / ' . days_label($limit) . ' h', svg_bar($pct, $kind), $kind];
}

/** Warnings grouped by shift: shift_id => [message, …] (soft and hard alike; a hard one is never in a saved shift). */
function warnings_by_shift(array $rows): array
{
    $out = [];
    foreach ($rows as $w) {
        $out[(int) $w['shift_id']][] = $w['message'];
    }
    return $out;
}

/** The local date (Y-m-d) a shift starts on. */
function shift_day(array $s): string
{
    return local_dt((string) $s['starts_at'], (string) $s['timezone'])->format('Y-m-d');
}

/** One shift for the builder's JSON: the times (UTC and in words), position, holder, status, warnings. No cost. */
function present_builder_shift(array $s, array $warnings = []): array
{
    $tz = (string) $s['timezone'];
    return ['shift_id' => (int) $s['shift_id'], 'position' => ['id' => (int) $s['position_id'], 'name' => $s['position_name'], 'color' => $s['position_color']],
            'starts_at' => json_ts((string) $s['starts_at']), 'ends_at' => json_ts((string) $s['ends_at']), 'day' => shift_day($s),
            'when' => shift_when((string) $s['starts_at'], (string) $s['ends_at'], $tz, true), 'paid_hours' => (float) $s['paid_hours'], 'break_minutes' => (int) $s['break_minutes'],
            'holder' => $s['assignee_member_id'] === null ? null : ['member_id' => (int) $s['assignee_member_id'], 'name' => $s['assignee_name']],
            'is_open' => (bool) $s['is_open'], 'status' => $s['status'], 'note' => $s['note'], 'published' => $s['published_at'] !== null,
            'changed_after_publish' => $s['changed_after_publish_at'] !== null, 'warnings' => $warnings];
}

/** The staffing strip's rows grouped by date: date => [[day_part, position, expected, recommended, scheduled, open, gap]]. */
function needs_by_day(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        if ((int) $r['recommended'] === 0 && (int) $r['scheduled'] === 0 && (int) $r['open_shifts'] === 0) {
            continue;
        }
        $out[$r['on_date']][] = ['day_part' => $r['day_part'], 'position' => $r['position_name'], 'expected' => $r['expected_covers'] === null ? null : (int) $r['expected_covers'],
                                 'recommended' => (int) $r['recommended'], 'scheduled' => (int) $r['scheduled'], 'open' => (int) $r['open_shifts'],
                                 'gap' => (int) $r['recommended'] - (int) $r['scheduled']];
    }
    return $out;
}

/** The outcome of a week action (copy, template, auto-fill, publish) kept for the builder's next view: shown once, in words. */
function remember_result(array $result): void
{
    $_SESSION['build_result'] = $result;
}

function take_result(int $siteId, string $weekStart): ?array
{
    $r = $_SESSION['build_result'] ?? null;
    if ($r === null || (int) ($r['site_id'] ?? 0) !== $siteId || ($r['week_start'] ?? '') !== $weekStart) {
        return null;
    }
    unset($_SESSION['build_result']);
    return $r;
}
