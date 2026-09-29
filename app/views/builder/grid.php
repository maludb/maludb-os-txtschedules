<?php
/**
 * The week builder (screen `builder`). Data: site, sites (where I build), ws, prev, next, week, prevWeek, view (people|positions), positions, positionId, canLabor, shifts (visible),
 * allShifts, warn (shift_id => sentences), warnRows, hours (member_id => row), labor, needs, people, days, day, badge, result, today, notice, currency
 * From 992 px: the grid (people or positions down, days across; SortableJS drag posts the same action the buttons post). Below: day tabs and one column of shifts. A published week
 * shows the same grid, not draggable — Add / Change / Cancel there say "This is live: staff will be told."
 */
$siteId = (int) $site['site_id'];
$published = $week !== null && $week['status'] === 'published';
$draft = !$published;
$here = builder_url($siteId, $ws, ['view' => $view === 'people' ? null : $view, 'position' => $positionId]);
$url = static fn (array $over): string => builder_url($siteId, $over['week'] ?? $ws, array_filter(['view' => ($over['view'] ?? $view) === 'people' ? null : ($over['view'] ?? $view), 'position' => array_key_exists('position', $over) ? $over['position'] : $positionId, 'day' => $over['day'] ?? null], static fn ($v) => $v !== null && $v !== ''));
$money = static fn ($n): string => ($currency === 'USD' ? '$' : $currency . ' ') . number_format((float) $n, 2);
// group the shifts
$byCell = [];
foreach ($shifts as $s) {
    $key = $view === 'positions' ? 'p' . $s['position_id'] : ($s['assignee_member_id'] === null ? 'open' : 'm' . $s['assignee_member_id']);
    $byCell[$key][shift_day($s)][] = $s;
}
$dayHours = [];
$dayCost = [];
foreach ($allShifts as $s) {
    if ($s['status'] !== 'scheduled') { continue; }
    $d = shift_day($s);
    $dayHours[$d] = ($dayHours[$d] ?? 0) + (float) $s['paid_hours'];
    if ($canLabor && $s['cost'] !== null) { $dayCost[$d] = ($dayCost[$d] ?? 0) + (float) $s['cost']; }
}
$rows = [];
if ($view === 'positions') {
    foreach ($positions as $p) { $rows['p' . $p['position_id']] = ['id' => (int) $p['position_id'], 'name' => $p['name'], 'member' => null, 'color' => $p['color']]; }
    foreach ($shifts as $s) { $rows['p' . $s['position_id']] ??= ['id' => (int) $s['position_id'], 'name' => $s['position_name'], 'member' => null, 'color' => $s['position_color']]; }
} else {
    foreach ($people as $p) { $rows['m' . $p['member_id']] = ['id' => (int) $p['member_id'], 'name' => $p['display_name'], 'member' => (int) $p['member_id'], 'color' => null]; }
    foreach ($shifts as $s) {
        if ($s['assignee_member_id'] !== null) { $rows['m' . $s['assignee_member_id']] ??= ['id' => (int) $s['assignee_member_id'], 'name' => $s['assignee_name'], 'member' => (int) $s['assignee_member_id'], 'color' => null]; }
    }
}
$drag = $draft;
$dayShifts = [];
foreach ($shifts as $s) { if (shift_day($s) === $day) { $dayShifts[] = $s; } }
$dayOpenCount = count(array_filter($dayShifts, static fn (array $s): bool => $s['is_open'] && $s['status'] === 'scheduled'));
$openTotal = count(array_filter($allShifts, static fn (array $s): bool => $s['is_open'] && $s['status'] === 'scheduled'));
$weekArg = $week === null ? ['site' => $siteId, 'week_start' => $ws] : ['week' => (int) $week['week_id']];
$hidden = static function (array $args): string { $h = ''; foreach ($args as $k => $v) { $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">'; } return $h; };
?>
<?= view('shared/header.php', ['id' => 'builder', 'title' => 'Builder', 'crumbs' => [['Home', '/'], [$site['name'], null], ['Builder', null]]]) ?>
<div class="main-content" id="builder-content" data-week-start="<?= e($ws) ?>" data-week-state="<?= e($badge[2]) ?>" data-here="<?= e($here) ?>">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($result !== null) { echo view('builder/partials/result.php', ['result' => $result]); } ?>

    <div class="card mb-3" id="builder-head">
        <div class="card-body p-3">
            <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                <?= hx_link($url(['week' => $prev, 'day' => null]), '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch px-3', 'id="builder-prev" aria-label="Previous week"') ?>
                <div class="fw-bold text-nowrap" id="builder-range"><?= e(week_label($ws)) ?></div>
                <?= hx_link($url(['week' => $next, 'day' => null]), '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch px-3', 'id="builder-next" aria-label="Next week"') ?>
                <span class="badge bg-soft-<?= e($badge[1]) ?> text-<?= e($badge[1]) ?> ms-1" id="builder-state"><?= e($badge[0]) ?></span>
                <span class="ms-auto fs-12 text-muted" id="builder-site-name"><?= e($site['name']) ?></span>
            </div>
            <form method="get" action="/builder" class="d-flex gap-2 flex-wrap mb-2" id="builder-filters" hx-get="/builder" hx-target="#page-content" hx-trigger="change" hx-push-url="true">
                <input type="hidden" name="week" value="<?= e($ws) ?>">
                <?php if (count($sites) > 1): ?>
                    <select name="site" id="builder-filter-site" class="form-select btn-touch w-auto" aria-label="Restaurant">
                        <?php foreach ($sites as $st): ?><option value="<?= (int) $st['scope_id'] ?>" <?= (int) $st['scope_id'] === $siteId ? 'selected' : '' ?>><?= e($st['name']) ?></option><?php endforeach; ?>
                    </select>
                <?php else: ?><input type="hidden" name="site" value="<?= $siteId ?>"><?php endif; ?>
                <select name="view" id="builder-filter-view" class="form-select btn-touch w-auto" aria-label="Rows">
                    <option value="people" <?= $view === 'people' ? 'selected' : '' ?>>By person</option>
                    <option value="positions" <?= $view === 'positions' ? 'selected' : '' ?>>By position</option>
                </select>
                <select name="position" id="builder-filter-position" class="form-select btn-touch w-auto" aria-label="Position">
                    <option value="">All positions</option>
                    <?php foreach ($positions as $p): ?><option value="<?= (int) $p['position_id'] ?>" <?= $positionId === (int) $p['position_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-light btn-touch" type="submit">Show</button></noscript>
            </form>
            <?php if ($published): ?><div class="alert alert-warning py-2 mb-2 fs-12" id="builder-live-note" role="note"><i class="feather-radio me-1"></i>This week is live: staff will be told of every change.</div><?php endif; ?>
            <div class="d-flex flex-wrap gap-2" id="builder-actions">
                <?php if ($draft): ?>
                    <form method="post" action="/weeks/copy.php" hx-post="/weeks/copy.php" hx-target="#flash" class="flex-fill" id="builder-copy-form">
                        <?= csrf_field() ?><?= $hidden($weekArg + ['from_week' => $prevWeek === null ? '' : (int) $prevWeek['week_id']]) ?>
                        <button type="submit" class="btn btn-light btn-touch w-100" id="builder-copy-btn"<?= $prevWeek === null ? ' disabled title="There is no week before this one to copy."' : '' ?>><i class="feather-copy me-1"></i>Copy last week</button>
                    </form>
                    <?= hx_link('/templates/?' . http_build_query(['site' => $siteId, 'week' => $ws]), '<i class="feather-file-text me-1"></i>From a template…', 'btn btn-light btn-touch flex-fill', 'id="builder-template-btn"') ?>
                    <form method="post" action="/weeks/autofill.php" hx-post="/weeks/autofill.php" hx-target="#flash" class="flex-fill" id="builder-autofill-form">
                        <?= csrf_field() ?><?= $hidden($weekArg) ?>
                        <button type="submit" class="btn btn-light btn-touch w-100" id="builder-autofill-btn"><i class="feather-zap me-1"></i>Auto-fill</button>
                    </form>
                <?php endif; ?>
                <?php if ($week !== null && $allShifts !== []): ?>
                    <details class="flex-fill" id="builder-savetemplate-details"><summary class="btn btn-light btn-touch w-100" id="builder-savetemplate-open"><i class="feather-save me-1"></i>Save as template</summary>
                        <form method="post" action="/templates/save.php" hx-post="/templates/save.php" hx-target="#flash" class="pt-2" id="builder-savetemplate-form">
                            <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>"><input type="hidden" name="week" value="<?= (int) $week['week_id'] ?>">
                            <label class="form-label fs-12 text-muted mb-1" for="template-form-field-name">Name</label>
                            <input type="text" name="name" id="template-form-field-name" class="form-control btn-touch mb-2" maxlength="80" required>
                            <button type="submit" class="btn btn-primary btn-touch w-100" id="template-form-save-btn">Save the template</button>
                        </form>
                    </details>
                <?php endif; ?>
                <?php if ($draft && $week !== null && $allShifts !== []): ?>
                    <?= hx_link('/weeks/publish-confirm?' . http_build_query(['site' => $siteId, 'week' => $ws]), '<i class="feather-send me-1"></i>Publish', 'btn btn-primary btn-touch flex-fill', 'id="builder-publish-btn"') ?>
                    <form method="post" action="/weeks/clear.php" hx-post="/weeks/clear.php" hx-target="#flash" hx-confirm="Remove every shift from this draft week?" class="flex-fill" id="builder-clear-form">
                        <?= csrf_field() ?><?= $hidden($weekArg) ?>
                        <button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="builder-clear-btn"><i class="feather-trash-2 me-1"></i>Clear the draft</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- the phone: day tabs, one column of shifts -->
    <div class="d-lg-none" id="builder-phone">
        <div class="card mb-3" id="builder-phone-days"><div class="card-body p-2"><div class="d-flex flex-nowrap overflow-auto gap-1 week-chips" id="builder-phone-chips">
            <?php foreach ($days as [$date, $dow, $dom, $isToday]): $n = count(array_filter($allShifts, static fn (array $s): bool => shift_day($s) === $date && $s['status'] === 'scheduled')); ?>
                <?= hx_link($url(['day' => $date]), '<span class="fs-11 text-uppercase">' . e($dow) . '</span><span class="fw-bold d-block">' . e($dom) . '</span>' . ($n > 0 ? '<i class="chip-dot"></i>' : ''),
                    'week-chip text-center rounded' . ($isToday ? ' is-today' : '') . ($n > 0 ? ' works' : '') . ($date === $day ? ' selected' : ''), 'id="builder-day-' . e($date) . '"') ?>
            <?php endforeach; ?>
        </div></div></div>
        <div class="fw-semibold mb-2" id="builder-phone-title"><?= e(local_dt($day . ' 12:00:00', 'UTC')->format('l, M j')) ?><?= $dayOpenCount > 0 ? ' · ' . $dayOpenCount . ' open' : '' ?></div>
        <?php if ($dayShifts === []): ?><div class="card mb-3" id="builder-phone-empty"><div class="card-body text-center text-muted py-4">No shifts on this day yet.</div></div><?php endif; ?>
        <?php foreach ($dayShifts as $s): $id = (int) $s['shift_id']; $w = $warn[$id] ?? []; $cancelled = $s['status'] === 'cancelled'; ?>
            <div class="card mb-2 shift-card<?= $s['is_open'] ? ' shift-open' : '' ?><?= $cancelled ? ' shift-cancelled' : '' ?>" id="builder-list-shift-<?= $id ?>">
                <div class="card-body p-3">
                    <div class="d-flex gap-3 align-items-stretch">
                        <?= pos_swatch($s['position_color']) ?>
                        <div class="flex-grow-1 min-w-0">
                            <?= hx_link(with_back('/shifts/' . $id, $here), '<span class="fw-bold">' . e(shift_time_range((string) $s['starts_at'], (string) $s['ends_at'], (string) $s['timezone'])) . '</span>', 'text-dark text-decoration-none', 'id="builder-list-shift-' . $id . '-link"') ?>
                            <div class="fs-12"><?= $s['is_open'] ? '<span class="text-primary fw-semibold">Open</span>' : e($s['assignee_name']) ?> · <span class="text-muted"><?= e($s['position_name']) ?><?= $cancelled ? ' · cancelled' : '' ?></span></div>
                            <?php foreach ($cancelled ? [] : $w as $i => $msg): ?><div class="fs-12 text-warning" id="builder-list-shift-<?= $id ?>-warn-<?= (int) $i ?>"><i class="feather-alert-triangle me-1"></i><?= e($msg) ?></div><?php endforeach; ?>
                        </div>
                    </div>
                    <?php if (!$cancelled) { echo view('builder/partials/move-form.php', ['s' => $s, 'days' => $days, 'people' => $people, 'live' => $published, 'idp' => 'builder-list']); } ?>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="mb-3"><?= hx_link('/shifts/new?' . http_build_query(['site' => $siteId, 'date' => $day, 'position' => $positionId, 'back' => $here]), '<i class="feather-plus me-1"></i>Add a shift', 'btn btn-primary btn-touch w-100', 'id="builder-phone-add"') ?></div>
        <div class="fs-12 text-muted mb-3" id="builder-phone-total">Scheduled that day: <?= e(days_label($dayHours[$day] ?? 0)) ?> h<?= $canLabor && isset($dayCost[$day]) ? ' · ' . e($money($dayCost[$day])) : '' ?></div>
    </div>

    <!-- from 992 px: the grid -->
    <div class="card mb-3 d-none d-lg-block" id="builder-grid-card">
        <div class="table-responsive" id="builder-grid-scroll">
            <table class="table table-bordered mb-0 builder-grid" id="builder-grid"<?= $drag ? ' data-sortable="1"' : '' ?> data-view="<?= e($view) ?>">
                <thead><tr>
                    <th class="grid-name" id="builder-grid-corner"><?= $view === 'positions' ? 'Positions' : 'People' ?></th>
                    <?php foreach ($days as [$date, $dow, $dom, $isToday]): ?><th class="grid-day<?= $isToday ? ' is-today' : '' ?>" id="builder-grid-head-<?= e($date) ?>"><?= e($dow . ' ' . $dom) ?></th><?php endforeach; ?>
                    <?php if ($view === 'people'): ?><th class="grid-hours" id="builder-grid-head-hours">Hours</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php if ($view === 'people'): ?>
                    <tr id="builder-row-open">
                        <th class="grid-name open-name" scope="row">Open shifts<?= $openTotal > 0 ? ' <span class="badge bg-soft-primary text-primary ms-1" id="builder-open-count">' . $openTotal . '</span>' : '' ?></th>
                        <?php foreach ($days as [$date]): ?>
                            <td class="grid-cell" id="builder-cell-open-<?= e($date) ?>" data-day="<?= e($date) ?>" data-member="" data-group="people">
                                <?php foreach ($byCell['open'][$date] ?? [] as $s) { echo view('builder/partials/shift-block.php', ['s' => $s, 'warn' => $warn[$s['shift_id']] ?? [], 'view' => $view, 'drag' => $drag, 'here' => $here]); } ?>
                                <?= hx_link('/shifts/new?' . http_build_query(['site' => $siteId, 'date' => $date, 'position' => $positionId, 'back' => $here]), '<i class="feather-plus"></i>', 'grid-add', 'id="builder-add-open-' . e($date) . '" aria-label="Add an open shift on ' . e($date) . '"') ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="grid-hours"></td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $key => $row): ?>
                    <tr id="builder-row-<?= e($key) ?>">
                        <th class="grid-name" scope="row"><?php if ($view === 'positions'): ?><?= pos_swatch($row['color'], 'dot') ?> <?php endif; ?><?= e($row['name']) ?></th>
                        <?php foreach ($days as [$date]): ?>
                            <td class="grid-cell" id="builder-cell-<?= e($key) ?>-<?= e($date) ?>" data-day="<?= e($date) ?>" data-member="<?= $row['member'] === null ? '' : (int) $row['member'] ?>" data-group="<?= $view === 'people' ? 'people' : 'pos-' . (int) $row['id'] ?>">
                                <?php foreach ($byCell[$key][$date] ?? [] as $s) { echo view('builder/partials/shift-block.php', ['s' => $s, 'warn' => $warn[$s['shift_id']] ?? [], 'view' => $view, 'drag' => $drag, 'here' => $here]); } ?>
                                <?= hx_link('/shifts/new?' . http_build_query(['site' => $siteId, 'date' => $date, 'position' => $view === 'positions' ? $row['id'] : $positionId, 'assignee' => $row['member'], 'back' => $here]), '<i class="feather-plus"></i>', 'grid-add', 'id="builder-add-' . e($key) . '-' . e($date) . '" aria-label="Add a shift for ' . e($row['name']) . ' on ' . e($date) . '"') ?>
                            </td>
                        <?php endforeach; ?>
                        <?php if ($view === 'people'): [$hl, $hbar, $hk] = hours_bar($hours[$row['member']] ?? null); ?>
                            <td class="grid-hours" id="builder-hours-<?= (int) $row['member'] ?>" data-hours="<?= e(days_label((float) ($hours[$row['member']]['scheduled_hours'] ?? 0))) ?>">
                                <div class="fs-12 fw-semibold text-<?= $hk === 'primary' ? 'body' : e($hk) ?>"><?= e($hl) ?></div><?= $hbar ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr id="builder-foot-hours"><th class="grid-name" scope="row">Scheduled hours</th>
                        <?php foreach ($days as [$date]): ?><td class="fs-12 fw-semibold" id="builder-foot-hours-<?= e($date) ?>"><?= e(days_label($dayHours[$date] ?? 0)) ?> h</td><?php endforeach; ?>
                        <?php if ($view === 'people'): ?><td></td><?php endif; ?></tr>
                    <?php if ($canLabor): ?>
                    <tr id="builder-foot-cost"><th class="grid-name" scope="row">Labor cost</th>
                        <?php foreach ($days as [$date]): ?><td class="fs-12" id="builder-foot-cost-<?= e($date) ?>"><?= isset($dayCost[$date]) ? e($money($dayCost[$date])) : '—' ?></td><?php endforeach; ?>
                        <?php if ($view === 'people'): ?><td></td><?php endif; ?></tr>
                    <?php endif; ?>
                </tfoot>
            </table>
        </div>
    </div>

    <?php if ($needs !== []) { echo view('builder/partials/needs-strip.php', ['needs' => $needs, 'days' => $days]); } ?>

    <?php if ($canLabor): $budgetAmount = $labor['budget_amount'] ?? null; $cost = (float) ($labor['scheduled_cost'] ?? 0); $pct = $budgetAmount !== null && (float) $budgetAmount > 0 ? $cost / (float) $budgetAmount * 100 : null; ?>
        <div class="card mb-3" id="builder-labor"><div class="card-body p-3">
            <div class="d-flex justify-content-between gap-2 flex-wrap"><div class="fw-semibold">Labor this week</div>
                <div id="builder-labor-cost"><?= e($money($cost)) ?><?= $budgetAmount !== null ? ' of ' . e($money($budgetAmount)) . ' budget' : ' <span class="text-muted">(no budget set)</span>' ?></div></div>
            <?php if ($pct !== null): ?><div class="mt-2"><?= svg_bar($pct, $pct > 100 ? 'danger' : ($pct >= 90 ? 'warning' : 'primary'), 8) ?></div><?php endif; ?>
            <div class="fs-12 text-muted mt-1" id="builder-labor-hours"><?= e(days_label((float) ($labor['scheduled_hours'] ?? 0))) ?> scheduled hours<?= ($labor['budget_hours'] ?? null) !== null ? ' of ' . e(days_label((float) $labor['budget_hours'])) . ' budgeted' : '' ?></div>
        </div></div>
    <?php endif; ?>

    <?php if ($warnRows !== []): ?>
        <div class="card mb-3" id="builder-warnings">
            <div class="card-header"><h6 class="card-title mb-0">Warnings <span class="badge bg-soft-warning text-warning ms-1" id="builder-warnings-count"><?= count($warnRows) ?></span></h6></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($warnRows as $i => $w): ?>
                    <li class="list-group-item fs-12" id="builder-warning-<?= (int) $i ?>"><i class="feather-alert-triangle text-warning me-1"></i>
                        <?= hx_link(with_back('/shifts/' . (int) $w['shift_id'], $here), '<span class="fw-semibold">' . e($w['display_name']) . '</span>', 'text-dark') ?>: <?= e($w['message']) ?>
                        <?= $w['severity'] === 'hard' ? '<span class="badge bg-soft-danger text-danger ms-1">Hard</span>' : '' ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
