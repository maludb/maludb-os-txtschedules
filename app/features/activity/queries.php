<?php
declare(strict_types=1);

/** The activity trail the caller may see (mcp_activity_log: their own rows, and every row at a site where they build). */
const ACTIVITY_PAGE = 50;

/** Rows newest first, one page; `more` says whether another page exists. */
function find_activity(PDO $pdo, array $filters, int $page): array
{
    $where = [];
    $args = [];
    if (($filters['site'] ?? null) !== null) {
        $where[] = 'site_id = :site';
        $args['site'] = (int) $filters['site'];
    }
    if (($filters['member'] ?? null) !== null) {
        $where[] = 'actor_member_id = :member';
        $args['member'] = (int) $filters['member'];
    }
    if (($filters['action'] ?? '') !== '') {
        $where[] = 'action LIKE :action';
        $args['action'] = str_replace(['%', '_'], ['\\%', '\\_'], rtrim($filters['action'], '.')) . '%';
    }
    if (($filters['since'] ?? null) !== null) {
        $where[] = "occurred_at > now() - make_interval(days => :since)";
        $args['since'] = (int) $filters['since'];
    }
    $sql = 'SELECT activity_id, occurred_at, actor_member_id, actor_name, source, action, entity_type, entity_id, site_id, agent_run_id
              FROM mcp_activity_log' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY occurred_at DESC, activity_id DESC LIMIT ' . (ACTIVITY_PAGE + 1) . ' OFFSET ' . (($page - 1) * ACTIVITY_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    $more = count($rows) > ACTIVITY_PAGE;
    return ['rows' => array_slice($rows, 0, ACTIVITY_PAGE), 'more' => $more];
}

/** One line for a row, in words: "Priya Shah signed on". Falls back to the event name. */
function activity_sentence(array $r): string
{
    $who = $r['actor_name'] ?? 'txtSchedules';
    $words = [
        'member.sign_on' => 'signed on', 'member.sign_out' => 'signed out', 'directory.sync' => 'refreshed the directory',
        'token.mint' => 'made an access token', 'token.revoke' => 'revoked an access token', 'assistant.message' => 'asked the assistant',
        'site.switch' => 'switched restaurant',
    ];
    if ($r['action'] === 'screen.view') {
        return $who . ' opened a screen';
    }
    return $who . ' ' . ($words[$r['action']] ?? str_replace(['.', '_'], [' ', ' '], (string) $r['action']));
}
