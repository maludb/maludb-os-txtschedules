<?php
declare(strict_types=1);
/**
 * /activity — the trail the caller may see at the current restaurant (screen `activity`): their own rows, and every
 * row where they build schedules (mcp_activity_log). Filters: action prefix, period. Pattern B on #activity-results.
 * JSON: the rows with their sentences.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/activity/queries.php';
require_login();
$pdo = db();
$site = current_site();
$filters = ['site' => $site['scope_id'] ?? null, 'action' => trim(request_string('action')), 'since' => request_integer('since'), 'member' => null];
if (!preg_match('/^[a-z_]+(\.[a-z_]+)*\.?$/', $filters['action'])) { $filters['action'] = ''; }
if (!in_array($filters['since'], [1, 7, 30, 90], true)) { $filters['since'] = null; }
$page = max(1, (int) (request_integer('page') ?? 1));
$result = find_activity($pdo, $filters, $page);
$rows = $result['rows'];
foreach ($rows as &$r) { $r['sentence'] = activity_sentence($r); }
unset($r);
$query = array_filter(['action' => $filters['action'], 'since' => $filters['since']], static fn ($v) => $v !== null && $v !== '');
log_screen_view($pdo, 'activity');
if (wants_json()) {
    respond_screen(['rows' => array_map(static fn (array $r): array => [
        'activity_id' => (int) $r['activity_id'], 'occurred_at' => json_ts($r['occurred_at']),
        'actor' => ['member_id' => $r['actor_member_id'] !== null ? (int) $r['actor_member_id'] : null, 'name' => $r['actor_name']],
        'action' => $r['action'], 'sentence' => $r['sentence'], 'entity_type' => $r['entity_type'],
        'entity_id' => $r['entity_id'] !== null ? (int) $r['entity_id'] : null, 'source' => $r['source'],
        'agent_run_id' => $r['agent_run_id'] !== null ? (int) $r['agent_run_id'] : null,
    ], $rows), 'page' => $page, 'more' => $result['more'], 'filters' => $query]);
}
$data = ['rows' => $rows, 'page' => $page, 'more' => $result['more'], 'filters' => $filters, 'query' => $query, 'tz' => site_timezone(), 'siteName' => $site['name'] ?? ''];
$resultsHtml = view('activity/partials/rows.php', $data);
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'activity-results') {
    header('Vary: HX-Request');
    echo $resultsHtml;
    exit;
}
render_screen('Activity', view('activity/page.php', $data + ['resultsHtml' => $resultsHtml]), ['activeNav' => 'activity', 'screen' => 'activity']);
