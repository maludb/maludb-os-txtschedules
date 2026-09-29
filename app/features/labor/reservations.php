<?php
declare(strict_types=1);

/**
 * Reservations' booked covers through the kernel (K7, docs/build-specs/labor-forecast.md "Reservations' covers through the kernel"): one call to the kernel's
 * `POST /api/v1/apps/read.php` with this application's token — never to Reservations — and the covers the answer names written into the forecast as `reservations`.
 * It DEGRADES and never fails: no connection approved, a restaurant Reservations does not serve, a provider that answers badly — each is a refusal CODE with its sentence and the typed
 * forecast stands. Nothing of the kernel's answer is kept but the covers themselves.
 */

/** The sentence for a refusal code (what a person or an agent reads). */
function reservation_refusal_words(string $code): string
{
    return match ($code) {
        'no_connection' => 'Reservations is not connected — ask a super-admin to approve the connection in the operating system. The forecast you type stands.',
        'not_at_location' => 'Reservations does not serve this restaurant.',
        'not_shared' => 'Reservations does not share its covers with this application.',
        'no_location' => 'This restaurant has no location in the operating system to ask Reservations about.',
        default => 'Reservations did not answer just now. Try again in a minute — the forecast you type stands.',
    };
}

/** The kernel location of a restaurant (mcp_sites.location_id); null when it has none. */
function site_location_id(PDO $pdo, int $siteId): ?int
{
    $st = $pdo->prepare('SELECT location_id FROM mcp_sites WHERE site_id = :s');
    $st->execute(['s' => $siteId]);
    $v = $st->fetchColumn();
    return $v === false || $v === null || (int) $v < 1 ? null : (int) $v;
}

/**
 * Ask the kernel for Reservations' booked covers by date and service. Answers ['rows' => [[date, service, reservations, covers], …]] or ['refusal' => code] (no_connection, not_at_location,
 * not_shared, provider_failed). A malformed answer is provider_failed — never half-used.
 */
function fetch_reservation_covers(int $locationId, string $from, string $to): array
{
    $answer = kernel_call('POST', '/api/v1/apps/read.php', ['provider' => 'reservations', 'tool' => 'covers_by_service', 'arguments' => ['from' => $from, 'to' => $to], 'location_id' => $locationId]);
    if ($answer === null) {
        return ['refusal' => 'provider_failed'];
    }
    $code = (string) ($answer['body']['error']['code'] ?? '');
    if ($answer['status'] === 403 || $answer['status'] === 404) {
        return ['refusal' => in_array($code, ['no_connection', 'not_at_location', 'not_shared'], true) ? $code : ($code === 'people_restricted' ? 'not_shared' : 'provider_failed')];
    }
    if ($answer['status'] !== 200 || !is_array($answer['body']['result'] ?? null)) {
        return ['refusal' => 'provider_failed'];
    }
    $result = $answer['body']['result'];
    $list = is_array($result['rows'] ?? null) ? $result['rows'] : (array_is_list($result) ? $result : null);
    if ($list === null) {
        return ['refusal' => 'provider_failed'];
    }
    $rows = [];
    foreach ($list as $r) {
        if (!is_array($r) || !is_string($r['date'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['date']) || !is_string($r['service'] ?? null) || !is_numeric($r['covers'] ?? null) || (int) $r['covers'] < 0) {
            return ['refusal' => 'provider_failed'];
        }
        $rows[] = ['date' => $r['date'], 'service' => trim($r['service']), 'reservations' => (int) ($r['reservations'] ?? 0), 'covers' => (int) $r['covers']];
    }
    return ['rows' => $rows];
}

/**
 * Fill a week's forecast from Reservations. $answer is what fetch_reservation_covers gave (fetched here when null — a handler fetches BEFORE it opens its transaction). Each row lands in the day-part
 * whose service name matches (case-insensitive; two services on one day-part add up); a service no day-part names is UNMAPPED and listed, never dropped silently. Cells typed or copied by a person
 * are skipped unless $replaceManual. Only days the answer names are touched (a day with no bookings is left alone). Answers
 * ['written' => cells written, 'unchanged' => already that number, 'skipped' => typed cells left alone, 'unmapped' => [[service, covers, days]], 'refusal' => ?code, 'first_id' => ?int, 'cells' => [[date, day_part, covers]]].
 */
function fill_forecast(PDO $pdo, int $siteId, string $weekStart, bool $replaceManual, int $by, ?array $answer = null): array
{
    $out = ['written' => 0, 'unchanged' => 0, 'skipped' => 0, 'unmapped' => [], 'refusal' => null, 'first_id' => null, 'cells' => []];
    $to = (new DateTimeImmutable($weekStart, new DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d');
    if ($answer === null) {
        $loc = site_location_id($pdo, $siteId);
        $answer = $loc === null ? ['refusal' => 'no_location'] : fetch_reservation_covers($loc, $weekStart, $to);
    }
    if (isset($answer['refusal'])) {
        $out['refusal'] = $answer['refusal'];
        return $out;
    }
    $byService = [];
    foreach (find_day_parts($pdo, $siteId) as $d) {
        if ($d['service_name'] !== null && trim($d['service_name']) !== '') {
            $byService[mb_strtolower(trim($d['service_name']))] ??= $d;
        }
    }
    $sum = [];
    $unmapped = [];
    foreach ($answer['rows'] as $r) {
        if ($r['date'] < $weekStart || $r['date'] > $to) {
            continue;
        }
        $d = $byService[mb_strtolower($r['service'])] ?? null;
        if ($d === null) {
            $u = &$unmapped[mb_strtolower($r['service'])];
            $u ??= ['service' => $r['service'], 'covers' => 0, 'days' => []];
            $u['covers'] += $r['covers'];
            $u['days'][$r['date']] = true;
            unset($u);
            continue;
        }
        $sum[$d['day_part_id']]['d'] = $d;
        $sum[$d['day_part_id']]['dates'][$r['date']] = ($sum[$d['day_part_id']]['dates'][$r['date']] ?? 0) + $r['covers'];
    }
    foreach ($unmapped as $u) {
        $out['unmapped'][] = ['service' => $u['service'], 'covers' => $u['covers'], 'days' => count($u['days'])];
    }
    $up = $pdo->prepare("INSERT INTO forecast_covers (scope_id, on_date, day_part_id, expected_covers, source, updated_by) VALUES (:s, :d, :p, :c, 'reservations', :u)
                         ON CONFLICT (scope_id, on_date, day_part_id) DO UPDATE SET expected_covers = EXCLUDED.expected_covers, source = 'reservations', updated_by = EXCLUDED.updated_by, updated_at = now()
                          WHERE CAST(:replace AS boolean) OR forecast_covers.source = 'reservations' RETURNING id");
    ksort($sum);
    foreach ($sum as $dpId => $g) {
        ksort($g['dates']);
        foreach ($g['dates'] as $date => $covers) {
            $cur = forecast_cell($pdo, $siteId, $date, $dpId);
            if ($cur !== null && $cur['source'] === 'reservations' && $cur['covers'] === $covers) {
                $out['unchanged']++;
                continue;
            }
            if ($cur !== null && $cur['source'] !== 'reservations' && !$replaceManual) {
                $out['skipped']++;
                continue;
            }
            $up->bindValue('s', $siteId, PDO::PARAM_INT);
            $up->bindValue('d', $date);
            $up->bindValue('p', $dpId, PDO::PARAM_INT);
            $up->bindValue('c', $covers, PDO::PARAM_INT);
            $up->bindValue('u', $by, PDO::PARAM_INT);
            $up->bindValue('replace', $replaceManual ? 't' : 'f');
            $up->execute();
            $id = $up->fetchColumn();
            if ($id !== false) {
                $out['written']++;
                $out['first_id'] ??= (int) $id;
                $out['cells'][] = ['date' => $date, 'day_part' => $g['d']['name'], 'covers' => $covers];
            }
        }
    }
    return $out;
}
