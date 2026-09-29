<?php
/**
 * Proof — agents: the run token acts as its agent's member under the same rights (announcement_post, announcement_read, prefs_save, calendar_feed_rotate); the rows are source agent with the run's request id and the site; an agent is
 * never a recipient; the screens are for people; and announcement_post is registered as `external_send` (the kernel pauses it — Phase 4 proves the pause itself).
 */
require __DIR__ . '/lib.php';
$W = reset6();
$mara = as_member(33);
$key = need('ACTION_TOKEN_KEY'); $relayKey = need('ACTIONS_RELAY_KEY');
$run = fn (int $m, int $runId): string => ($p = $m . '.' . (time() + 300) . '.' . $runId) . '.' . hash_hmac('sha256', 'run:' . $p, $key);
$relay = fn (string $tok): string => hash_hmac('sha256', $tok, $relayKey);
$post = fn (string $path, array $form, array $h): array => (function () use ($path, $form, $h) { $r = req('POST', $path, ['headers' => array_merge(JSONH, $h), 'form' => $form]); return [$r['code'], json_decode($r['body'], true) ?? [], $r]; })();
admin_sql("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (905, 'agent', 'SMOKE Scheduler', 'user', 'active', 'write', '{manager}') ON CONFLICT DO NOTHING;
           INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES (905, 102, 'manager', '{manager}', 'write') ON CONFLICT DO NOTHING;
           INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (906, 'agent', 'SMOKE Helper', 'user', 'active', 'write', '{staff}') ON CONFLICT DO NOTHING;
           INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES (906, 102, 'staff', '{staff}', 'write') ON CONFLICT DO NOTHING;");
kernel_state(function ($s) {
    foreach ([[731, 905], [732, 906]] as [$r, $m]) { $s['facts'][(string) $r] = ['valid' => true, 'is_agent' => true, 'member_id' => $m, 'run_id' => $r, 'request_id' => "req-run-$r", 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; }
    return $s;
});
$hdr = function (int $m, int $r) use ($run, $relay): array { $t = $run($m, $r); return ['X-Action-Token: ' . $t, 'X-Action-Relay: ' . $relay($t)]; };
echo "1. The scheduler agent (announce.post)\n";
$since = last_activity_id();
[$c, $b] = $post('/announcements/save.php', ['site' => 102, 'title' => 'SMOKE Agent post', 'body' => 'From the scheduler.', 'audience' => 'site'], $hdr(905, 731));
$id = (int) ($b['record_id'] ?? 0);
$row = q("SELECT source, agent_run_id, request_id, scope_id, actor_member_id FROM activity_log WHERE action = 'announcement.post' AND id > :s", ['s' => $since])[0] ?? [];
ok($c === 200 && $id > 0 && ($row['source'] ?? '') === 'agent' && (int) $row['agent_run_id'] === 731 && $row['request_id'] === 'req-run-731' && (int) $row['scope_id'] === 102 && (int) $row['actor_member_id'] === 905, 'announcement_post under the run token: 200 (no confirm page for an API caller), source agent, run 731, the run\'s request id, the site');
ok(str_ends_with($b['location'] ?? '', '#announcement-' . $id), 'the reply carries record_id and the location ends in it');
$told = array_map('intval', array_unique(array_column(box("announcement:$id"), 'member_id')));
$kinds = array_map('intval', array_column(q("SELECT id FROM members WHERE member_kind = 'agent'"), 'id'));
ok($told !== [] && array_intersect($told, $kinds) === [] && !in_array(905, $told, true), 'the agents (905, 906, and any other) are not among the ' . count($told) . ' people told — an agent reads through its tools');
echo "2. The staff agent\n";
[$c, $b] = $post('/announcements/save.php', ['site' => 102, 'title' => 'x', 'body' => 'y', 'audience' => 'site'], $hdr(906, 732));
ok($c === 403, 'announcement_post without announce.post: 403');
$since = last_activity_id();
[$c, $b] = $post('/announcements/read.php', ['announcement' => $id], $hdr(906, 732));
$row = q("SELECT source, agent_run_id, scope_id FROM activity_log WHERE action = 'announcement.read' AND id > :s", ['s' => $since])[0] ?? [];
ok($c === 200 && ($row['source'] ?? '') === 'agent' && (int) $row['agent_run_id'] === 732 && (int) one('SELECT count(*) FROM announcement_reads WHERE announcement_id = :a AND member_id = 906', ['a' => $id]) === 1, 'announcement_read: 200, the receipt is the agent\'s own, source agent, run 732');
[$c, $b] = $post('/announcements/remove.php', ['announcement' => $id], $hdr(906, 732));
ok($c === 403, 'announcement_remove by a staff agent: 403');
[$c, $b] = $post('/settings/prefs.php', ['by_sms' => 'no'], $hdr(906, 732));
ok($c === 200 && one('SELECT by_sms FROM notification_prefs WHERE member_id = 906') === false && (int) one('SELECT count(*) FROM notification_prefs WHERE member_id <> 906') === 0, 'prefs_save: the agent\'s own row only');
[$c, $b] = $post('/settings/calendar-feed.php', [], $hdr(906, 732));
ok($c === 200 && preg_match('#/api/v1/calendar/[a-f0-9]{48}\.ics$#', $b['link'] ?? '') === 1 && (int) one('SELECT count(*) FROM calendar_feeds WHERE member_id = 906') === 1, 'calendar_feed_rotate: the agent gets a link in its reply (its own shifts — an agent has none)');
[$c, $b] = $post('/announcements/remove.php', ['announcement' => $id], $hdr(905, 731));
ok($c === 200, 'the posting agent removes its own announcement: 200');
echo "3. Agents use the tools, not the screens\n";
$codes = [];
foreach (['/announcements/?site=102', '/announcements/new?site=102', '/settings/'] as $p) { $codes[] = req('GET', $p, ['headers' => array_merge(JSONH, $hdr(905, 731))])['code']; $codes[] = req('GET', $p, ['headers' => array_merge(JSONH, $hdr(906, 732))])['code']; }
ok(array_unique($codes) === [403], 'the three screens answer an agent 403 (people only): ' . implode(',', $codes));
echo "4. What the kernel is told to pause\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
$bad = [];
foreach (['announcement_post' => ['external_send', 'announcement.post'], 'announcement_remove' => [null, 'announcement.remove'], 'announcement_read' => [null, 'announcement.read'], 'prefs_save' => [null, 'prefs.save'], 'calendar_feed_rotate' => [null, 'calendar_feed.rotate']] as $a => [$ap, $ev]) {
    if (!array_key_exists('approval', $reg[$a]) || $reg[$a]['approval'] !== $ap || ($reg[$a]['log_event'] ?? '') !== $ev || $reg[$a]['built'] !== true) { $bad[] = $a; }
}
ok($bad === [], 'the five actions are built and carry the approval and log event the manifest says (announcement_post: external_send)' . ($bad ? ' — ' . implode(', ', $bad) : ''));
kernel_state(function ($s) { unset($s['facts']); return $s; });
admin_sql("DELETE FROM notification_outbox; DELETE FROM calendar_feeds; DELETE FROM notification_prefs; DELETE FROM announcement_reads; DELETE FROM members WHERE id IN (905, 906) AND FALSE");
finish();
