<?php
declare(strict_types=1);

/**
 * The notifications worker (slice 6, docs/build-specs/announcements-notifications.md) — the timer runs it every minute, one pass:
 *   1 expire   offers and asks past their time (ts_exchanges_expire()), the holder told once
 *   2 remind   shifts starting inside the holder's lead time → one reminder per channel the person has on (never twice)
 *   3 certs    a manager is told when a card enters its warning days and when it has run out
 *   4 send     every queued row whose time has come: email through MaluMail, text through the KERNEL (K6). A refusal is a skip, never a stop.
 *   5 forecast once a day, each restaurant's next 14 days of booked covers from Reservations (K7)
 * Every step is its own try: one failing never stops the others. Advisory-locked (a slow pass is not overlapped). Nothing is sent for an inactive person.
 *   php bin/notifications.php [--only=expire,remind,certs,send,forecast] [--limit=100]
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/notify/queue.php';
require_once dirname(__DIR__) . '/app/features/notify/send.php';
require_once dirname(__DIR__) . '/app/features/notify/remind.php';

$opts = getopt('', ['only::', 'limit::']);
$only = isset($opts['only']) && $opts['only'] !== false ? array_filter(explode(',', (string) $opts['only'])) : ['expire', 'remind', 'certs', 'send', 'forecast'];
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 100;
$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('txtschedules_notifications'))")->fetchColumn()) {
    echo "another pass holds the lock — skipping.\n";
    exit(0);
}
$report = [];
$step = static function (string $name, callable $run) use (&$report, $only): void {
    if (!in_array($name, $only, true)) {
        return;
    }
    try {
        $report[$name] = $run();
    } catch (Throwable $e) {
        error_log('notifications ' . $name . ': ' . $e->getMessage());
        $report[$name] = 'error';
    }
};
$step('expire', static fn () => expire_exchanges($pdo));
$step('remind', static fn () => queue_reminders($pdo));
$step('certs', static fn () => queue_certification_warnings($pdo));
$step('send', static function () use ($pdo, $limit): array {
    $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'queued' => 0, 'unconfigured' => 0];
    foreach (queued_batch($pdo, $limit) as $row) {
        $o = deliver_row($pdo, $row);
        $out[match ($o) { 'ok', 'sent' => 'sent', 'skipped' => 'skipped', 'failed' => 'failed', 'unconfigured' => 'unconfigured', default => 'queued' }]++;
    }
    return $out;
});
$step('forecast', static fn () => daily_forecast_fill($pdo));
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
