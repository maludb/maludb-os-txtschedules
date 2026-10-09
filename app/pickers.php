<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract). One entry per record kind.
 * txtSchedules scopes everything by restaurant, so a people source declares the site as an integer param (`site_id`) and its
 * `allow` closure asks the right AT that site — a site the caller does not hold fails every right, so its people are never listed
 * (and the view behind find_schedulable_people() would show none of them anyway). `search` reuses the screens' own query functions
 * (find_schedulable_people, find_give_candidates), filtered by the typed text and paged here: a restaurant's staff is a short list.
 * Rows: id, label, detail (one muted line: the positions a person works), badge + color — optional.
 *
 *   person           everyone on the schedule at a site (`site_id`) — schedule.build there: the availability screen, the shift form,
 *                    "Move to…" (builder and shift page). `self=1` puts the caller in the list even when they are not on the schedule
 *                    (the old select's "Me"/"Mine" first option), first, marked "You".
 *   person_approver  the same, for requests.approve there: time off for someone, whose balances.
 *   colleague        who can take a shift as a gift or swap (`shift_id`): market.trade at its site and the caller holds the shift,
 *                    exactly the shift page's own condition.
 */
const PICKER_PAGE_SIZE = 25;

/** Pages an already-built list of [id, label, detail?] rows by the typed text: the shape the endpoint's fragment wants. */
function picker_page(array $rows, string $q, int $page): array
{
    if ($q !== '') {
        $rows = array_values(array_filter($rows, static fn (array $r): bool => mb_stripos($r['label'], $q) !== false));
    }
    $total = count($rows);
    $pages = max(1, (int) ceil($total / PICKER_PAGE_SIZE));
    $page = min(max(1, $page), $pages);
    return ['rows' => array_slice($rows, ($page - 1) * PICKER_PAGE_SIZE, PICKER_PAGE_SIZE), 'total' => $total, 'page' => $page, 'page_size' => PICKER_PAGE_SIZE, 'pages' => $pages];
}

/** Does the caller hold the site and the right there? (A site not held has no rights: it does not exist to them.) */
function picker_site_allows(string $right, ?int $siteId): bool
{
    return $siteId !== null && in_array($siteId, array_column(held_sites(), 'scope_id'), true) && has_right($right, $siteId);
}

/** A person's label (display name) — only people the caller shares a restaurant with are visible in mcp_members. */
function picker_person_label(PDO $pdo, int $id): ?string
{
    $st = $pdo->prepare("SELECT display_name FROM mcp_members WHERE member_id = :m AND member_kind = 'human'");
    $st->execute(['m' => $id]);
    $name = $st->fetchColumn();
    return $name === false ? null : (string) $name;
}

/** The people source for a right: the schedulable people at `site_id`, the positions they work as the detail line. */
function picker_people_source(string $right): array
{
    return [
        'title' => 'People', 'noun' => 'person', 'params' => ['site_id', 'self'],
        'allow' => static fn (array $p): bool => picker_site_allows($right, $p['site_id'] ?? null),
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/weeks/queries.php';
            require_once __DIR__ . '/features/shifts/queries.php';
            $site = (int) $params['site_id'];
            $names = array_column(find_site_positions($pdo, $site), 'name', 'position_id');
            $worked = find_member_positions($pdo, $site);
            $me = (int) current_member_id();
            $rows = [];
            foreach (find_schedulable_people($pdo, $site) as $p) {
                $id = (int) $p['member_id'];
                $rows[] = ['id' => $id, 'label' => (string) $p['display_name'],
                           'detail' => implode(' · ', array_filter(array_map(static fn ($pid) => $names[$pid] ?? null, $worked[$id] ?? [])))];
            }
            if (($params['self'] ?? null) === 1) {
                $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['id'] !== $me));
                $mine = picker_person_label($pdo, $me);
                if ($mine !== null) {
                    array_unshift($rows, ['id' => $me, 'label' => $mine, 'detail' => 'You']);
                }
            }
            return picker_page($rows, $q, $page);
        },
        'label' => static fn (PDO $pdo, int $id): ?string => picker_person_label($pdo, $id),
    ];
}

return [
    'person' => picker_people_source('schedule.build'),
    'person_approver' => picker_people_source('requests.approve'),
    'colleague' => [
        'title' => 'Colleagues', 'noun' => 'colleague', 'params' => ['shift_id'],
        'allow' => static function (array $p): bool {
            require_once __DIR__ . '/features/shifts/queries.php';
            $s = $p['shift_id'] !== null ? find_shift(db(), $p['shift_id']) : null;
            return $s !== null && $s['assignee_member_id'] !== null && (int) $s['assignee_member_id'] === (int) current_member_id()
                && picker_site_allows('market.trade', (int) $s['site_id']);
        },
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/shifts/queries.php';
            $rows = array_map(static fn (array $c): array => ['id' => (int) $c['member_id'], 'label' => (string) $c['display_name']],
                find_give_candidates($pdo, (int) $params['shift_id'], (int) current_member_id()));
            return picker_page($rows, $q, $page);
        },
        'label' => static fn (PDO $pdo, int $id): ?string => picker_person_label($pdo, $id),
    ],
];
