<?php
/**
 * Helpers for the Phase 3 slice 7 proofs (docs/build-specs/settings-rules-reports.md, "Proof"). Run through tests/phase3/slice7/run.sh: a fresh SCRATCH database (never the installed one), the application on
 * :8191, a fake kernel and a fake MaluDB that also stands in for MaluMail. Everything a proof makes is named "SMOKE …"; nothing real is sent. Builds on slice 6's helpers.
 * Every proof starts with reset7(): both restaurants' settings and rules as they ship, no SMOKE day-parts, no proof overrides; nothing depends on the clock's fixed offsets (weeks come from wk(n)); published
 * shifts are never deleted, so earlier proofs' SMOKE shifts are cancelled.
 */
require dirname(__DIR__) . '/slice6/lib.php';

/** Both restaurants' settings back to what materialisation gives them, the rules to the Generic preset, the proof's day-parts gone. */
function reset7(): array
{
    $W = reset6();
    admin_sql("UPDATE site_settings ss SET week_start = a.default_week_start, currency = a.default_currency, time_off_day_hours = a.default_time_off_day_hours,
                      allow_offer = true, allow_pickup = true, allow_swap = true, allow_give = true, approval_pickup = 'on_warning', approval_swap = 'on_warning', approval_give = 'on_warning',
                      cutoff_minutes = 120, shift_lead_approves_same_day = true, claim_mode = 'first', offer_expires = 'at_start', availability_needs_approval = true, reminder_minutes_before = 120,
                      overtime_weekly_hours = 40, overtime_multiplier = 1.5, rule_preset = 'generic' FROM app_settings a WHERE a.id = 1;
               DELETE FROM site_rules;
               INSERT INTO site_rules (scope_id, rule_key, severity, params) SELECT s.scope_id, e.key, e.value->>'severity', COALESCE(e.value->'params', '{}'::jsonb)
                 FROM sites s, rule_presets rp, jsonb_each(rp.rules) e WHERE rp.key = 'generic' AND s.removed_at IS NULL;
               DELETE FROM rule_overrides WHERE reason LIKE 'SMOKE%' OR reason LIKE '=SMOKE%';
               DELETE FROM forecast_covers WHERE day_part_id IN (SELECT id FROM day_parts WHERE name LIKE 'SMOKE%');
               DELETE FROM day_parts WHERE name LIKE 'SMOKE%' OR key LIKE 'smoke%';
               UPDATE day_parts SET archived_at = NULL, name = CASE key WHEN 'lunch' THEN 'Lunch' WHEN 'dinner' THEN 'Dinner' ELSE name END, starts_at = CASE key WHEN 'lunch' THEN '11:00' WHEN 'dinner' THEN '17:00' ELSE starts_at END,
                      ends_at = CASE key WHEN 'lunch' THEN '15:00' WHEN 'dinner' THEN '22:00' ELSE ends_at END, sort_order = CASE key WHEN 'lunch' THEN 1 WHEN 'dinner' THEN 2 ELSE sort_order END,
                      service_name = CASE key WHEN 'lunch' THEN 'Lunch' WHEN 'dinner' THEN 'Dinner' ELSE service_name END WHERE key IN ('lunch', 'dinner');
               UPDATE shifts SET status = 'cancelled', cancelled_at = now() WHERE note LIKE 'SMOKE s7 %' AND status = 'scheduled';
               DELETE FROM activity_log WHERE action IN ('settings.update', 'day_part.save', 'day_part.archive', 'rule.update', 'rule.preset', 'report.export') AND actor_member_id IS NULL;");
    return $W;
}

/** The settings row of a restaurant, as text values (a fixture peek). */
function site_row(int $site): array { return q('SELECT * FROM site_settings WHERE scope_id = :s', ['s' => $site])[0] ?? []; }
function rule_of(int $site, string $key): array { $r = q('SELECT severity, params FROM site_rules WHERE scope_id = :s AND rule_key = :k', ['s' => $site, 'k' => $key])[0] ?? []; return $r ? ['severity' => $r['severity'], 'params' => json_decode($r['params'], true)] : []; }
/** A form post to the settings handler as a jar, at a restaurant. */
function set(string $jar, int $site, array $f): array { return act($jar, '/site/save.php', ['site' => $site] + $f); }
function rule(string $jar, int $site, string $key, string $sev, array $params = []): array { return act($jar, '/rules/save.php', ['site' => $site, 'rule' => $key, 'severity' => $sev] + ($params ? ['params' => $params] : [])); }
/** The admin of Airport (the owner, a super-admin holds admin at every site) and Downtown's manager who is NOT an admin. */
function admin_jar(int $site = 102): string { return as_member(1, $site); }
/** A settings screen's JSON (as a jar). */
function settings_json(string $jar, int $site): array { [, $d] = screen($jar, "/site/?site=$site"); return $d['settings'] ?? []; }
/** Parse a CSV text into rows of cells (RFC 4180, CRLF). */
function parse_csv(string $text): array
{
    $rows = []; $row = []; $cell = ''; $q = false; $n = strlen($text);
    for ($i = 0; $i < $n; $i++) {
        $c = $text[$i];
        if ($q) { if ($c === '"') { if (($text[$i + 1] ?? '') === '"') { $cell .= '"'; $i++; } else { $q = false; } } else { $cell .= $c; } }
        elseif ($c === '"') { $q = true; }
        elseif ($c === ',') { $row[] = $cell; $cell = ''; }
        elseif ($c === "\r") { }
        elseif ($c === "\n") { $row[] = $cell; $rows[] = $row; $row = []; $cell = ''; }
        else { $cell .= $c; }
    }
    if ($cell !== '' || $row !== []) { $row[] = $cell; $rows[] = $row; }
    return $rows;
}

/** Cancel a person's scheduled shifts within $pad hours of a window starting $h hours from now and $len long — so a fixture never collides with (or warns about) the fixture's own shifts, whatever the clock says. */
function clear_for(int $member, float $h, float $len = 3, int $pad = 12): void
{
    $a = floor((time() + $h * 3600) / 900) * 900;
    admin_sql("UPDATE shifts SET status = 'cancelled', cancelled_at = now() WHERE assignee_member_id = $member AND status = 'scheduled'
               AND tstzrange(starts_at - interval '$pad hours', ends_at + interval '$pad hours') && tstzrange(to_timestamp($a), to_timestamp(" . ($a + (int) ($len * 3600)) . "))");
}
/** A published shift $h hours from now for a person, with nothing of theirs within twelve hours of it. */
function t7_shift(int $site, int $pos, int $member, float $h, float $len = 3, int $pad = 12): int
{
    clear_for($member, $h, $len, $pad);
    return mkshift($site, $pos, $member, $h, $len, ['note' => 'SMOKE s7 ' . run_id(), 'break' => 0]);
}
/** A Downtown-only ADMIN (id 38) — the restaurant's own administrator, not a super-admin. */
function dex_claims(): array
{
    $c = cast()['34'];
    $c['display_name'] = 'SMOKE Dex'; $c['email'] = 'dex@example.invalid'; $c['role'] = 'admin'; $c['roles'] = ['admin']; $c['capability'] = 'admin';
    foreach ($c['scopes'] as &$s) { $s['role'] = 'admin'; $s['roles'] = ['admin']; $s['capability'] = 'admin'; }
    return $c;
}
function as_dex(): string { [$j, $r] = sign_on(38, null, ['claims' => dex_claims()]); if ($r['code'] !== 302) { fwrite(STDERR, "Dex sign-on failed {$r['code']}\n"); } return $j; }
/** What the preview endpoint says for these form values (as a jar): the sentence text. */
function preview_of(string $jar, int $site, array $f): string
{
    $r = req('GET', '/site/?' . http_build_query(['site' => $site, 'preview' => '1'] + $f), ['jar' => $jar]);
    return trim(html_entity_decode(strip_tags($r['body'])));
}
/** The banner sentence the settings PAGE shows (as a jar). */
function sentence_on_page(string $jar, int $site): string
{
    preg_match('/id="site-settings-sentence">([^<]*)</', page($jar, "/site/?site=$site")['body'], $m);
    return html_entity_decode($m[1] ?? '');
}

/** A query as the RECORDS role (what the read tools see) for a member: the views' own numbers, to hold a report against. */
function recq(int $member, string $sql): array
{
    static $p = null;
    $p ??= new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec("SELECT set_config('app.member_id', '$member', false)");
    return $p->query($sql)->fetchAll();
}
/** A report as JSON (as a jar): [code, the `result` block, the whole data]. */
function rep(string $jar, int $site, string $report, string $from, string $to, string $more = ''): array
{
    [$c, $d] = screen($jar, "/reports/?site=$site&report=$report&from=$from&to=$to$more");
    return [$c, $d['result'] ?? [], $d];
}
/** The rows of a report result as [column key => value] lists, and one column of them. */
function col_of(array $result, string $key): array { return array_column($result['rows'] ?? [], $key); }
/** A report as a CSV download (as a jar). */
function csv_of(string $jar, int $site, string $report, string $from, string $to): array
{
    $r = req('GET', "/reports/?site=$site&report=$report&from=$from&to=$to&format=csv", ['jar' => $jar]);
    return [$r['code'], $r['code'] === 200 ? parse_csv($r['body']) : [], $r];
}
/** A date $n days from a date (n may be negative). */
function plus(string $date, int $n): string { return (new DateTimeImmutable($date))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d'); }
