<?php
declare(strict_types=1);

/**
 * How a shift is worded and what its JSON answer carries (whitelist presenters — never a raw row, never pay).
 * Times are shown in the shift's own site zone; the zone's name is added when the person holds sites in more than one.
 */

function local_dt(string $utc, string $tz): DateTimeImmutable
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz !== '' ? $tz : 'UTC'));
}

/** "5:00–11:00 pm" — the meridiem said once when both ends share it; "11:00 am–3:00 pm"; past midnight "5:00 pm–1:00 am". */
function shift_time_range(string $startUtc, string $endUtc, string $tz): string
{
    $a = local_dt($startUtc, $tz);
    $b = local_dt($endUtc, $tz);
    $same = $a->format('a') === $b->format('a') && $a->format('Y-m-d') === $b->format('Y-m-d');
    return $a->format('g:i') . ($same ? '' : ' ' . $a->format('a')) . '–' . $b->format('g:i a');
}

/** "Fri Oct 9 · 5:00–11:00 pm" (+ " CDT" when $zone). */
function shift_when(string $startUtc, string $endUtc, string $tz, bool $zone = false): string
{
    return local_dt($startUtc, $tz)->format('D M j') . ' · ' . shift_time_range($startUtc, $endUtc, $tz) . ($zone ? ' ' . local_dt($startUtc, $tz)->format('T') : '');
}

/** True when the person holds restaurants in more than one time zone — then every time names its zone (NF-2). */
function show_zone(): bool
{
    return count(array_unique(array_column(held_sites(), 'timezone'))) > 1;
}

function shift_names(array $names, int $show = 2): string
{
    $names = array_values(array_filter($names, static fn ($n) => $n !== null && $n !== ''));
    if ($names === []) {
        return '';
    }
    $head = array_slice($names, 0, $show);
    $rest = count($names) - count($head);
    return implode(', ', $head) . ($rest > 0 ? ' +' . $rest : '');
}

/** The badge a shift card wears for its live exchange: [label, bootstrap colour] or null. From the HOLDER's side. */
function shift_exchange_badge(array $s): ?array
{
    $st = $s['ex_status'] ?? null;
    if ($st === null) {
        return null;
    }
    $kind = $s['ex_kind'] ?? '';
    return match (true) {
        $st === 'open' && $kind === 'coverage' => ['Cover asked', 'info'],
        $st === 'open' => ['Offered', 'primary'],
        $st === 'pending_acceptance' && $kind === 'swap' => ['Swap asked', 'info'],
        $st === 'pending_acceptance' => ['Waiting for ' . ($s['ex_to_name'] ?? 'a colleague'), 'info'],
        $st === 'pending_approval' => ['Waiting for a manager', 'warning'],
        default => null,
    };
}

/** A shift as JSON: ids, times in UTC and the words, the holder, who else is on, the live trade. No cost, no wage, no contact. */
function present_shift(array $s): array
{
    $tz = (string) $s['timezone'];
    return [
        'shift_id' => (int) $s['shift_id'], 'site_id' => (int) $s['site_id'], 'site' => $s['site_name'], 'time_zone' => $tz,
        'position' => ['id' => (int) $s['position_id'], 'name' => $s['position_name'], 'color' => $s['position_color']],
        'starts_at' => json_ts($s['starts_at']), 'ends_at' => json_ts($s['ends_at']),
        'when' => shift_when($s['starts_at'], $s['ends_at'], $tz, true),
        'paid_hours' => (float) $s['paid_hours'], 'break_minutes' => (int) $s['break_minutes'],
        'holder' => $s['assignee_member_id'] === null ? null : ['member_id' => (int) $s['assignee_member_id'], 'name' => $s['assignee_name']],
        'is_open' => (bool) $s['is_open'], 'status' => $s['status'], 'note' => $s['note'],
        'others' => $s['others'] ?? [],
        'exchange' => ($s['exchange_id'] ?? null) === null ? null : ['exchange_id' => (int) $s['exchange_id'], 'kind' => $s['ex_kind'], 'status' => $s['ex_status']],
    ];
}

/** The Monday (or the site's week start) on or before a local date; $weekStart 0 = Sunday … 6 = Saturday. */
function week_start_of(string $date, int $weekStart = 1): string
{
    $d = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    $back = ((int) $d->format('w') - $weekStart + 7) % 7;
    return $d->modify('-' . $back . ' days')->format('Y-m-d');
}

/** A YYYY-MM-DD from the request, or $default when absent or malformed. */
function request_local_date(string $name, string $default): string
{
    $v = request_date($name);
    return is_string($v) ? $v : $default;
}

/** The UTC instant a local date starts in a zone, as 'Y-m-d H:i:sP' text for a query. */
function local_midnight_utc(string $date, string $tz): string
{
    return (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone($tz)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s+00');
}

/** One history row in words: "Priya offered it". Escape at the view. */
function activity_words(array $r): string
{
    $who = $r['actor_name'] ?? 'txtSchedules';
    $a = $r['after'] ?? [];
    switch ($r['action']) {
        case 'exchange.offer':    return $who . ' offered it';
        case 'exchange.give':     return $who . ' offered it to a colleague';
        case 'exchange.swap':     return $who . ' asked a colleague to swap it';
        case 'exchange.open':     return $who . ' put it up as an open shift';
        case 'exchange.coverage': return $who . ' asked ' . (int) ($a['invitees'] ?? 0) . ' people to cover it';
        case 'exchange.claim':
            return match ($a['outcome'] ?? '') {
                'approved' => $who . ' took it — no approval needed',
                'pending_approval' => $who . ' asked for it — sent to a manager',
                default => $who . ' asked for it',
            };
        case 'exchange.withdraw': return $who . ' withdrew their claim';
        case 'exchange.choose':   return $who . ' chose who takes it';
        case 'exchange.accept':   return $who . ' accepted it' . (($a['status'] ?? '') === 'pending_approval' ? ' — sent to a manager' : '');
        case 'exchange.refuse':   return $who . ' refused it';
        case 'exchange.approve':  return $who . ' approved the trade';
        case 'exchange.decline':  return $who . ' declined the trade' . (($a['note'] ?? '') !== '' ? ': ' . $a['note'] : '');
        case 'exchange.cancel':   return $who . ' withdrew the trade';
        case 'exchange.expire':   return 'The offer expired';
        case 'shift.assign':
            $from = $r['before']['assignee_name'] ?? null;
            return ($a['assignee_name'] ?? null) !== null
                ? 'It moved to ' . $a['assignee_name'] . (($a['via'] ?? '') === 'exchange' ? ' by a trade' : '')
                : $who . ' took it off ' . ($from ?? 'its holder');
        default:                  return $who . ' ' . str_replace(['.', '_'], ' ', (string) $r['action']);
    }
}

/** The position's colour as a swatch — an SVG fill, not a style attribute (the colour is the restaurant's own data, a checked #rrggbb). $kind: bar | dot. */
function pos_swatch(?string $color, string $kind = 'bar'): string
{
    $c = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $color) ? $color : '#6c757d';
    return $kind === 'dot'
        ? '<svg class="pos-swatch pos-dot" width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><circle cx="5" cy="5" r="5" fill="' . e($c) . '"/></svg>'
        : '<svg class="pos-swatch pos-bar" width="6" height="100%" viewBox="0 0 6 10" preserveAspectRatio="none" aria-hidden="true"><rect width="6" height="10" fill="' . e($c) . '"/></svg>';
}

/** A team-schedule row as JSON: who, when, whether open — no cost, no phone, no email. */
function present_team_row(array $s): array
{
    return ['shift_id' => (int) $s['shift_id'], 'starts_at' => json_ts($s['starts_at']), 'ends_at' => json_ts($s['ends_at']),
            'time' => shift_time_range($s['starts_at'], $s['ends_at'], $s['timezone']), 'is_open' => (bool) $s['is_open'],
            'member' => $s['assignee_member_id'] === null ? null : ['member_id' => (int) $s['assignee_member_id'], 'name' => $s['assignee_name']]];
}
