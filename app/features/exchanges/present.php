<?php
declare(strict_types=1);

/** How an exchange is worded, badged and shown as JSON (whitelist presenters; no pay, no contact details). */

const EXCHANGE_KIND_ICON = ['offer' => 'feather-share', 'open' => 'feather-plus-circle', 'give' => 'feather-gift', 'swap' => 'feather-repeat', 'coverage' => 'feather-users'];
const EXCHANGE_KIND_WORD = ['offer' => 'Offer', 'open' => 'Open shift', 'give' => 'Give', 'swap' => 'Swap', 'coverage' => 'Cover request'];

/** [label, colour] for an exchange's state — the status vocabulary of the spec. */
function exchange_status_badge(array $x): array
{
    return match ($x['status']) {
        'open' => ['Up for grabs', 'primary'],
        'pending_acceptance' => ['Waiting for ' . ($x['to_name'] ?? 'a colleague'), 'info'],
        'pending_approval' => ['Waiting for a manager', 'warning'],
        'approved' => ['Done', 'success'],
        'declined' => ['Declined', 'secondary'],
        'cancelled' => ['Withdrawn', 'secondary'],
        'expired' => ['Expired', 'dark'],
        default => [(string) $x['status'], 'secondary'],
    };
}

function claim_status_badge(string $s): array
{
    return match ($s) {
        'pending' => ['Waiting', 'info'], 'won' => ['Yours', 'success'], 'lost' => ['Someone else', 'secondary'], 'withdrawn' => ['Withdrawn', 'secondary'], default => [$s, 'secondary'],
    };
}

/** The one line for a trade: "Priya offers Fri Oct 9 · 5:00–11:00 pm (Server)". */
function exchange_headline(array $x): string
{
    $when = shift_when($x['shift_starts_at'], $x['shift_ends_at'], $x['timezone'], show_zone());
    $pos = $x['position_name'] ?? 'shift';
    if (($x['to_name'] ?? null) !== null && in_array($x['kind'], ['offer', 'open', 'coverage'], true) && in_array($x['status'], ['pending_approval', 'approved'], true)) {
        return $x['to_name'] . ' takes ' . $when . ' (' . $pos . ')' . (($x['from_name'] ?? null) !== null ? ' from ' . $x['from_name'] : '');
    }
    return match ($x['kind']) {
        'offer' => ($x['from_name'] ?? 'Someone') . ' offers ' . $when . ' (' . $pos . ')',
        'open' => 'Open shift ' . $when . ' (' . $pos . ')',
        'give' => ($x['from_name'] ?? 'Someone') . ' gives ' . $when . ' (' . $pos . ') to ' . ($x['to_name'] ?? 'a colleague'),
        'swap' => ($x['from_name'] ?? 'Someone') . ' swaps ' . $when . ' (' . $pos . ') with ' . ($x['to_name'] ?? 'a colleague')
                  . (($x['swap_starts_at'] ?? null) !== null ? ' for ' . shift_when($x['swap_starts_at'], $x['swap_ends_at'], $x['timezone'], show_zone()) : ''),
        'coverage' => 'Cover wanted ' . $when . ' (' . $pos . ')',
        default => $when,
    };
}

/** The banner a finished action lands with (?notice=), by key — a whitelist, never request text. [kind, message] or null. */
function notice_words(?string $key): ?array
{
    return match ($key) {
        'claim_approved' => ['success', 'It\'s yours — added to your schedule.'],
        'claim_pending' => ['info', 'Sent to a manager.'],
        'claim_noted' => ['info', 'Your interest is noted — a manager chooses.'],
        'accepted' => ['success', 'Accepted — the shift is yours.'],
        'accepted_pending' => ['info', 'Accepted — sent to a manager.'],
        'refused' => ['secondary', 'You refused it.'],
        'withdrawn' => ['secondary', 'Your claim is withdrawn.'],
        'cancelled' => ['secondary', 'The trade is withdrawn.'],
        'approved' => ['success', 'Approved — the shift has moved.'],
        'declined' => ['secondary', 'Declined — the shift stays where it was.'],
        'chosen' => ['success', 'Given — the shift has moved.'],
        'offered' => ['success', 'It is up on the marketplace.'],
        'asked' => ['success', 'Asked — you will hear when they answer.'],
        default => null,
    };
}

/** An exchange as JSON — the facts a person may see; the claims only for those who decide (the caller passes them). */
function present_exchange(array $x, ?array $claims = null): array
{
    $tz = (string) $x['timezone'];
    $out = [
        'exchange_id' => (int) $x['exchange_id'], 'site_id' => (int) $x['site_id'], 'site' => $x['site_name'], 'time_zone' => $tz,
        'kind' => $x['kind'], 'status' => $x['status'], 'headline' => exchange_headline($x),
        'shift' => ['shift_id' => (int) $x['shift_id'], 'position' => $x['position_name'], 'starts_at' => json_ts($x['shift_starts_at']), 'ends_at' => json_ts($x['shift_ends_at']),
                    'when' => shift_when($x['shift_starts_at'], $x['shift_ends_at'], $tz, true)],
        'swap_shift' => ($x['swap_shift_id'] ?? null) === null ? null : ['shift_id' => (int) $x['swap_shift_id'], 'position' => $x['swap_position_name'],
                    'starts_at' => json_ts($x['swap_starts_at']), 'ends_at' => json_ts($x['swap_ends_at'])],
        'from' => $x['from_member_id'] === null ? null : ['member_id' => (int) $x['from_member_id'], 'name' => $x['from_name']],
        'to' => $x['to_member_id'] === null ? null : ['member_id' => (int) $x['to_member_id'], 'name' => $x['to_name']],
        'note' => $x['note'], 'expires_at' => json_ts($x['expires_at']), 'needs_approval' => (bool) $x['needs_approval'],
        'warnings' => array_map(static fn (array $w): array => ['rule' => $w['rule'] ?? null, 'severity' => $w['severity'] ?? 'soft', 'message' => $w['message'] ?? ''], $x['warnings'] ?? []),
        'claims' => (int) $x['claims'], 'decision_note' => $x['decision_note'], 'decided_at' => json_ts($x['decided_at']),
    ];
    if ($claims !== null) {
        $out['claim_list'] = array_map(static fn (array $c): array => ['member_id' => (int) $c['member_id'], 'name' => $c['member_name'], 'status' => $c['status'],
            'warnings' => array_map(static fn (array $w): string => (string) ($w['message'] ?? ''), $c['warnings'] ?? [])], $claims);
    }
    return $out;
}
