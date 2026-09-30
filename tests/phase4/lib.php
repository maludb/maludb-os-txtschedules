<?php
/**
 * Helpers for the Phase 4 proofs (docs/txtschedules-mcp-tool-surface.md; design §10, §13). Run through tests/phase4/run.sh: a fresh SCRATCH database
 * (never the installed one), the application on :8191, a fake kernel on :8192 (run-facts, K6, K7), and the TWO MCP SERVERS on :8194 (records) and :8195
 * (activity) — plus a third records server on :8196 whose kernel URL is dead (the gate must fail closed). Everything a proof makes is named "SMOKE …".
 * Builds on Phase 3's helpers (the cast, the world, reset7()).
 */
require dirname(__DIR__) . '/phase3/slice7/lib.php';

const REC = 8194;
const ACT = 8195;
const REC_DEAD = 8196;

/** The world, as slice 7 leaves it, with this run's own leftovers gone. */
function reset_p4(): array
{
    $W = reset7();
    admin_sql("UPDATE shifts SET status = 'cancelled', cancelled_at = now() WHERE note LIKE 'SMOKE p4 %' AND status = 'scheduled';
               DELETE FROM time_off_requests WHERE note LIKE 'SMOKE p4%';
               DELETE FROM blackout_dates WHERE reason LIKE 'SMOKE p4%';");
    admin_sql("INSERT INTO members (id, member_kind, display_name, email, business_role, status, capability, roles) VALUES (29, 'human', 'SMOKE Nobody', 'nobody@example.invalid', 'user', 'active', 'write', '{}') ON CONFLICT (id) DO NOTHING; DELETE FROM member_site_roles WHERE member_id = 29");   // no restaurant at all
    as_planner();                                  // Pat (35): builds at Airport, no labor.view — makes the member
    return $W;
}

// ---- tokens ------------------------------------------------------------------------------------------------------
/** A person's own mcp_ token (the servers resolve it through mcp_resolve_token). Cached per member. */
function person_token(int $member): string
{
    static $t = [];
    if (isset($t[$member])) { return $t[$member]; }
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    admin_sql("INSERT INTO mcp_access_tokens (member_id, label, token_hash) VALUES ($member, 'SMOKE p4', '" . hash('sha256', $raw) . "')");
    return $t[$member] = $raw;
}
/** The tenant's signed RUN token an agent presents ('mid.exp.run.hmac' over run:mid.exp.run). */
function run_token(int $member, int $run, int $ttl = 300): string
{
    $p = $member . '.' . (time() + $ttl) . '.' . $run;
    return $p . '.' . hash_hmac('sha256', 'run:' . $p, need('ACTION_TOKEN_KEY'));
}
/** A person's action token (the command bar's): 'mid.exp.hmac'. */
function action_token(int $member, int $ttl = 300): string
{
    $p = $member . '.' . (time() + $ttl);
    return $p . '.' . hash_hmac('sha256', $p, need('ACTION_TOKEN_KEY'));
}
/** The kernel's own 60-second token: 'kernel.exp.app.nonce.hmac' over kernel:exp.app.nonce. */
function kernel_token(string $app = 'txtschedules', int $ttl = 60): string
{
    $p = (time() + $ttl) . '.' . $app . '.' . bin2hex(random_bytes(16));
    return 'kernel.' . $p . '.' . hash_hmac('sha256', 'kernel:' . $p, need('ACTION_TOKEN_KEY'));
}
/** Tell the fake kernel what the run-facts call answers for a run id. $endpoints: [name => [tool => constraints]] or [] for another application's. */
function facts(int $run, int $member, array $endpoints, array $o = []): void
{
    kernel_state(function ($s) use ($run, $member, $endpoints, $o) {
        $eps = [];
        foreach ($endpoints as $name => $tools) { $eps[] = ['id' => 1, 'name' => $name, 'tools' => (object) $tools]; }
        $s['facts'][(string) $run] = ($o['raw'] ?? null) ?: ['valid' => $o['valid'] ?? true, 'is_agent' => $o['is_agent'] ?? true, 'member_id' => $member, 'run_id' => $run, 'request_id' => "req-run-$run",
            'trigger' => $o['trigger'] ?? 'chat', 'is_eval' => ($o['trigger'] ?? '') === 'eval', 'endpoints' => $eps];
        return $s;
    });
}
/** An agent: a mirror row with roles at sites. $roles: [site => role]. capability null = not yet admitted (the first-contact path admits it). */
function make_agent(int $id, string $name, array $roles, ?string $cap = 'write'): void
{
    $capSql = $cap === null ? 'NULL' : "'$cap'";
    admin_sql("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES ($id, 'agent', '$name', 'user', 'active', $capSql, '{}')
               ON CONFLICT (id) DO UPDATE SET capability = $capSql, status = 'active';
               DELETE FROM member_site_roles WHERE member_id = $id;");
    foreach ($roles as $site => $role) {
        admin_sql("INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES ($id, $site, '$role', '{" . $role . "}', '" . ($role === 'admin' ? 'admin' : 'write') . "')");
    }
}

// ---- the MCP client (the kernel's own: initialize, initialized, then the call) ------------------------------------
/** One JSON-RPC message; answers [decoded message|null, session id, http status]. Reads plain JSON or a short event stream. */
function mcp_post(int $port, string $token, array $msg, ?string $session = null, string $path = '/mcp'): array
{
    $h = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-06-18'];
    if ($token !== '') { $h[] = 'Authorization: Bearer ' . $token; }
    if ($session) { $h[] = 'Mcp-Session-Id: ' . $session; }
    $sess = $session;
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($msg), CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$sess) { if (stripos($line, 'mcp-session-id:') === 0) { $sess = trim(substr($line, 15)); } return strlen($line); }]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if (!is_string($body) || $body === '') { return [null, $sess, $status]; }
    if (stripos($type, 'text/event-stream') !== false) {
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (str_starts_with($line, 'data:')) { $m = json_decode(trim(substr($line, 5)), true); if (is_array($m) && ($m['id'] ?? null) === ($msg['id'] ?? null)) { return [$m, $sess, $status]; } }
        }
        return [null, $sess, $status];
    }
    return [json_decode($body, true), $sess, $status];
}
/** Open a session as a bearer: [session id, http status of initialize]. */
function mcp_open(int $port, string $token, string $path = '/mcp'): array
{
    [$init, $sess, $st] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'p4', 'version' => '1']]], null, $path);
    if ($st === 200) { mcp_post($port, $token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sess, $path); }
    return [$sess, $st];
}
/** The tool names a bearer is offered, or null when the server refused it (401). */
function mcp_tools(int $port, string $token, string $path = '/mcp'): ?array
{
    [$sess, $st] = mcp_open($port, $token, $path);
    if ($st !== 200) { return null; }
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess, $path);
    $names = array_map(fn ($t) => $t['name'], $a['result']['tools'] ?? []);
    sort($names);
    return $names;
}
/**
 * Call a tool as a bearer. Answers ['http' => status, 'error' => bool (the tool refused), 'text' => raw text, 'data' => decoded JSON or null].
 * Arguments are wrapped as {"params": ...} the way FastMCP's models take them, except the kernel's flat call ($flat).
 */
function mcp_tool(int $port, string $token, string $tool, array $args = [], bool $flat = false, string $path = '/mcp'): array
{
    [$sess, $st] = mcp_open($port, $token, $path);
    if ($st !== 200) { return ['http' => $st, 'error' => true, 'text' => '', 'data' => null]; }
    $arguments = $flat || $args === [] && in_array($tool, ['app_roles'], true) ? (object) $args : ['params' => (object) $args];
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], $sess, $path);
    $res = $a['result'] ?? [];
    $text = (string) ($res['content'][0]['text'] ?? ($a['error']['message'] ?? ''));
    $data = json_decode($text, true);
    return ['http' => 200, 'error' => !empty($res['isError']) || isset($a['error']) || (is_array($data) && isset($data['error']) && count($data) <= 2), 'text' => $text, 'data' => is_array($data) ? $data : null];
}
/** A tool as a person (their own token): the decoded answer. */
function tool(int $member, string $name, array $args = [], int $port = REC): array { return mcp_tool($port, person_token($member), $name, $args); }
function tdata(int $member, string $name, array $args = [], int $port = REC) { return tool($member, $name, $args, $port)['data']; }

// ---- fixtures -----------------------------------------------------------------------------------------------------
/** The local date (Y-m-d) of the next weekday (1 Monday .. 7 Sunday) strictly after today in a restaurant's zone. */
function next_dow(string $tz, int $dow): string
{
    $d = new DateTimeImmutable('now', new DateTimeZone($tz));
    do { $d = $d->modify('+1 day'); } while ((int) $d->format('N') !== $dow);
    return $d->format('Y-m-d');
}
/** A shift on a local date at a local time, in a restaurant's zone (published unless $o['draft']). Cancels nothing; returns the id. */
function local_shift(int $site, int $pos, ?int $member, string $date, string $from, string $to, array $o = []): int
{
    $tz = (string) one('SELECT timezone FROM sites WHERE scope_id = :s', ['s' => $site]);
    $start = new DateTimeImmutable("$date $from", new DateTimeZone($tz));
    $end = new DateTimeImmutable("$date $to", new DateTimeZone($tz));
    if ($end <= $start) { $end = $end->modify('+1 day'); }
    $ws = monday_of($start, $tz);
    $draft = $o['draft'] ?? false;
    $week = one("INSERT INTO schedule_weeks (scope_id, week_start, status, published_at) VALUES (:s, :w, :st, CASE WHEN :st2 = 'published' THEN now() END)
                 ON CONFLICT (scope_id, week_start) DO UPDATE SET status = schedule_weeks.status RETURNING id", ['s' => $site, 'w' => $ws, 'st' => $draft ? 'draft' : 'published', 'st2' => $draft ? 'draft' : 'published']);
    return (int) one('INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at, break_minutes, assignee_member_id, note, published_at) VALUES (:s, :w, :p, :a, :b, :br, :m, :n, CASE WHEN :d THEN NULL ELSE now() END) RETURNING id',
        ['s' => $site, 'w' => $week, 'p' => $pos, 'a' => $start->format('c'), 'b' => $end->format('c'), 'br' => $o['break'] ?? 0, 'm' => $member, 'n' => $o['note'] ?? 'SMOKE p4 ' . run_id(), 'd' => $draft ? 't' : 'f']);
}
/** Cancel every scheduled shift at a site that touches a local date (so a proof's Friday holds only what it made). */
function clear_day(int $site, string $date): void
{
    admin_sql("UPDATE shifts s SET status = 'cancelled', cancelled_at = now() FROM sites st WHERE st.scope_id = s.scope_id AND s.scope_id = $site AND s.status = 'scheduled'
               AND s.starts_at < (('$date'::date + 1)::timestamp AT TIME ZONE st.timezone) AND s.ends_at > ('$date'::date::timestamp AT TIME ZONE st.timezone);");
}
/** The strings a decoded answer carries anywhere (values and keys), flattened — what a grep over the text would find. */
function walk($v, callable $f, string $path = ''): void
{
    if (is_array($v)) { foreach ($v as $k => $x) { walk($x, $f, $path . '.' . $k); } return; }
    $f($path, $v);
}
/** The pay keys of every answer: any of these, non-null, is a rate, a cost or a budget. */
const PAY_KEYS = ['wage_rate', 'wage_override', 'default_wage_rate', 'effective_rate', 'cost', 'scheduled_cost', 'budget_amount', 'cost_over_budget', 'budget_hours', 'hours_over_budget'];
/** Every pay value an answer carries: [path => value] for a pay key that is not null. */
function pay_in($data): array
{
    $out = [];
    walk($data, function ($path, $v) use (&$out) {
        $key = substr($path, (int) strrpos($path, '.') + 1);
        if (in_array($key, PAY_KEYS, true) && $v !== null) { $out[$path] = $v; }
    });
    return $out;
}
/** The four fixture rates and this proof's own budget number, as text: must never appear in an answer to a caller without labor.view. */
function pay_strings(): array { return array_merge(wages(), ['4321.09', '187.5']); }
