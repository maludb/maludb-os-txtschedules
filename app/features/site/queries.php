<?php
declare(strict_types=1);

/**
 * A restaurant's own settings (slice 7: docs/build-specs/settings-rules-reports.md) — the trade settings (D2), the week, reminders, the hours a day of time off (D13), overtime — and its
 * day-parts. Every function takes the restaurant from the caller (the handler has already asked settings.manage AT it), opens no transaction of its own, logs nothing, and refuses in words with a
 * DomainException (the handler's guard makes it a 422). A setting takes effect for the NEXT action: nothing here rewrites an exchange or a request that already exists.
 */

/** The three exchange kinds a manager may be asked about, in the words a sentence uses: kind => [noun with article, plural verb form]. */
const TRADE_APPROVALS = ['pickup' => 'a pick-up', 'swap' => 'a swap', 'give' => 'a give'];
const APPROVAL_CHOICES = ['always' => 'Always', 'on_warning' => 'Only when a rule warns', 'never' => 'Never'];

/**
 * The fields of the settings form, how each is read and what it may be. type: bool | int | dec | enum | currency. The names are exactly site_settings' columns.
 */
const SETTINGS_FIELDS = [
    'week_start'                   => ['int', 0, 6, 'The week starts on a day from 0 (Sunday) to 6 (Saturday).'],
    'currency'                     => ['currency'],
    'allow_offer'                  => ['bool'],
    'allow_pickup'                 => ['bool'],
    'allow_swap'                   => ['bool'],
    'allow_give'                   => ['bool'],
    'approval_pickup'              => ['enum', ['always', 'on_warning', 'never'], 'A pick-up needs a manager always, only when a rule warns, or never.'],
    'approval_swap'                => ['enum', ['always', 'on_warning', 'never'], 'A swap needs a manager always, only when a rule warns, or never.'],
    'approval_give'                => ['enum', ['always', 'on_warning', 'never'], 'A give needs a manager always, only when a rule warns, or never.'],
    'cutoff_minutes'               => ['int', 0, 10080, 'No trade closer than is a number of minutes from 0 to 10080 (a week).'],
    'shift_lead_approves_same_day' => ['bool'],
    'claim_mode'                   => ['enum', ['first', 'manager_chooses'], 'When several people ask, the first wins or a manager chooses.'],
    'offer_expires'                => ['enum', ['at_start', 'at_cutoff'], 'An unclaimed offer ends at the start or at the cutoff.'],
    'availability_needs_approval'  => ['bool'],
    'reminder_minutes_before'      => ['int', 0, 2880, 'Remind people a number of minutes before a shift from 0 to 2880 (two days).'],
    'time_off_day_hours'           => ['dec', 0.25, 24, 'A day of time off counts from 0.25 to 24 hours.'],
    'overtime_weekly_hours'        => ['dec', 1, 168, 'Overtime starts after a number of hours a week from 1 to 168.'],
    'overtime_multiplier'          => ['dec', 1, 10, 'The overtime multiplier is from 1 to 10 (1.5 is time and a half).'],
];

/** The restaurant's settings row (base table — every field typed), or null when there is none. */
function find_site_settings(PDO $pdo, int $siteId): ?array
{
    $st = $pdo->prepare('SELECT scope_id, ' . implode(', ', array_keys(SETTINGS_FIELDS)) . ', rule_preset, updated_at FROM site_settings WHERE scope_id = :s');
    $st->execute(['s' => $siteId]);
    $r = $st->fetch();
    return $r === false ? null : settings_typed($r) + ['scope_id' => (int) $r['scope_id'], 'rule_preset' => $r['rule_preset'], 'updated_at' => $r['updated_at']];
}

/** The typed settings of a row (bools true/false, ints, floats). */
function settings_typed(array $row): array
{
    $out = [];
    foreach (SETTINGS_FIELDS as $k => $spec) {
        $v = $row[$k];
        $out[$k] = match ($spec[0]) {
            'bool' => is_bool($v) ? $v : in_array((string) $v, ['t', 'true', '1'], true),
            'int' => (int) $v,
            'dec' => round((float) $v, 2),
            default => (string) $v,
        };
    }
    return $out;
}

/**
 * The new settings a request asks for, checked field by field: $raw is what was sent (a field left out is absent), $cur the current typed settings. A field left out stays; the answer is the
 * WHOLE settings, typed. Anything out of range is refused in the field's own words — never clamped.
 */
function parse_site_settings(array $raw, array $cur): array
{
    $new = $cur;
    foreach (SETTINGS_FIELDS as $k => $spec) {
        if (!array_key_exists($k, $raw)) {
            continue;
        }
        $v = is_string($raw[$k]) ? trim($raw[$k]) : $raw[$k];
        switch ($spec[0]) {
            case 'bool':
                $s = strtolower((string) (is_bool($v) ? ($v ? 'yes' : 'no') : $v));
                if (!in_array($s, ['1', 'true', 'on', 'yes', '0', 'false', 'off', 'no'], true)) {
                    throw new DomainException('Say yes or no for ' . str_replace('_', ' ', $k) . '.');
                }
                $new[$k] = in_array($s, ['1', 'true', 'on', 'yes'], true);
                break;
            case 'int':
                if (!is_scalar($v) || !preg_match('/^-?\d{1,6}$/', (string) $v) || (int) $v < $spec[1] || (int) $v > $spec[2]) {
                    throw new DomainException($spec[3]);
                }
                $new[$k] = (int) $v;
                break;
            case 'dec':
                if (!is_scalar($v) || !is_numeric((string) $v) || (float) $v < $spec[1] || (float) $v > $spec[2]) {
                    throw new DomainException($spec[3]);
                }
                $new[$k] = round((float) $v, 2);
                break;
            case 'enum':
                if (!is_scalar($v) || !in_array((string) $v, $spec[1], true)) {
                    throw new DomainException($spec[2]);
                }
                $new[$k] = (string) $v;
                break;
            case 'currency':
                $c = strtoupper((string) $v);
                if (!preg_match('/^[A-Z]{3}$/', $c)) {
                    throw new DomainException('The currency is a three-letter code like USD.');
                }
                $new[$k] = $c;
                break;
        }
    }
    return $new;
}

/**
 * Save the settings whole; answers ['changed' => [field => ['before' => x, 'after' => y]]] — empty when nothing differs (then nothing is written). The row is locked so two saves
 * do not interleave. Never writes a field the request did not change.
 */
function save_site_settings(PDO $pdo, int $siteId, array $f, int $by): array
{
    $lock = $pdo->prepare('SELECT 1 FROM site_settings WHERE scope_id = :s FOR UPDATE');
    $lock->execute(['s' => $siteId]);
    if ($lock->fetchColumn() === false) {
        throw new DomainException('Not found.');
    }
    $cur = find_site_settings($pdo, $siteId) ?? throw new DomainException('Not found.');
    $changed = [];
    foreach (SETTINGS_FIELDS as $k => $spec) {
        if ($f[$k] !== $cur[$k]) {
            $changed[$k] = ['before' => $cur[$k], 'after' => $f[$k]];
        }
    }
    if ($changed === []) {
        return ['changed' => []];
    }
    $sets = [];
    $args = ['s' => $siteId, 'by' => $by];
    foreach ($changed as $k => $c) {
        $sets[] = "$k = :$k";
        $args[$k] = is_bool($c['after']) ? ($c['after'] ? 't' : 'f') : $c['after'];
    }
    $pdo->prepare('UPDATE site_settings SET ' . implode(', ', $sets) . ', updated_by = :by WHERE scope_id = :s')->execute($args);
    return ['changed' => $changed];
}

/** "offer, pick up and swap" — a list in words. */
function words_list(array $items): string
{
    $items = array_values($items);
    $n = count($items);
    return $n <= 1 ? (string) ($items[0] ?? '') : implode(', ', array_slice($items, 0, -1)) . ' and ' . $items[$n - 1];
}

/** "2 hours", "90 minutes", "1 day" — the cutoff as a person says it. */
function cutoff_words(int $minutes): string
{
    if ($minutes % 1440 === 0 && $minutes > 0) {
        $d = intdiv($minutes, 1440);
        return $d . ($d === 1 ? ' day' : ' days');
    }
    if ($minutes % 60 === 0 && $minutes > 0) {
        $h = intdiv($minutes, 60);
        return $h . ($h === 1 ? ' hour' : ' hours');
    }
    return $minutes . ($minutes === 1 ? ' minute' : ' minutes');
}

/**
 * The preview sentence: what these settings mean in plain words. $s is the typed settings (saved or as typed in the form).
 * "Staff can offer, pick up, swap and give shifts. A manager looks at a pick-up only when a rule warns. Nothing changes hands within 2 hours of the start."
 */
function trade_sentence(array $s): string
{
    $verbs = ['offer' => 'offer', 'pickup' => 'pick up', 'swap' => 'swap', 'give' => 'give'];
    $allowed = [];
    foreach ($verbs as $k => $word) {
        if (!empty($s['allow_' . $k])) {
            $allowed[$k] = $word;
        }
    }
    if ($allowed === []) {
        return 'Staff cannot trade shifts at all. Nothing changes hands.';
    }
    $out = ['Staff can ' . words_list($allowed) . ' shifts.'];
    $groups = ['always' => [], 'on_warning' => [], 'never' => []];
    foreach (TRADE_APPROVALS as $k => $noun) {
        if (isset($allowed[$k])) {
            $groups[(string) ($s['approval_' . $k] ?? 'on_warning')][] = $noun;
        }
    }
    if ($groups['always'] !== []) {
        $out[] = 'A manager approves every ' . words_list(array_map(static fn (string $n): string => substr($n, 2), $groups['always'])) . '.';
    }
    if ($groups['on_warning'] !== []) {
        $out[] = 'A manager looks at ' . words_list($groups['on_warning']) . ' only when a rule warns.';
    }
    if ($groups['never'] !== []) {
        $many = count($groups['never']) > 1;
        $out[] = ucfirst(words_list($groups['never'])) . ' never ' . ($many ? 'wait' : 'waits') . ' for a manager.';
    }
    $anyManager = $groups['always'] !== [] || $groups['on_warning'] !== [];
    if ($anyManager) {
        $out[] = !empty($s['shift_lead_approves_same_day']) ? 'Shift leads can approve same-day and next-day trades.' : 'Only a manager approves same-day and next-day trades.';
    }
    if (!empty($allowed['pickup'])) {
        $out[] = ($s['claim_mode'] ?? 'first') === 'manager_chooses' ? 'When several people ask for a shift, a manager chooses.' : 'When several people ask for a shift, the first to ask gets it.';
    }
    if (!empty($allowed['offer'])) {
        $out[] = ($s['offer_expires'] ?? 'at_start') === 'at_cutoff' ? 'An offer nobody takes ends at the cutoff.' : 'An offer nobody takes ends when the shift starts.';
    }
    $c = (int) ($s['cutoff_minutes'] ?? 0);
    $out[] = $c === 0 ? 'Trades are open right up to the start.' : 'Nothing changes hands within ' . cutoff_words($c) . ' of the start.';
    return implode(' ', $out);
}

/** "Vacation of two whole days counts 12 hours." — what the setting means, as an example under the field. */
function day_hours_sentence(float $hours): string
{
    $total = round($hours * 2, 2);
    return 'Vacation of two whole days counts ' . days_label($total) . ($total === 1.0 ? ' hour.' : ' hours.');
}

// ---- day-parts ---------------------------------------------------------------------------------------------------------------------------

/** Every day-part of the restaurant, live first, archived after: day_part_id, key, name, starts_at, ends_at (HH:MM), sort_order, service_name, archived. */
function find_all_day_parts(PDO $pdo, int $siteId): array
{
    $st = $pdo->prepare("SELECT id, key, name, to_char(starts_at, 'HH24:MI') AS starts_at, to_char(ends_at, 'HH24:MI') AS ends_at, sort_order, service_name, (archived_at IS NOT NULL) AS archived
                           FROM day_parts WHERE scope_id = :s ORDER BY archived_at NULLS FIRST, sort_order, id");
    $st->execute(['s' => $siteId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['day_part_id'] = (int) $r['id'];
        $r['sort_order'] = (int) $r['sort_order'];
        $r['archived'] = in_array($r['archived'], [true, 't', '1', 1], true);
    }
    unset($r);
    return $rows;
}

/** The base row of one day-part (with its restaurant), or null. */
function day_part_row(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT id, scope_id, key, name, to_char(starts_at, 'HH24:MI') AS starts_at, to_char(ends_at, 'HH24:MI') AS ends_at, sort_order, service_name, archived_at FROM day_parts WHERE id = :i");
    $st->execute(['i' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A clock time "9:00" / "09:00" / "17:30" → "HH:MM"; null when it is not a time of day. */
function clock_text(string $v): ?string
{
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:00)?$/', trim($v), $m)) {
        return null;
    }
    return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
}

/** A key for a name: lower-case words joined by _, starting with a letter, at most 30, unique among the restaurant's day-parts (archived ones included — the key is unique for good). */
function day_part_key(PDO $pdo, int $siteId, string $name): string
{
    $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
    if ($base === '' || !ctype_alpha($base[0])) {
        $base = 'part_' . $base;
    }
    $base = rtrim(substr($base, 0, 26), '_');
    $key = $base;
    for ($i = 2; $i < 100; $i++) {
        $st = $pdo->prepare('SELECT 1 FROM day_parts WHERE scope_id = :s AND key = :k');
        $st->execute(['s' => $siteId, 'k' => $key]);
        if ($st->fetchColumn() === false) {
            return $key;
        }
        $key = $base . '_' . $i;
    }
    throw new DomainException('Choose a different name for the day-part.');
}

/**
 * Add a day-part ($id null) or change one. $f: name, starts_at, ends_at (HH:MM), service_name (?string), sort_order (?int — null puts a new one last). A day-part may run past midnight (the end
 * before the start), never start and end at the same minute; two live day-parts may not share a name or a service name (Reservations' covers would land twice).
 * Answers ['id', 'before' => ?array, 'after' => array].
 */
function save_day_part(PDO $pdo, int $siteId, ?int $id, array $f, int $by): array
{
    $name = trim((string) ($f['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 40) {
        throw new DomainException('A day-part needs a name of up to 40 characters.');
    }
    $from = clock_text((string) ($f['starts_at'] ?? ''));
    $to = clock_text((string) ($f['ends_at'] ?? ''));
    if ($from === null || $to === null) {
        throw new DomainException('Give the start and the end as times like 11:00 and 15:00.');
    }
    if ($from === $to) {
        throw new DomainException('A day-part cannot start and end at the same time.');
    }
    $service = isset($f['service_name']) ? trim((string) $f['service_name']) : '';
    if (mb_strlen($service) > 40) {
        throw new DomainException('A service name is up to 40 characters.');
    }
    $service = $service === '' ? null : $service;
    $order = $f['sort_order'] ?? null;
    if ($order !== null && (!is_int($order) || $order < 0 || $order > 1000)) {
        throw new DomainException('The order is a whole number from 0 to 1000.');
    }
    $before = null;
    if ($id !== null) {
        $before = day_part_row($pdo, $id);
        if ($before === null || (int) $before['scope_id'] !== $siteId || $before['archived_at'] !== null) {
            throw new DomainException('Not found.');
        }
    }
    foreach (find_all_day_parts($pdo, $siteId) as $d) {
        if ($d['archived'] || $d['day_part_id'] === $id) {
            continue;
        }
        if (strcasecmp($d['name'], $name) === 0) {
            throw new DomainException('There is already a day-part called ' . $d['name'] . '.');
        }
        if ($service !== null && $d['service_name'] !== null && strcasecmp($d['service_name'], $service) === 0) {
            throw new DomainException($d['name'] . ' already uses that service name.');
        }
    }
    if ($id === null) {
        if ($order === null) {
            $max = $pdo->prepare('SELECT COALESCE(max(sort_order), 0) + 1 FROM day_parts WHERE scope_id = :s AND archived_at IS NULL');
            $max->execute(['s' => $siteId]);
            $order = (int) $max->fetchColumn();
        }
        $st = $pdo->prepare('INSERT INTO day_parts (scope_id, key, name, starts_at, ends_at, sort_order, service_name) VALUES (:s, :k, :n, :a, :b, :o, :v) RETURNING id');
        $st->execute(['s' => $siteId, 'k' => day_part_key($pdo, $siteId, $name), 'n' => $name, 'a' => $from, 'b' => $to, 'o' => $order, 'v' => $service]);
        $id = (int) $st->fetchColumn();
    } else {
        $order ??= (int) $before['sort_order'];
        $pdo->prepare('UPDATE day_parts SET name = :n, starts_at = :a, ends_at = :b, sort_order = :o, service_name = :v WHERE id = :i AND scope_id = :s')
            ->execute(['n' => $name, 'a' => $from, 'b' => $to, 'o' => $order, 'v' => $service, 'i' => $id, 's' => $siteId]);
    }
    return ['id' => $id, 'before' => $before === null ? null : array_intersect_key($before, array_flip(['name', 'starts_at', 'ends_at', 'sort_order', 'service_name'])),
            'after' => ['name' => $name, 'starts_at' => $from, 'ends_at' => $to, 'sort_order' => $order, 'service_name' => $service]];
}

/** Archive a day-part: it leaves the forecast and the pickers; its old covers stay. Answers its row as it was. */
function archive_day_part(PDO $pdo, int $id, int $by): array
{
    $row = day_part_row($pdo, $id);
    if ($row === null || $row['archived_at'] !== null) {
        throw new DomainException('Not found.');
    }
    $pdo->prepare('UPDATE day_parts SET archived_at = now() WHERE id = :i')->execute(['i' => $id]);
    return $row;
}
