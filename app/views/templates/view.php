<?php
/** One template (screen `template-view`). Data: site, tpl, rows (its shifts), weeks [[week_start, label, state]], want (the week asked for, or null) */
$siteId = (int) $site['site_id'];
$id = (int) $tpl['template_id'];
$dowNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$order = [];
for ($i = 0; $i < 7; $i++) { $order[] = ((int) $site['week_start'] + $i) % 7; }
$by = [];
foreach ($rows as $r) { $by[$r['weekday']][] = $r; }
$hm = static function (string $t): string { $d = DateTimeImmutable::createFromFormat('H:i', substr($t, 0, 5)); return $d === false ? $t : ltrim($d->format('g:i'), '0') . ' ' . $d->format('a'); };
?>
<?= view('shared/header.php', ['id' => 'template-view', 'title' => $tpl['name'], 'crumbs' => [['Home', '/'], ['Templates', '/templates/?site=' . $siteId], [$tpl['name'], null]]]) ?>
<div class="main-content" id="template-view-content">
    <div class="card mb-3" id="template-start"><div class="card-body">
        <div class="fw-semibold mb-2">Start a week from it</div>
        <form method="post" action="/templates/apply.php" hx-post="/templates/apply.php" hx-target="#flash" id="template-apply-form">
            <?= csrf_field() ?><input type="hidden" name="template" value="<?= $id ?>"><input type="hidden" name="site" value="<?= $siteId ?>">
            <label class="form-label fs-12 text-muted" for="template-form-field-week">Week</label>
            <select name="week_start" id="template-form-field-week" class="form-select btn-touch mb-2">
                <?php foreach ($weeks as $w): ?><option value="<?= e($w['week_start']) ?>" <?= $w['state'] === 'published' ? 'disabled' : '' ?> <?= $want === $w['week_start'] ? 'selected' : '' ?>><?= e($w['label']) ?> — <?= $w['state'] === 'new' ? 'not started' : ($w['state'] === 'draft' ? 'draft' : 'published') ?></option><?php endforeach; ?>
            </select>
            <div class="fs-12 text-muted mb-2">A shift whose person has time off or would break a hard rule is left open and listed. Shifts already in the week stay.</div>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="template-apply-btn"<?= $rows === [] ? ' disabled' : '' ?>>Start the week</button>
        </form>
    </div></div>

    <?php foreach ($order as $dow): ?>
        <div class="card mb-2 template-day" id="template-day-<?= (int) $dow ?>">
            <div class="card-header py-2"><h6 class="card-title mb-0"><?= e($dowNames[$dow]) ?></h6></div>
            <?php if (($by[$dow] ?? []) === []): ?><div class="card-body py-2 fs-12 text-muted">Nothing on this day.</div><?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($by[$dow] as $r): ?>
                        <li class="list-group-item d-flex gap-2 align-items-center" id="template-shift-<?= (int) $r['template_shift_id'] ?>">
                            <?= pos_swatch($r['position_color'], 'dot') ?>
                            <span class="fw-semibold"><?= e($hm($r['starts_at']) . '–' . $hm($r['ends_at'])) ?></span>
                            <span class="text-muted"><?= e($r['position_name']) ?></span>
                            <span class="ms-auto <?= $r['assignee_name'] === null ? 'text-muted' : '' ?>"><?= e($r['assignee_name'] ?? 'Open') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <details class="mb-3 mt-3" id="template-rename-details"><summary class="btn btn-light btn-touch w-100" id="template-rename-open">Rename</summary>
        <form method="post" action="/templates/save.php" hx-post="/templates/save.php" hx-target="#flash" class="pt-2" id="template-rename-form">
            <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>"><input type="hidden" name="template" value="<?= $id ?>">
            <label class="form-label fs-12 text-muted" for="template-rename-field-name">Name</label>
            <input type="text" name="name" id="template-rename-field-name" class="form-control btn-touch mb-2" maxlength="80" value="<?= e($tpl['name']) ?>" required>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="template-rename-save-btn">Save the name</button>
        </form>
    </details>
    <form method="post" action="/templates/archive.php" hx-post="/templates/archive.php" hx-target="#flash" hx-confirm="Archive this template? It leaves the list." id="template-archive-form">
        <?= csrf_field() ?><input type="hidden" name="template" value="<?= $id ?>">
        <button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="template-archive-btn"><i class="feather-archive me-1"></i>Archive</button>
    </form>
</div>
