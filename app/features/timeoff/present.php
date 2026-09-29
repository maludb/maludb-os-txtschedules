<?php
declare(strict_types=1);

/** How time off is worded and what its JSON answer carries (whitelist presenters). Hours, never pay. Times are the request's restaurant's, with the zone when a person holds more than one. */

/** "16 h", "4 h", "0.5 h". */
function hours_label(float|int|string|null $h): string
{
    return days_label($h ?? 0) . ' h';
}

/** A request's dates in words, in its restaurant's zone: "Fri Oct 9", "Fri Oct 9 – Sun Oct 11", "Fri Oct 9 · 9:00 am–1:00 pm", "Fri Oct 9, 9:00 am – Sun Oct 11, 5:00 pm" (+ zone). */
function time_off_when(array $r, bool $zone = false): string
{
    $tz = (string) $r['timezone'];
    $a = local_dt((string) $r['starts_at'], $tz);
    $last = local_dt((string) $r['ends_at'], $tz)->modify('-1 second');
    $b = local_dt((string) $r['ends_at'], $tz);
    $whole = $a->format('H:i') === '00:00' && $b->format('H:i') === '00:00';
    if ($whole) {
        $out = $a->format('Y-m-d') === $last->format('Y-m-d') ? $a->format('D M j') : $a->format('D M j') . ' – ' . $last->format('D M j');
        return $out;
    }
    if ($a->format('Y-m-d') === $b->format('Y-m-d')) {
        return shift_when((string) $r['starts_at'], (string) $r['ends_at'], $tz, $zone);
    }
    return $a->format('D M j, g:i a') . ' – ' . $b->format('D M j, g:i a') . ($zone ? ' ' . $a->format('T') : '');
}

/** [label, bootstrap colour] of a request's state. */
function time_off_status_badge(string $status): array
{
    return match ($status) {
        'pending' => ['Waiting for a manager', 'warning'],
        'approved' => ['Approved', 'success'],
        'declined' => ['Declined', 'danger'],
        default => ['Cancelled', 'secondary'],
    };
}

/** What a notice says about a request — kind, dates, hours, restaurant: never pay, phone or email. */
function time_off_facts(array $r): string
{
    return $r['type_name'] . ', ' . time_off_when($r, true) . ', ' . hours_label($r['hours']) . ', ' . $r['site_name'] . '.';
}

/** A request as JSON: the facts a person may see. The balance only when the caller was allowed to read it (the caller passes $withBalance). */
function present_time_off(array $r, bool $withBalance = false, ?array $shifts = null): array
{
    $tz = (string) $r['timezone'];
    $out = ['request_id' => (int) $r['request_id'], 'site_id' => (int) $r['site_id'], 'site' => $r['site_name'], 'time_zone' => $tz,
            'member' => ['member_id' => (int) $r['member_id'], 'name' => $r['member_name']],
            'type' => ['type_id' => (int) $r['type_id'], 'name' => $r['type_name'], 'paid' => (bool) $r['paid'], 'tracks_balance' => (bool) $r['tracks_balance']],
            'starts_at' => json_ts((string) $r['starts_at']), 'ends_at' => json_ts((string) $r['ends_at']), 'when' => time_off_when($r, true), 'hours' => (float) $r['hours'],
            'note' => $r['note'], 'status' => $r['status'], 'decided_by' => $r['decided_by_name'] ?? null, 'decided_at' => json_ts($r['decided_at'] ?? null), 'decision_note' => $r['decision_note'] ?? null];
    if ($withBalance && $r['tracks_balance']) {
        $b = (float) ($r['balance_hours'] ?? 0);
        $pending = $r['status'] === 'pending';
        $out['balance'] = ['hours' => $b, 'after_approval' => $pending ? round($b - (float) $r['hours'], 2) : null];
    }
    if ($shifts !== null) {
        $out['covered_shifts'] = array_map(static fn (array $s): array => ['shift_id' => (int) $s['shift_id'], 'position' => $s['position_name'], 'starts_at' => json_ts((string) $s['starts_at']),
            'ends_at' => json_ts((string) $s['ends_at']), 'when' => shift_when((string) $s['starts_at'], (string) $s['ends_at'], (string) $s['timezone'], true)], $shifts);
    }
    return $out;
}

/** The banner a time-off action lands with (?notice=), by key — a whitelist, never request text. */
function time_off_notice(?string $key): ?array
{
    return match ($key) {
        'to_requested' => ['success', 'Asked — you will hear when a manager decides.'],
        'to_approved' => ['success', 'Approved.'],
        'to_approved_opened' => ['success', 'Approved — the shifts it covered are open, and their holders were told.'],
        'to_declined' => ['secondary', 'Declined.'],
        'to_cancelled' => ['secondary', 'Cancelled.'],
        'to_adjusted' => ['success', 'The balance is updated.'],
        'to_type_saved' => ['success', 'Saved.'],
        'to_type_archived' => ['secondary', 'Archived — it stays on old requests and leaves the picker.'],
        'to_blackout_saved' => ['success', 'That date is blacked out.'],
        'to_blackout_removed' => ['secondary', 'That date is open again.'],
        default => availability_notice($key),
    };
}
