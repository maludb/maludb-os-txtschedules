<?php
declare(strict_types=1);

/**
 * The mirror's timer (sign-on-and-directory.md §4, scoped-applications.md §2): every minute, GET the change feed
 * since the stored cursor and apply it — members, departments, memberships, then the SITES (scopes[]) and each
 * affected member's holding (access[]). The first run, and --full, read scopes.php first so every restaurant
 * exists before anyone signs in. Advisory-locked; logs directory.sync with the counts (source cron).
 *   php bin/directory_sync.php [--full]
 *   php bin/directory_sync.php --from-file bin/dev_directory.json    a fixture in the kernel's format (testing-without-a-kernel.md)
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';

$opts = getopt('', ['full', 'from-file:']);
$full = isset($opts['full']);
$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('txtschedules_directory_sync'))")->fetchColumn()) {
    echo "another sync holds the lock — skipping.\n";
    exit(0);
}
$state = $pdo->query('SELECT next_cursor FROM directory_sync_state WHERE id = 1')->fetch();
$cursor = $full ? null : ($state['next_cursor'] ?? null);

$docs = [];
if (isset($opts['from-file'])) {
    $fixture = json_decode((string) file_get_contents((string) $opts['from-file']), true);
    if (!is_array($fixture) || !is_array($fixture['feed'] ?? null)) {
        fwrite(STDERR, "the fixture has no feed\n");
        exit(1);
    }
    $docs[] = $fixture['feed'];
} else {
    if ($cursor === null) {
        $scopes = directory_read('scopes.php');
        if ($scopes === null || ($scopes['schema'] ?? '') !== 'os.directory-scopes/1') {
            $pdo->prepare('UPDATE directory_sync_state SET last_run_at = now(), last_error = :e, updated_at = now() WHERE id = 1')
                ->execute(['e' => 'the kernel did not answer scopes.php']);
            fwrite(STDERR, "the kernel did not answer scopes.php\n");
            exit(1);
        }
        $docs[] = ['scopes' => $scopes['scopes'] ?? []];
    }
    $feed = directory_read('changes.php' . ($cursor !== null ? '?since=' . rawurlencode((string) $cursor) : ''));
    if ($feed === null || ($feed['schema'] ?? '') !== 'os.directory-changes/1') {
        $pdo->prepare('UPDATE directory_sync_state SET last_run_at = now(), last_error = :e, updated_at = now() WHERE id = 1')
            ->execute(['e' => 'the kernel did not answer the change feed']);
        log_activity($pdo, 'directory.sync.failed', null, null, ['actor_member_id' => null, 'after' => ['cursor' => $cursor]]);
        fwrite(STDERR, "the kernel did not answer the change feed\n");
        exit(1);
    }
    $docs[] = $feed;
}

$pdo->beginTransaction();
try {
    $counts = ['members' => 0, 'departments' => 0, 'memberships' => 0, 'scopes' => 0, 'access' => 0];
    $next = null;
    $wasFull = false;
    foreach ($docs as $doc) {
        foreach (mirror_apply_feed($pdo, $doc) as $k => $v) {
            $counts[$k] = ($counts[$k] ?? 0) + $v;
        }
        $next = $doc['next'] ?? $next;
        $wasFull = $wasFull || !empty($doc['full']);
    }
    $pdo->prepare('UPDATE directory_sync_state SET next_cursor = COALESCE(:n, next_cursor), full_at = CASE WHEN :full THEN now() ELSE full_at END,
                          scopes_at = CASE WHEN :scoped THEN now() ELSE scopes_at END, last_run_at = now(), last_error = NULL, updated_at = now() WHERE id = 1')
        ->execute(['n' => $next, 'full' => $wasFull ? 't' : 'f', 'scoped' => $cursor === null ? 't' : 'f']);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('directory_sync: ' . $e->getMessage());
    $pdo->prepare('UPDATE directory_sync_state SET last_run_at = now(), last_error = :e, updated_at = now() WHERE id = 1')->execute(['e' => 'apply failed']);
    exit(1);
}
if (array_sum($counts) > 0 || $wasFull) {
    log_activity($pdo, 'directory.sync', null, null, ['actor_member_id' => null, 'scope_id' => null, 'after' => $counts + ['full' => $wasFull]]);
}
printf("applied %d members, %d departments, %d memberships, %d sites, %d holdings%s; cursor %s\n", $counts['members'], $counts['departments'],
    $counts['memberships'], $counts['scopes'], $counts['access'], $wasFull ? ' (full)' : '', $next ?? '(unchanged)');
