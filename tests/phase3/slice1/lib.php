<?php
/**
 * Helpers for the Phase 3 slice 1 proofs (docs/build-specs/shifts-marketplace.md, "Proof"). Run through tests/phase3/slice1/run.sh: a fresh
 * SCRATCH database (never the installed one), the application on :8191, a fake kernel and a fake MaluDB. Everything a proof makes is named
 * "SMOKE …"; nothing is sent (the outbox only queues). Builds on tests/phase2/lib.php (req, ok, q, one, jar, kernel_state …).
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

const JSONH = ['Accept: application/json'];

/** The cast: the Phase 2 fixture's four, plus the people slice 1 needs (claims only — they sign on and become members, as in production). */
function cast(): array
{
    static $c = null;
    if ($c !== null) { return $c; }
    $c = fixture()['claims'];
    $mk = static fn (string $name, string $role, string $cap, array $sites): array => [
        'display_name' => "SMOKE $name", 'email' => strtolower($name) . '@example.invalid', 'business_role' => 'user', 'is_external' => false, 'status' => 'active',
        'departments' => [], 'capability' => $cap, 'role' => $role, 'roles' => [$role],
        'scopes' => array_map(static fn (array $s): array => ['scope_id' => $s[0], 'kind' => 'location', 'id' => $s[0] - 90, 'name' => $s[1], 'role' => $role, 'roles' => [$role], 'capability' => $cap], $sites)];
    $airport = [102, 'SMOKE Airport'];
    $downtown = [101, 'SMOKE Downtown'];
    $c['30'] = $mk('Ana', 'staff', 'write', [$airport]);
    $c['31'] = $mk('Lee', 'staff', 'write', [$airport]);
    $c['32'] = $mk('Dana', 'shift_lead', 'write', [$airport]);
    $c['33'] = $mk('Mara', 'manager', 'write', [$airport]);
    $c['34'] = $mk('Joe', 'staff', 'write', [$downtown]);
    return $c;
}
const NAMES = [1 => 'Owner', 26 => 'Priya', 27 => 'Marco', 28 => 'Sam', 30 => 'Ana', 31 => 'Lee', 32 => 'Dana', 33 => 'Mara', 34 => 'Joe'];

/** A signed-on jar for a cast member, at a site. */
function as_member(int $m, ?int $scope = null): string { [$j, $r] = sign_on($m, $scope, ['claims' => cast()[(string) $m]]); if ($r['code'] !== 302) { fwrite(STDERR, "sign-on of $m failed: {$r['code']}\n"); } return $j; }

/** A write over HTTP as a signed-on person, answered as JSON: [status, decoded body]. CSRF token fetched from the shell. */
function act(string $jar, string $path, array $form, array $extraHeaders = []): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $r = req('POST', $path, ['jar' => $jar, 'headers' => array_merge(JSONH, $extraHeaders), 'form' => $form + ['csrf_token' => $tok[$jar]]]);
    return [$r['code'], json_decode($r['body'], true) ?? [], $r];
}
/** A screen as JSON (data only). */
function screen(string $jar, string $path): array { $r = req('GET', $path, ['jar' => $jar, 'headers' => JSONH]); return [$r['code'], json_decode($r['body'], true)['data'] ?? [], $r]; }
function msg(array $body): string { return (string) ($body['error']['message'] ?? ''); }

/** Local Monday on or before a moment in a zone (the week's start, as the builder will store it). */
function monday_of(DateTimeInterface $t, string $tz): string { $d = DateTimeImmutable::createFromInterface($t)->setTimezone(new DateTimeZone($tz)); return $d->modify('-' . (((int) $d->format('w') + 6) % 7) . ' days')->format('Y-m-d'); }

/** A published (or draft) shift, $h hours from now for $len hours, in a week row made on the way. Returns its id. */
function mkshift(int $site, int $pos, ?int $member, float $h, float $len = 6, array $o = []): int
{
    $tz = (string) one('SELECT timezone FROM sites WHERE scope_id = :s', ['s' => $site]);
    $start = new DateTimeImmutable('@' . (int) (floor((time() + $h * 3600) / 900) * 900));
    $ws = monday_of($start, $tz);
    $status = ($o['draft'] ?? false) ? 'draft' : 'published';
    $week = one("INSERT INTO schedule_weeks (scope_id, week_start, status, published_at) VALUES (:s, :w, :st, CASE WHEN :st2 = 'published' THEN now() END)
                 ON CONFLICT (scope_id, week_start) DO UPDATE SET status = schedule_weeks.status RETURNING id", ['s' => $site, 'w' => $ws, 'st' => $status, 'st2' => $status]);
    return (int) one('INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at, break_minutes, assignee_member_id, note) VALUES (:s, :w, :p, :a, :b, :br, :m, :n) RETURNING id',
        ['s' => $site, 'w' => $week, 'p' => $pos, 'a' => $start->format('c'), 'b' => $start->modify('+' . (int) ($len * 60) . ' minutes')->format('c'), 'br' => $o['break'] ?? 30, 'm' => $member, 'n' => $o['note'] ?? null]);
}

/** The whole fixture (idempotent per scratch database): everyone signed on once, positions, rates, staff positions, shifts. Returns the ids. */
function world(): array
{
    static $W = null;
    if ($W !== null) { return $W; }
    $file = need('TS_DEV_STATE') . '/world.json';
    if (is_file($file)) { return $W = json_decode((string) file_get_contents($file), true); }
    foreach (array_keys(NAMES) as $m) { as_member($m, $m === 27 ? 101 : null); }
    $pos = fn (int $site, string $name, string $color, string $rate): int => (int) one('INSERT INTO positions (scope_id, name, color, default_wage_rate) VALUES (:s, :n, :c, :r) RETURNING id', ['s' => $site, 'n' => $name, 'c' => $color, 'r' => $rate]);
    $W = ['aSrv' => $pos(102, 'Server', '#3454d1', '21.37'), 'aBar' => $pos(102, 'Bar', '#e83e8c', '23.19'), 'dSrv' => $pos(101, 'Server', '#17a2b8', '22.83')];
    $sp = fn (int $m, int $p, ?string $own = null) => q('INSERT INTO staff_positions (member_id, position_id, wage_override) VALUES (:m, :p, :o) ON CONFLICT DO NOTHING', ['m' => $m, 'p' => $p, 'o' => $own]);
    $sp(26, $W['aSrv'], '24.61'); $sp(30, $W['aSrv']); $sp(30, $W['aBar']); $sp(31, $W['aSrv']); $sp(32, $W['aSrv']); $sp(33, $W['aSrv']); $sp(27, $W['aSrv']);
    $sp(27, $W['dSrv']); $sp(34, $W['dSrv']); $sp(28, $W['dSrv']); $sp(1, $W['dSrv']); $sp(1, $W['aSrv']);
    // Priya
    $W['p1'] = mkshift(102, $W['aSrv'], 26, 50);          // offered → taken
    $W['p2'] = mkshift(102, $W['aSrv'], 26, 74);          // given
    $W['p3'] = mkshift(102, $W['aSrv'], 26, 98);          // given, refused
    $W['p4'] = mkshift(102, $W['aSrv'], 26, 146);         // swapped
    $W['p5'] = mkshift(102, $W['aSrv'], 26, 1, 4);        // inside the cutoff
    $W['p6'] = mkshift(102, $W['aSrv'], 26, 130);         // near Lee's shift (min rest)
    $W['pdraft'] = mkshift(102, $W['aSrv'], 26, 24 * 125, 6, ['draft' => true]);
    // Ana
    $W['a1'] = mkshift(102, $W['aSrv'], 30, 52);          // overlaps p1
    $W['a2'] = mkshift(102, $W['aSrv'], 30, 110);
    $W['anb'] = mkshift(102, $W['aBar'], 30, 140);        // a Bar shift (Lee does not work Bar)
    $W['sameday'] = mkshift(102, $W['aSrv'], 30, 6);      // starts today or tomorrow
    $W['nextweek'] = mkshift(102, $W['aSrv'], 30, 200);
    // Lee, Dana, Mara
    $W['l1'] = mkshift(102, $W['aSrv'], 31, 120);
    $W['l2'] = mkshift(102, $W['aSrv'], 31, 156);
    $W['d1'] = mkshift(102, $W['aSrv'], 32, 80);
    // open shifts
    $W['o1'] = mkshift(102, $W['aSrv'], null, 60);
    $W['o2'] = mkshift(102, $W['aSrv'], null, 70);
    $W['o3'] = mkshift(102, $W['aSrv'], null, 300);
    // Downtown and Marco
    $W['j1'] = mkshift(101, $W['dSrv'], 34, 50);
    $W['m1'] = mkshift(101, $W['dSrv'], 27, 30);
    $W['m2'] = mkshift(102, $W['aSrv'], 27, 100);
    $W['dtOffer'] = mkshift(101, $W['dSrv'], 34, 90);
    file_put_contents($file, json_encode($W));
    return $W;
}

/** What one page of the slice must never contain: a fixture wage. */
function wages(): array { return ['21.37', '24.61', '23.19', '22.83']; }
function leaks(string $body): array { return array_values(array_filter(wages(), fn (string $w) => str_contains($body, $w))); }
function tomorrow_tz(string $tz): string { return (new DateTimeImmutable('now', new DateTimeZone($tz)))->modify('+1 day')->format('Y-m-d'); }

/** Outbox rows for an exchange, and the set of members told. */
function outbox(int $exchange, ?string $kind = null): array { return q('SELECT * FROM notification_outbox WHERE reference = :r' . ($kind ? ' AND kind = :k' : '') . ' ORDER BY id', ['r' => "exchange:$exchange"] + ($kind ? ['k' => $kind] : [])); }
function told(int $exchange): array { $m = array_values(array_unique(array_map(fn ($r) => (int) $r['member_id'], outbox($exchange)))); sort($m); return $m; }
function settings(int $site, array $set): void { foreach ($set as $k => $v) { q("UPDATE site_settings SET $k = :v WHERE scope_id = :s", ['v' => is_bool($v) ? ($v ? 't' : 'f') : $v, 's' => $site]); } }

/** A holder offers a shift over HTTP; returns the exchange id (fails loudly). */
function offer(string $jar, int $shift, ?string $note = null): int { [$c, $b] = act($jar, '/exchanges/offer.php', ['shift' => $shift] + ($note ? ['note' => $note] : [])); if ($c !== 200) { fwrite(STDERR, "offer of $shift failed: " . msg($b) . "\n"); return 0; } return (int) $b['record_id']; }
function claim(string $jar, int $x): array { return act($jar, '/exchanges/claim.php', ['exchange' => $x]); }
function status_of(int $x): string { return (string) one('SELECT status FROM exchanges WHERE id = :i', ['i' => $x]); }
function holder_of(int $shift): ?int { $v = one('SELECT assignee_member_id FROM shifts WHERE id = :i', ['i' => $shift]); return $v === null ? null : (int) $v; }
function card_of(string $jar, int $x, string $tab = 'grabs'): ?array { [, $d] = screen($jar, '/marketplace?tab=' . $tab); foreach ($d['exchanges'] ?? [] as $e) { if ($e['exchange_id'] === $x) { return $e; } } return null; }
/** The Server position at Airport (id) for fresh shifts. */
function srv(): int { return (int) world()['aSrv']; }

/** Fixture SQL as the database owner (postgres) — for tables the application role cannot write yet (time off arrives in slice 3, settings in slice 7). Scratch database only. */
function admin_sql(string $sql): string { return (string) shell_exec('sudo -n -u postgres psql -v ON_ERROR_STOP=1 -qAt -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg($sql) . ' 2>&1'); }
