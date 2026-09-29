<?php
declare(strict_types=1);

/**
 * What the worker sends with (slice 6): an email through MaluMail (the application's own sender), a text through the KERNEL's service (K6 — this application never holds a Twilio key and never
 * sees a phone number). Each sender answers [outcome, detail]: outcome 'ok' (detail = the kernel's notification id for a text), 'skipped' (a refusal that is final — detail its code),
 * 'retry' (try again a minute later — detail the reason) or 'unconfigured' (no mail key here). A refusal from the kernel is a SKIP, never a stop; the person's email is a row of its own.
 */

/** The text a person gets by SMS: the notice's own words, cut to the kernel's 480 with the LINK kept (a link cut in half is worse than none). */
function sms_text(string $body): string
{
    $body = trim(preg_replace('/[ \t]+/', ' ', str_replace("\r", '', $body)));
    if (mb_strlen($body) <= 480) {
        return $body;
    }
    if (preg_match_all('#https?://\S+#', $body, $m) && $m[0] !== []) {
        $url = end($m[0]);
        $rest = trim(str_replace($url, '', $body));
        $room = 480 - mb_strlen($url) - 1;
        return rtrim(mb_substr($rest, 0, $room - 1), " ,.;:\n") . '…' . ' ' . $url;
    }
    return mb_substr($body, 0, 479) . '…';
}

/** One email. The sender is MAIL_FROM / MAIL_FROM_NAME; a person with no address on file is a skip. */
function send_email(array $row): array
{
    $to = trim((string) ($row['email'] ?? ''));
    if ($to === '') {
        return ['skipped', 'no_email'];
    }
    if ((string) env('MALUMAIL_API_KEY', '') === '' || (string) env('MAIL_FROM', '') === '') {
        return ['unconfigured', 'MaluMail is not configured'];
    }
    $subject = (string) ($row['subject'] ?? '') !== '' ? (string) $row['subject'] : app_name();
    try {
        $r = malumail_send([
            'from' => (string) env('MAIL_FROM'), 'from_name' => (string) env('MAIL_FROM_NAME', app_name()),
            'to' => $to, 'subject' => $subject,
            'text' => (string) $row['body'], 'html' => view('emails/notice.php', ['subject' => $subject, 'body' => (string) $row['body']]),
        ]);
    } catch (RuntimeException $e) {
        $m = $e->getMessage();
        if (str_contains($m, '(400)')) {
            return ['skipped', 'undeliverable'];                     // suppressed or invalid: permanent — MaluMail will never send it
        }
        return ['retry', preg_match('/\((\d{3})\)/', $m, $c) ? 'malumail_' . $c[1] : 'malumail_unreachable'];
    }
    foreach ($r['rejected'] ?? [] as $rej) {
        return ['skipped', str_starts_with((string) ($rej['reason'] ?? ''), 'suppressed') ? 'suppressed' : 'undeliverable'];
    }
    return ['ok', null];
}

/**
 * One text through the kernel: POST /api/v1/notify/sms.php {member_id, text, reference} with the application's token. 202 → ok (the kernel's notification id). 503 no_sender, 422 not_held /
 * no_verified_phone / opted_out / rate_limited (and any other refusal) → skipped with the code. An unreachable kernel or a 5xx → retry.
 */
function send_sms(array $row): array
{
    $a = kernel_call('POST', '/api/v1/notify/sms.php', ['member_id' => (int) $row['member_id'], 'text' => sms_text((string) $row['body']), 'reference' => (string) ($row['reference'] ?? '') !== '' ? (string) $row['reference'] : 'outbox:' . (int) $row['id']]);
    if ($a === null) {
        return ['retry', 'kernel_unreachable'];
    }
    if ($a['status'] === 202 || $a['status'] === 200) {
        $id = $a['body']['notification']['id'] ?? null;
        return ['ok', is_numeric($id) ? (int) $id : null];
    }
    $code = (string) ($a['body']['error']['code'] ?? '');
    if ($a['status'] >= 500 && $a['status'] !== 503) {
        return ['retry', 'kernel_' . $a['status']];
    }
    if ($a['status'] === 401) {
        return ['retry', 'kernel_unauthorized'];                     // our token is wrong: nothing is final about the person
    }
    return ['skipped', preg_match('/^[a-z_]{2,40}$/', $code) ? $code : 'refused_' . $a['status']];
}

/** Send one queued row and record the outcome (row status + one `notification.send` activity row with no body, no address). Answers the outcome word. */
function deliver_row(PDO $pdo, array $row): string
{
    $channel = (string) $row['channel'];
    if ($row['member_status'] !== 'active') {
        mark_skipped($pdo, (int) $row['id'], 'inactive');
        [$outcome, $detail] = ['skipped', 'inactive'];
    } else {
        [$outcome, $detail] = $channel === 'email' ? send_email($row) : send_sms($row);
        switch ($outcome) {
            case 'ok':
                mark_sent($pdo, (int) $row['id'], $channel === 'sms' ? $detail : null);
                break;
            case 'skipped':
                // A text nobody can receive AND no email address either: nothing reached this person — noted failed, not merely skipped.
                if ($channel === 'sms' && trim((string) ($row['email'] ?? '')) === '') {
                    mark_failed($pdo, (int) $row['id'], 'no email on file and ' . $detail);
                    $outcome = 'failed';
                } else {
                    mark_skipped($pdo, (int) $row['id'], (string) $detail);
                }
                break;
            case 'retry':
                $outcome = mark_retry($pdo, (int) $row['id'], (string) $detail, (int) $row['attempts']);
                break;
            default:
                return 'unconfigured';                                // stays queued, no attempt counted, nothing logged
        }
    }
    $outcome = $outcome === 'ok' ? 'sent' : $outcome;
    log_activity($pdo, 'notification.send', 'notification', (int) $row['id'], ['scope_id' => $row['scope_id'] === null ? null : (int) $row['scope_id'], 'actor_member_id' => null,
        'after' => ['kind' => $row['kind'], 'channel' => $channel, 'outcome' => $outcome, 'code' => $detail !== null && !is_int($detail) ? $detail : null, 'member_id' => (int) $row['member_id']]]);
    return $outcome;
}
