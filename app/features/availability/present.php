<?php
declare(strict_types=1);

/** How availability is worded and what its JSON answer carries (whitelist presenters — never a raw row). Times are the restaurant's wall-clock; no zone is needed. */

/** "5:00–11:00 pm", "All day", "10:00 pm–2:00 am (past midnight)" for a block's HH:MM pair. */
function availability_span(string $from, string $to): string
{
    if ($from === '00:00' && $to === '00:00') {
        return 'All day';
    }
    $a = new DateTimeImmutable('2000-01-01 ' . $from);
    $b = new DateTimeImmutable('2000-01-01 ' . $to);
    $over = $to <= $from;
    $same = $a->format('a') === $b->format('a') && !$over;
    return $a->format('g:i') . ($same ? '' : ' ' . $a->format('a')) . '–' . $b->format('g:i a') . ($over ? ' (next day)' : '');
}

/** [label, bootstrap colour] of a block's kind. */
function availability_kind_badge(string $kind): array
{
    return match ($kind) {
        'available' => ['Available', 'success'],
        'unavailable' => ['Unavailable', 'danger'],
        default => ['Preferred', 'info'],
    };
}

/** "from Oct 9" / "Oct 9 – Nov 3" / "" when it has always been in effect. */
function availability_dates(array $b): string
{
    $from = format_date($b['effective_from']);
    return $b['effective_to'] !== null ? $from . ' – ' . format_date($b['effective_to']) : 'from ' . $from;
}

function present_availability(array $b): array
{
    return ['availability_id' => (int) $b['availability_id'], 'member' => ['member_id' => (int) $b['member_id'], 'name' => $b['member_name']],
            'site_id' => $b['site_id'], 'site' => $b['site_id'] === null ? null : $b['site_name'],
            'weekday' => (int) $b['weekday'], 'day' => WEEKDAY_NAMES[(int) $b['weekday']], 'starts' => $b['starts_at'], 'ends' => $b['ends_at'],
            'all_day' => $b['starts_at'] === '00:00' && $b['ends_at'] === '00:00', 'words' => availability_span($b['starts_at'], $b['ends_at']),
            'kind' => $b['kind'], 'effective_from' => $b['effective_from'], 'effective_to' => $b['effective_to'], 'status' => $b['status']];
}

/** The banner an availability action lands with (?notice=), by key — a whitelist. */
function availability_notice(?string $key): ?array
{
    return match ($key) {
        'av_saved' => ['success', 'Saved.'],
        'av_pending' => ['info', 'Saved — it counts once a manager approves it.'],
        'av_removed' => ['secondary', 'Removed.'],
        'av_approved' => ['success', 'Approved.'],
        'av_declined' => ['secondary', 'Declined.'],
        default => null,
    };
}
