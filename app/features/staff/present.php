<?php
declare(strict_types=1);

/**
 * How people, positions and certifications are worded and what their JSON answers carry (whitelist presenters — never a raw row). PAY: a rate is printed or returned only where the caller may
 * see it — `labor.view` at the position's restaurant, or their own effective rate — and the presenters here take that decision as an argument the SCREEN made with has_right(); a NULL from a
 * view is never turned into "no rate" for someone who may not see rates (that would tell them one is unset).
 */

/** "$14.00 an hour" — the currency is the restaurant's. */
function rate_label(float $rate, string $currency = 'USD'): string
{
    $sym = ['USD' => '$', 'CAD' => 'CA$', 'EUR' => '€', 'GBP' => '£', 'AUD' => 'A$', 'MXN' => 'MX$'][$currency] ?? '';
    return ($sym !== '' ? $sym : $currency . ' ') . number_format($rate, 2) . ' an hour';
}

/** "Applies to 1 person; 1 has their own rate." */
function rate_reach_words(array $n): string
{
    $w = $n['without'];
    $o = $n['own'];
    return 'Applies to ' . $w . ' ' . ($w === 1 ? 'person' : 'people') . '; ' . $o . ' ' . ($o === 1 ? 'has' : 'have') . ' their own rate.';
}

function area_label(?string $area): string
{
    return POSITION_AREAS[$area ?? 'other'] ?? 'Other';
}

/** [label, bootstrap colour] of a certification state in the due list. */
function cert_state_badge(string $state): array
{
    return match ($state) {
        'expired' => ['Expired', 'danger'],
        'missing' => ['Missing', 'danger'],
        'due' => ['Due soon', 'warning'],
        default => ['To verify', 'info'],
    };
}

/** What a due-list row says: "Expired 3 days ago", "Due in 10 days (Oct 9)", "Missing", "Entered by the person — to verify". */
function cert_due_words(array $r): string
{
    $d = $r['days_left'];
    return match ($r['state']) {
        'expired' => 'Expired ' . ($d === null ? '' : (abs($d) === 1 ? 'yesterday' : abs($d) . ' days ago') . ' (' . format_date($r['expires_on']) . ')'),
        'due' => 'Due ' . ($d === 0 ? 'today' : 'in ' . $d . ' day' . ($d === 1 ? '' : 's')) . ' (' . format_date($r['expires_on']) . ')',
        'missing' => 'Has no card and works a position that needs one',
        default => 'Waiting for a manager to check' . ($r['expires_on'] !== null ? ' · expires ' . format_date($r['expires_on']) : ''),
    };
}

/** A card's line for its owner or a manager: [text, colour] — expired in danger, inside the warning days in warning, else the expiry or "no expiry". */
function cert_card_status(array $c): array
{
    if ($c['replaced']) {
        return ['Replaced by a newer card', 'secondary'];
    }
    if ($c['expired']) {
        return ['Expired ' . format_date($c['expires_on']), 'danger'];
    }
    if ($c['due_soon']) {
        $d = (int) $c['days_left'];
        return ['Expires ' . format_date($c['expires_on']) . ' — ' . ($d === 0 ? 'today' : 'in ' . $d . ' day' . ($d === 1 ? '' : 's')), 'warning'];
    }
    if ($c['expires_on'] !== null && $c['track_expiry']) {
        return ['Expires ' . format_date($c['expires_on']), 'success'];
    }
    return [$c['track_expiry'] ? 'No expiry given' : 'Does not expire', 'secondary'];
}

function cert_verified_words(array $c): string
{
    return $c['verified'] ? 'Verified by a manager' . ($c['verified_by_name'] ?? null ? ' (' . $c['verified_by_name'] . ')' : '') : 'Waiting for a manager to check';
}

/** A person's card list as JSON: kind, dates, state, whether verified. */
function present_certification(array $c): array
{
    return ['certification_id' => $c['certification_id'], 'member_id' => $c['member_id'], 'site_id' => $c['site_id'], 'site' => $c['site_name'], 'kind_id' => $c['kind_id'], 'kind' => $c['kind_name'],
            'issued_on' => $c['issued_on'], 'expires_on' => $c['expires_on'], 'reference' => $c['reference'], 'expired' => $c['expired'], 'due_soon' => $c['due_soon'], 'replaced' => $c['replaced'],
            'verified' => $c['verified'], 'verified_by' => $c['verified_by_name'] ?? null];
}

function present_cert_due(array $r): array
{
    return ['certification_id' => $r['certification_id'], 'member' => ['member_id' => $r['member_id'], 'name' => $r['display_name']], 'kind_id' => $r['kind_id'], 'kind' => $r['kind_name'],
            'state' => $r['state'], 'expires_on' => $r['expires_on'], 'days_left' => $r['days_left'], 'words' => cert_due_words($r)];
}

function present_cert_kind(array $k, array $positionNames = []): array
{
    return ['kind_id' => $k['kind_id'], 'name' => $k['name'], 'track_expiry' => $k['track_expiry'], 'warn_days' => $k['warn_days'],
            'positions' => array_values(array_map(static fn (int $p): array => ['position_id' => $p, 'name' => $positionNames[$p] ?? ''], $k['required_position_ids']))];
}

/** A staff card as JSON — never a wage, an email or a phone. */
function present_staff_row(array $r): array
{
    return ['member_id' => $r['member_id'], 'name' => $r['display_name'], 'main_site_id' => $r['main_site_id'], 'main_site' => $r['main_site_name'], 'on_schedule' => $r['on_schedule'],
            'is_minor' => $r['is_minor'], 'minor_until' => $r['minor_until'], 'max_hours_week' => $r['max_hours_week'], 'expired_certifications' => $r['expired_certifications'],
            'positions' => array_map(static fn (array $p): array => ['position_id' => $p['position_id'], 'name' => $p['name'], 'is_primary' => $p['is_primary']], $r['positions'])];
}

/**
 * The positions of one person for a caller: each row with a rate ONLY where `$see($row)` says so (the screen's has_right('labor.view', site), or the person is the caller — then the effective
 * rate alone, never the source or the own-rate). Answers the rows for the view and for JSON.
 */
function staff_position_rows(array $positions, callable $see, bool $isSelf): array
{
    $out = [];
    foreach ($positions as $p) {
        $row = ['position_id' => $p['position_id'], 'site_id' => $p['site_id'], 'site_name' => $p['site_name'], 'name' => $p['position_name'], 'is_primary' => $p['is_primary'], 'color' => $p['color'],
                'area' => $p['area'], 'pay' => null];
        if ($see($p)) {
            $rate = $p['wage_rate'];
            $row['pay'] = ['rate' => $rate, 'label' => $rate === null ? 'No rate set' : rate_label($rate, (string) $p['currency']), 'source' => $p['wage_source'] === 'override' ? 'own' : ($rate === null ? null : 'default'),
                           'own_rate' => $p['wage_override'], 'own_label' => $p['wage_override'] === null ? null : rate_label($p['wage_override'], (string) $p['currency']), 'may_see_source' => true];
        } elseif ($isSelf && $p['wage_rate'] !== null) {
            $row['pay'] = ['rate' => $p['wage_rate'], 'label' => rate_label($p['wage_rate'], (string) $p['currency']), 'source' => null, 'own_rate' => null, 'own_label' => null, 'may_see_source' => false];
        }
        $out[] = $row;
    }
    return $out;
}

/** The banner a slice-4 action lands with (?notice=), by key — a whitelist, never request text. */
function staff_notice(?string $key): ?array
{
    return match ($key) {
        'st_saved' => ['success', 'Saved.'],
        'st_wage_set' => ['success', 'The person\'s own rate is set.'],
        'st_wage_cleared' => ['success', 'Their own rate is removed — the position\'s default applies.'],
        'st_cert_added' => ['success', 'The card is added.'],
        'st_cert_added_verified' => ['success', 'The card is added and verified.'],
        'st_cert_updated' => ['success', 'The card is changed.'],
        'st_cert_removed' => ['secondary', 'The card is removed.'],
        'st_cert_verified' => ['success', 'The card is verified.'],
        'st_cert_unverified' => ['secondary', 'The card is marked as not yet checked.'],
        'ps_saved' => ['success', 'Saved.'],
        'ps_archived' => ['secondary', 'Archived — it stays on old shifts and leaves the pickers.'],
        'ps_rate_set' => ['success', 'The default rate is set.'],
        'ps_rate_cleared' => ['success', 'The default rate is cleared.'],
        'ck_saved' => ['success', 'Saved.'],
        'ck_archived' => ['secondary', 'Archived — cards already entered stay, and it leaves the pickers and the rule.'],
        default => null,
    };
}
