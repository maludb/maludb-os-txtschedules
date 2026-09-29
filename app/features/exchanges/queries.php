<?php
declare(strict_types=1);

/**
 * Exchanges — offers, open shifts, gives, swaps, coverage — read through the mcp_* views and moved only through the
 * database's ts_exchange_*() functions (docs/build-specs/shifts-marketplace.md). The database is the referee: the
 * functions refuse (SQLSTATE P0001) in the sentence a person reads; these functions pass it through. No pay anywhere.
 */

/** The columns and joins every exchange read carries: the shift(s), their position and zone, the site's claim rule. */
const EXCHANGE_SELECT = "SELECT x.exchange_id, x.site_id, st.name AS site_name, st.timezone, st.claim_mode, st.shift_lead_approves_same_day, st.cutoff_minutes, st.approval_pickup, st.approval_give, st.approval_swap,
        x.kind, x.shift_id, x.from_member_id, x.from_name, x.to_member_id, x.to_name, x.swap_shift_id, x.status, x.needs_approval, x.warnings,
        x.note, x.expires_at, x.decided_by, x.decided_at, x.decision_note, x.created_at, x.claims,
        x.shift_starts_at, x.shift_ends_at, s.position_id, s.position_name, s.position_color, s.assignee_name AS holder_name, s.assignee_member_id AS holder_id,
        sw.starts_at AS swap_starts_at, sw.ends_at AS swap_ends_at, sw.position_name AS swap_position_name, sw.position_color AS swap_position_color
   FROM mcp_exchanges x
   JOIN mcp_sites st ON st.site_id = x.site_id
   LEFT JOIN mcp_shifts s ON s.shift_id = x.shift_id
   LEFT JOIN mcp_shifts sw ON sw.shift_id = x.swap_shift_id";

function decode_exchange_rows(array $rows): array
{
    foreach ($rows as &$r) {
        foreach (['exchange_id', 'site_id', 'shift_id', 'claims'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        foreach (['from_member_id', 'to_member_id', 'swap_shift_id', 'holder_id'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
        $r['needs_approval'] = (bool) $r['needs_approval'];
        $r['warnings'] = is_string($r['warnings'] ?? null) ? (json_decode($r['warnings'], true) ?: []) : ($r['warnings'] ?? []);
        if (isset($r['claimants'])) {
            $r['claimants'] = is_string($r['claimants']) ? (json_decode($r['claimants'], true) ?: []) : $r['claimants'];
        }
    }
    unset($r);
    return $rows;
}

/** The marketplace's three tabs at one site: 'grabs' (offers and open shifts I could take), 'forme' (asked of me), 'claims' (mine). */
function find_marketplace(PDO $pdo, int $siteId, int $memberId, string $tab): array
{
    $mine = 'EXISTS (SELECT 1 FROM mcp_exchange_claims c WHERE c.exchange_id = x.exchange_id AND c.member_id = ';
    if ($tab === 'forme') {
        $sql = EXCHANGE_SELECT . " WHERE x.site_id = :site AND x.expires_at > now() AND (
                    (x.kind IN ('give', 'swap') AND x.to_member_id = :m1 AND x.status = 'pending_acceptance')
                 OR (x.kind = 'coverage' AND x.status = 'open' AND EXISTS (SELECT 1 FROM exchange_invitees i WHERE i.exchange_id = x.exchange_id AND i.member_id = :m2)
                     AND NOT {$mine}:m3)))
                ORDER BY x.shift_starts_at, x.exchange_id";
        $args = ['site' => $siteId, 'm1' => $memberId, 'm2' => $memberId, 'm3' => $memberId];
    } elseif ($tab === 'claims') {
        $sql = 'SELECT q.*, c.claim_status, c.claim_at FROM (' . EXCHANGE_SELECT . ') q
                  JOIN (SELECT exchange_id, status AS claim_status, created_at AS claim_at FROM mcp_exchange_claims WHERE member_id = :m) c ON c.exchange_id = q.exchange_id
                 WHERE q.site_id = :site ORDER BY c.claim_at DESC, q.exchange_id DESC LIMIT 50';
        $args = ['site' => $siteId, 'm' => $memberId];
    } else {
        $sql = EXCHANGE_SELECT . " WHERE x.site_id = :site AND x.kind IN ('offer', 'open') AND x.status = 'open' AND x.expires_at > now()
                  AND x.from_member_id IS DISTINCT FROM :m1 AND NOT {$mine}:m2)
                ORDER BY x.shift_starts_at, x.exchange_id";
        $args = ['site' => $siteId, 'm1' => $memberId, 'm2' => $memberId];
    }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return decode_exchange_rows($st->fetchAll());
}

/**
 * May this person take this exchange's shift? One call of the database's own check (ts_exchange_check_taker): the
 * sentence it raises when not, the soft warnings when so. ['reason' => ?string, 'warnings' => [{rule, severity, message}]]
 */
function marketplace_check(PDO $pdo, int $exchangeId, int $memberId): array
{
    try {
        $st = $pdo->prepare('SELECT ts_exchange_check_taker(x, :m) FROM exchanges x WHERE x.id = :id');
        $st->execute(['m' => $memberId, 'id' => $exchangeId]);
        $w = $st->fetchColumn();
        return ['reason' => null, 'warnings' => $w === false || $w === null ? [] : (json_decode((string) $w, true) ?: [])];
    } catch (PDOException $e) {
        if ((string) $e->getCode() !== 'P0001') {
            throw $e;
        }
        return ['reason' => db_message($e, 'You cannot take this shift.'), 'warnings' => []];
    }
}

/** The sentence why not, or null (the spec's signature); the warnings are marketplace_check()'s. */
function marketplace_reason(PDO $pdo, int $exchangeId, int $memberId): ?string
{
    return marketplace_check($pdo, $exchangeId, $memberId)['reason'];
}

/** One exchange the caller may see (mcp_exchanges' own rule: parties, claimants, invitees, approvers, the main restaurant's staff for an open offer). */
function find_exchange(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(EXCHANGE_SELECT . ' WHERE x.exchange_id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch();
    return $row === false ? null : decode_exchange_rows([$row])[0];
}

/** The claims on an exchange (the caller decides whether to show them: approvers only, the screen says). */
function exchange_claims(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT claim_id, exchange_id, member_id, member_name, status, warnings, created_at FROM mcp_exchange_claims WHERE exchange_id = :id ORDER BY created_at, claim_id');
    $st->execute(['id' => $id]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['member_id'] = (int) $r['member_id'];
        $r['warnings'] = json_decode((string) $r['warnings'], true) ?: [];
    }
    unset($r);
    return $rows;
}

/** The exchange's own site — the one base-table read: a write derives its site from the record, never the session. */
function exchange_site_id(PDO $pdo, int $exchangeId): ?int
{
    $st = $pdo->prepare('SELECT scope_id FROM exchanges WHERE id = :id');
    $st->execute(['id' => $exchangeId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

/**
 * What waits for a manager: trades pending approval, and (claim mode manager_chooses) open trades with claims, oldest first.
 * $siteIds = where I hold requests.approve; $daySiteIds = where I hold only market.approve_day (same-day trades only, if the site lets shift leads).
 * A trade I am part of is never in my own inbox.
 */
function find_approvals(PDO $pdo, array $siteIds, int $memberId, array $daySiteIds = []): array
{
    $lit = static fn (array $ids): string => '{' . implode(',', array_map('intval', $ids)) . '}';
    $sql = 'SELECT q.*, COALESCE((SELECT json_agg(json_build_object(\'member_id\', c.member_id, \'name\', c.member_name, \'warnings\', c.warnings) ORDER BY c.created_at)
                                     FROM mcp_exchange_claims c WHERE c.exchange_id = q.exchange_id AND c.status = \'pending\'), \'[]\'::json) AS claimants
              FROM (' . EXCHANGE_SELECT . ") q
             WHERE (q.status = 'pending_approval' OR (q.status = 'open' AND q.claims > 0 AND q.claim_mode = 'manager_chooses'))
               AND q.from_member_id IS DISTINCT FROM :m1 AND q.to_member_id IS DISTINCT FROM :m2
               AND (q.site_id = ANY (CAST(:full AS bigint[]))
                    OR (q.site_id = ANY (CAST(:day AS bigint[])) AND q.shift_lead_approves_same_day
                        AND ((LEAST(q.shift_starts_at, COALESCE(q.swap_starts_at, q.shift_starts_at)) AT TIME ZONE q.timezone)::date
                             <= (now() AT TIME ZONE q.timezone)::date + 1)))
             ORDER BY q.created_at, q.exchange_id";
    $st = $pdo->prepare($sql);
    $st->execute(['m1' => $memberId, 'm2' => $memberId, 'full' => $lit($siteIds), 'day' => $lit($daySiteIds)]);
    return decode_exchange_rows($st->fetchAll());
}

/** My own trades and claims, by state: 'waiting' (still live), 'decided' (finished), or 'all'. ['mine' => exchanges, 'claims' => exchanges with claim_status]. */
function find_my_requests(PDO $pdo, int $memberId, string $state): array
{
    $live = "('open', 'pending_acceptance', 'pending_approval')";
    $cond = $state === 'waiting' ? " AND x.status IN $live" : ($state === 'decided' ? " AND x.status NOT IN $live" : '');
    $st = $pdo->prepare(EXCHANGE_SELECT . " WHERE (x.from_member_id = :m1 OR (x.kind IN ('give', 'swap') AND x.to_member_id = :m2)) $cond ORDER BY x.created_at DESC, x.exchange_id DESC LIMIT 100");
    $st->execute(['m1' => $memberId, 'm2' => $memberId]);
    $mine = decode_exchange_rows($st->fetchAll());
    $ccond = $state === 'waiting' ? " AND q.status IN $live AND c.claim_status IN ('pending', 'won')"
           : ($state === 'decided' ? " AND (q.status NOT IN $live OR c.claim_status IN ('lost', 'withdrawn'))" : '');
    $st = $pdo->prepare('SELECT q.*, c.claim_status, c.claim_at FROM (' . EXCHANGE_SELECT . ') q
                           JOIN (SELECT exchange_id, status AS claim_status, created_at AS claim_at FROM mcp_exchange_claims WHERE member_id = :m) c ON c.exchange_id = q.exchange_id
                          WHERE true ' . $ccond . ' ORDER BY c.claim_at DESC LIMIT 100');
    $st->execute(['m' => $memberId]);
    return ['mine' => $mine, 'claims' => decode_exchange_rows($st->fetchAll())];
}

/** Who could cover a shift: main-restaurant staff, free, no hard rule broken, fewest hours that week first (ts_coverage_candidates checks the caller's right inside). */
function find_coverage_candidates(PDO $pdo, int $shiftId): array
{
    $st = $pdo->prepare('SELECT member_id, display_name, hours_this_week, warnings FROM ts_coverage_candidates(:s)');
    $st->execute(['s' => $shiftId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['member_id'] = (int) $r['member_id'];
        $r['warnings'] = json_decode((string) $r['warnings'], true) ?: [];
    }
    unset($r);
    return $rows;
}

/** Put a shift up (offer / give / swap / open) or ask several to cover it (coverage): ts_exchange_create() and the invitees, one transaction. Returns the exchange id. */
function create_exchange(PDO $pdo, string $kind, int $shiftId, int $by, ?int $to = null, ?int $swapShift = null, ?string $note = null, array $invitees = []): int
{
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $st = $pdo->prepare('SELECT ts_exchange_create(:k, :s, :by, :to, :sw, :n)');
        $st->execute(['k' => $kind, 's' => $shiftId, 'by' => $by, 'to' => $to, 'sw' => $swapShift, 'n' => $note]);
        $id = (int) $st->fetchColumn();
        foreach (array_unique(array_map('intval', $invitees)) as $m) {
            $pdo->prepare('INSERT INTO exchange_invitees (exchange_id, member_id) VALUES (:x, :m)')->execute(['x' => $id, 'm' => $m]);
        }
        if ($own) {
            $pdo->commit();
        }
        return $id;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** A claim: 'approved' | 'pending_approval' | 'claimed' — or the database's refusal (P0001). Two at once: the row lock lets one win. */
function claim_exchange(PDO $pdo, int $id, int $memberId): string
{
    $st = $pdo->prepare('SELECT ts_exchange_claim(:id, :m)');
    $st->execute(['id' => $id, 'm' => $memberId]);
    return (string) $st->fetchColumn();
}

function choose_claim(PDO $pdo, int $id, int $memberId, int $by): string
{
    $st = $pdo->prepare('SELECT ts_exchange_choose(:id, :m, :by)');
    $st->execute(['id' => $id, 'm' => $memberId, 'by' => $by]);
    return (string) $st->fetchColumn();
}

/** The named colleague answers a give or a swap: 'approved' | 'pending_approval' | 'declined'. */
function accept_exchange(PDO $pdo, int $id, int $memberId, bool $accept): string
{
    $st = $pdo->prepare('SELECT ts_exchange_accept(:id, :m, :a)');
    $st->bindValue('id', $id, PDO::PARAM_INT);
    $st->bindValue('m', $memberId, PDO::PARAM_INT);
    $st->bindValue('a', $accept, PDO::PARAM_BOOL);
    $st->execute();
    return (string) $st->fetchColumn();
}

/** A manager (or, same-day, a shift lead) decides: 'approved' | 'declined'. */
function decide_exchange(PDO $pdo, int $id, bool $approve, int $by, ?string $note): string
{
    $st = $pdo->prepare('SELECT ts_exchange_decide(:id, :a, :by, :n)');
    $st->bindValue('id', $id, PDO::PARAM_INT);
    $st->bindValue('a', $approve, PDO::PARAM_BOOL);
    $st->bindValue('by', $by, PDO::PARAM_INT);
    $st->bindValue('n', $note);
    $st->execute();
    return (string) $st->fetchColumn();
}

function cancel_exchange(PDO $pdo, int $id, int $by): string
{
    $st = $pdo->prepare('SELECT ts_exchange_cancel(:id, :by)');
    $st->execute(['id' => $id, 'by' => $by]);
    return (string) $st->fetchColumn();
}

/** Withdraw one's own waiting claim. */
function withdraw_claim(PDO $pdo, int $id, int $memberId): void
{
    $st = $pdo->prepare("UPDATE exchange_claims SET status = 'withdrawn' WHERE exchange_id = :id AND member_id = :m AND status = 'pending'");
    $st->execute(['id' => $id, 'm' => $memberId]);
    if ($st->rowCount() === 0) {
        throw new DomainException('You have no waiting claim on this trade.');
    }
}

/** The state a handler needs before and after a move (base tables: the handler has already been allowed to act): the exchange, and the holder of each shift. */
function exchange_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT x.id AS exchange_id, x.scope_id AS site_id, x.kind, x.status, x.shift_id, x.swap_shift_id, x.from_member_id, x.to_member_id,
            x.created_by, x.warnings, x.note, x.expires_at,
            s.assignee_member_id AS shift_holder_id, sm.display_name AS shift_holder_name, s.starts_at AS shift_starts_at, s.ends_at AS shift_ends_at, p.name AS position_name,
            w.assignee_member_id AS swap_holder_id, wm.display_name AS swap_holder_name, w.starts_at AS swap_starts_at, w.ends_at AS swap_ends_at,
            si.name AS site_name, si.timezone, fm.display_name AS from_name, tm.display_name AS to_name
       FROM exchanges x
       JOIN shifts s ON s.id = x.shift_id JOIN positions p ON p.id = s.position_id JOIN sites si ON si.scope_id = x.scope_id
       LEFT JOIN members sm ON sm.id = s.assignee_member_id
       LEFT JOIN shifts w ON w.id = x.swap_shift_id LEFT JOIN members wm ON wm.id = w.assignee_member_id
       LEFT JOIN members fm ON fm.id = x.from_member_id LEFT JOIN members tm ON tm.id = x.to_member_id
      WHERE x.id = :id");
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['exchange_id', 'site_id', 'shift_id'] as $k) {
        $r[$k] = (int) $r[$k];
    }
    foreach (['swap_shift_id', 'from_member_id', 'to_member_id', 'created_by', 'shift_holder_id', 'swap_holder_id'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    $r['warnings'] = json_decode((string) $r['warnings'], true) ?: [];
    return $r;
}

/** The people to tell that a trade waits: whoever holds requests.approve at the site (and market.approve_day for a same-day one the site lets shift leads settle), never the person who acted. */
function find_approver_ids(PDO $pdo, int $siteId, bool $sameDay, int $exceptMemberId): array
{
    $st = $pdo->prepare("SELECT DISTINCT r.member_id
              FROM member_site_roles r
              JOIN members m ON m.id = r.member_id AND m.status = 'active' AND m.capability IS NOT NULL AND m.member_kind = 'human'
              JOIN site_settings ss ON ss.scope_id = r.scope_id
              JOIN ts_role_rights rr ON rr.role_key = ANY (r.roles)
             WHERE r.scope_id = :s AND r.member_id <> :except
               AND (rr.right_key = 'requests.approve' OR (:same AND ss.shift_lead_approves_same_day AND rr.right_key = 'market.approve_day'))
             ORDER BY r.member_id");
    $st->bindValue('s', $siteId, PDO::PARAM_INT);
    $st->bindValue('except', $exceptMemberId, PDO::PARAM_INT);
    $st->bindValue('same', $sameDay, PDO::PARAM_BOOL);
    $st->execute();
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** How many trades wait for the caller at a site (the menu badge). */
function count_approvals(PDO $pdo, int $siteId, int $memberId): int
{
    $full = has_right('requests.approve', $siteId);
    $day = !$full && has_right('market.approve_day', $siteId);
    if (!$full && !$day) {
        return 0;
    }
    return count(find_approvals($pdo, $full ? [$siteId] : [], $memberId, $day ? [$siteId] : []));
}

/** The soft warnings recorded on a member's claim (sentences). */
function claim_warnings(PDO $pdo, int $exchangeId, int $memberId): array
{
    $st = $pdo->prepare('SELECT warnings FROM exchange_claims WHERE exchange_id = :x AND member_id = :m');
    $st->execute(['x' => $exchangeId, 'm' => $memberId]);
    $w = json_decode((string) ($st->fetchColumn() ?: '[]'), true) ?: [];
    return array_map(static fn (array $x): string => (string) ($x['message'] ?? ''), $w);
}

/** One trade's story, newest first (mcp_activity_log, entity exchange). */
function exchange_history(PDO $pdo, int $id, int $limit = 50): array
{
    $st = $pdo->prepare("SELECT activity_id, occurred_at, actor_member_id, actor_name, source, action, entity_type, entity_id, before, after
                           FROM mcp_activity_log WHERE action <> 'screen.view' AND entity_type = 'exchange' AND entity_id = :id
                          ORDER BY occurred_at DESC, activity_id DESC LIMIT " . max(1, min(200, $limit)));
    $st->execute(['id' => $id]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['after'] = $r['after'] === null ? [] : (json_decode((string) $r['after'], true) ?: []);
        $r['before'] = $r['before'] === null ? [] : (json_decode((string) $r['before'], true) ?: []);
    }
    unset($r);
    return $rows;
}
