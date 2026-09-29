<?php
declare(strict_types=1);

/**
 * What every exchange handler shares: the database's refusal in its own words, the right to decide a trade, the
 * outbox rows a step queues, and the log rows a move writes. A handler is: gate at the record's site → one transaction
 * → log with the site → emit_action_status → saved_go.
 */

/** Run a database step; the database's own sentence (P0001) becomes a 422 — 'Not found.' is the trade's absence, a 404. Any other error is a 500. */
function exchange_guard(PDO $pdo, callable $step): mixed
{
    try {
        return $step();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof DomainException) {
            refuse(422, $e->getMessage());
        }
        if ($e instanceof PDOException && (string) $e->getCode() === '23505' && str_contains($e->getMessage(), 'exchanges_one_live_per_shift')) {
            refuse(422, 'That shift already has a trade going.');
        }
        if ($e instanceof PDOException && (string) $e->getCode() === '23P01') {
            refuse(422, 'That would give someone two shifts at once.');
        }
        if ($e instanceof PDOException && (string) $e->getCode() === 'P0001') {
            $msg = db_message($e, 'That could not be done.');
            if ($msg === 'Not found.') {
                refuse(404, 'That trade is no longer available.');
            }
            refuse(422, $msg);
        }
        throw $e;
    }
}

/** The request's exchange id (the manifest's `exchange`, or the column's own name), or a 422. */
function request_exchange_id(): int
{
    $id = request_integer('exchange') ?? request_integer('exchange_id');
    if ($id === null || $id < 1) {
        refuse(422, 'Say which trade.');
    }
    return $id;
}

/** The request's shift id (`shift` or `shift_id`), or a 422. */
function request_shift_id(): int
{
    $id = request_integer('shift') ?? request_integer('shift_id');
    if ($id === null || $id < 1) {
        refuse(422, 'Say which shift.');
    }
    return $id;
}

/** A free-text note, trimmed, at most 500 characters; null when empty. */
function request_note(string $name = 'note'): ?string
{
    $n = request_string($name);
    if (mb_strlen($n) > 500) {
        refuse(422, 'A note may be up to 500 characters.');
    }
    return $n === '' ? null : $n;
}

/** Where a form sends the person after: a local path it asked for (`return_to`), else the default. */
function return_path(string $default): string
{
    return safe_local_path((string) ($_POST['return_to'] ?? '')) ?? $default;
}

/** Does the shift(s) of this exchange start today or tomorrow in the restaurant's zone? (the shift lead's same-day rule) */
function exchange_is_same_day(array $x): bool
{
    $tz = new DateTimeZone((string) ($x['timezone'] ?? 'UTC'));
    $starts = array_filter([$x['shift_starts_at'] ?? null, $x['swap_starts_at'] ?? null]);
    if ($starts === []) {
        return false;
    }
    $earliest = min(array_map(static fn ($s) => (new DateTimeImmutable($s, new DateTimeZone('UTC')))->getTimestamp(), $starts));
    $day = (new DateTimeImmutable('@' . $earliest))->setTimezone($tz)->format('Y-m-d');
    $limit = (new DateTimeImmutable('now', $tz))->modify('+1 day')->format('Y-m-d');
    return $day <= $limit;
}

/**
 * May the caller decide this trade (approve, decline, choose)? requests.approve at its site; or market.approve_day when the
 * restaurant lets shift leads and the shift is today or tomorrow. Returns null when so, else the sentence. Nobody decides their own.
 */
function exchange_decide_refusal(array $x, int $memberId): ?string
{
    $site = (int) $x['site_id'];
    if ($memberId === (int) ($x['from_member_id'] ?? 0) || $memberId === (int) ($x['to_member_id'] ?? 0)) {
        return 'You cannot decide a trade you are part of.';
    }
    if (has_right('requests.approve', $site)) {
        return null;
    }
    if (has_right('market.approve_day', $site)) {
        if (!$x['shift_lead_approves_same_day']) {
            return 'This restaurant does not let shift leads approve trades.';
        }
        return exchange_is_same_day($x) ? null : 'A shift lead approves trades for today or tomorrow only.';
    }
    return 'You may not approve requests here.';
}

/** Queue a notice for one person, on the channels they chose (email and text both on unless they turned one off; the sender is slice 6). One row a channel. */
function notify(PDO $pdo, int $memberId, int $siteId, string $kind, string $subject, string $body, ?string $reference = null, ?string $dedupe = null): void
{
    $st = $pdo->prepare('SELECT by_email, by_sms, kinds FROM notification_prefs WHERE member_id = :m');
    $st->execute(['m' => $memberId]);
    $p = $st->fetch();
    $email = $p === false ? true : (bool) $p['by_email'];
    $sms = $p === false ? true : (bool) $p['by_sms'];
    if ($p !== false && !str_contains((string) $p['kinds'], $kind)) {
        return;                                                     // the person turned this kind off
    }
    $ins = $pdo->prepare('INSERT INTO notification_outbox (member_id, scope_id, channel, kind, subject, body, reference, dedupe_key)
                          VALUES (:m, :s, :c, :k, :sub, :b, :r, :d) ON CONFLICT DO NOTHING');
    foreach (array_filter(['email' => $email, 'sms' => $sms]) as $channel => $_) {
        $ins->execute(['m' => $memberId, 's' => $siteId, 'c' => $channel, 'k' => $kind, 'sub' => $channel === 'email' ? $subject : null,
                       'b' => mb_substr($body, 0, 3900), 'r' => $reference, 'd' => $dedupe === null ? null : $dedupe . ':' . $channel]);
    }
}

/** The facts a notice carries — day, time, restaurant, position, a link — never pay, phone or email. */
function exchange_facts(array $st, bool $swap = false): string
{
    $tz = (string) $st['timezone'];
    $shift = $swap && $st['swap_starts_at'] !== null
        ? shift_when($st['swap_starts_at'], $st['swap_ends_at'], $tz, true) : shift_when($st['shift_starts_at'], $st['shift_ends_at'], $tz, true);
    return ($st['position_name'] ?? 'Shift') . ', ' . $shift . ', ' . $st['site_name'] . '.';
}

/**
 * Queue the notices of one step (the spec's table). $st = exchange_state() AFTER the step; $actor = who acted; $extra per event.
 * events: asked, coverage, taken, needs_manager, accepted, refused, decided, gone.
 */
function exchange_notify(PDO $pdo, string $event, array $st, int $actor, array $extra = []): void
{
    $id = (int) $st['exchange_id'];
    $site = (int) $st['site_id'];
    $ref = 'exchange:' . $id;
    $link = ' ' . app_url('/exchanges/' . $id);
    $facts = exchange_facts($st);
    $holder = $st['from_member_id'];
    $taker = $st['to_member_id'];
    $send = static function (?int $member, string $kind, string $subject, string $body) use ($pdo, $site, $ref): void {
        if ($member !== null) {
            notify($pdo, $member, $site, $kind, $subject, $body, $ref);
        }
    };
    switch ($event) {
        case 'asked':          // a give or a swap: the named colleague
            $swap = $st['kind'] === 'swap' ? ' for ' . exchange_facts($st, true) : '';
            $send($taker, 'exchange', 'A colleague asked you to take a shift', ($st['from_name'] ?? 'A colleague') . ' asked you to ' . ($st['kind'] === 'swap' ? 'swap' : 'take') . ': ' . $facts . $swap . $link);
            break;
        case 'coverage':       // each invitee
            foreach ($extra['invitees'] ?? [] as $m) {
                $send((int) $m, 'exchange', 'A shift needs cover', $st['site_name'] . ' needs cover: ' . $facts . ' Be the first to say yes.' . $link);
            }
            break;
        case 'taken':          // a claim that went straight through: the holder and the taker
            $send($holder, 'exchange', ($st['to_name'] ?? 'Someone') . ' took your shift', ($st['to_name'] ?? 'Someone') . ' took your shift: ' . $facts . $link);
            $send($taker, 'exchange', 'The shift is yours', 'The shift is yours: ' . $facts . $link);
            break;
        case 'needs_manager':  // the approvers, and the taker
            foreach ($extra['approvers'] ?? [] as $m) {
                $send((int) $m, 'exchange', 'A trade waits for you', ($st['to_name'] ?? 'Someone') . ' wants a shift and a manager must look: ' . $facts . $link);
            }
            $send($taker, 'exchange', 'Sent to a manager', 'Your request was sent to a manager: ' . $facts . $link);
            break;
        case 'accepted':
            $send($holder, 'exchange', ($st['to_name'] ?? 'A colleague') . ' accepted', ($st['to_name'] ?? 'A colleague') . ' accepted the trade: ' . $facts . $link);
            break;
        case 'refused':
            $send($holder, 'exchange', ($st['to_name'] ?? 'A colleague') . ' refused', ($st['to_name'] ?? 'A colleague') . ' turned the trade down: ' . $facts . $link);
            break;
        case 'decided':        // approved / declined / chosen: the holder and the taker
            $word = $extra['word'] ?? 'decided';
            $send($holder, 'request_decided', 'The trade was ' . $word, 'The trade was ' . $word . ': ' . $facts . (($extra['note'] ?? '') !== '' ? ' Note: ' . $extra['note'] : '') . $link);
            $send($taker, 'request_decided', 'The trade was ' . $word, 'The trade was ' . $word . ': ' . $facts . (($extra['note'] ?? '') !== '' ? ' Note: ' . $extra['note'] : '') . $link);
            break;
        case 'gone':           // the other invitees of a coverage request
            foreach ($extra['invitees'] ?? [] as $m) {
                $send((int) $m, 'exchange', 'The shift is taken', 'That shift has been taken by someone else: ' . $facts);
            }
            break;
    }
}

/**
 * After a step that may have moved shifts: for each shift whose holder changed between $before and the database now, write
 * `shift.assign` with the site — before/after the holder's id and name, after.via = 'exchange'. Returns how many moved.
 */
function log_shift_moves(PDO $pdo, array $before, ?string $screen = null): int
{
    $after = exchange_state($pdo, (int) $before['exchange_id']);
    if ($after === null || $after['status'] !== 'approved') {
        return 0;
    }
    $moved = 0;
    $pairs = [[(int) $before['shift_id'], $before['shift_holder_id'], $before['shift_holder_name'], $after['shift_holder_id'], $after['shift_holder_name']]];
    if ($before['swap_shift_id'] !== null) {
        $pairs[] = [(int) $before['swap_shift_id'], $before['swap_holder_id'], $before['swap_holder_name'], $after['swap_holder_id'], $after['swap_holder_name']];
    }
    foreach ($pairs as [$shiftId, $fromId, $fromName, $toId, $toName]) {
        if ($fromId === $toId) {
            continue;
        }
        log_activity($pdo, 'shift.assign', 'shift', $shiftId, [
            'scope_id' => (int) $before['site_id'],
            'before' => ['assignee_member_id' => $fromId, 'assignee_name' => $fromName],
            'after' => ['assignee_member_id' => $toId, 'assignee_name' => $toName, 'via' => 'exchange', 'exchange_id' => (int) $before['exchange_id']],
        ]);
        $moved++;
    }
    return $moved;
}

/** Log one exchange.* row with the site; the shift id rides in `after` so a shift's history finds it. */
function log_exchange(PDO $pdo, string $action, array $st, array $after = []): void
{
    log_activity($pdo, $action, 'exchange', (int) $st['exchange_id'], [
        'scope_id' => (int) $st['site_id'],
        'after' => ['exchange_id' => (int) $st['exchange_id'], 'shift_id' => (int) $st['shift_id']] + $after,
    ]);
}

/** Wrap the standard tail of a handler: report, then land. $path is where the person (or the location of a create) goes. */
function exchange_done(string $did, int $recordId, string $path, array $data = []): never
{
    emit_action_status(true, ['did' => $did, 'record_id' => $recordId, 'refresh' => 'exchangeChanged'] + $data);
    saved_go($path, 'exchangeChanged');
}

/** A path with the banner key a screen shows (?notice=), keeping any query it already has. */
function land_with_notice(string $path, string $notice): string
{
    return $path . (str_contains($path, '?') ? '&' : '?') . 'notice=' . $notice;
}
