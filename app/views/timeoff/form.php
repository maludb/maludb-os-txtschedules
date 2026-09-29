<?php
/** Ask for time off (screen `time-off-add`). Data: site, types, type, v (from, to, from_time, to_time, hours), member, me, people, preview, multiSite, sites, canApprove */
$siteId = (int) $site['site_id'];
?>
<?= view('shared/header.php', ['id' => 'time-off-add', 'title' => 'Ask for time off', 'crumbs' => [['Home', '/'], ['Time off', '/time-off'], ['Ask', null]]]) ?>
<div class="main-content" id="time-off-add-content">
    <?php if ($multiSite): ?>
        <div class="d-flex flex-wrap gap-1 mb-3" id="time-off-form-sites">
            <?php foreach ($sites as $s): ?><?= hx_link('/time-off/new?site=' . (int) $s['scope_id'], e($s['name']), 'btn btn-touch ' . ((int) $s['scope_id'] === $siteId ? 'btn-primary' : 'btn-light'), 'id="time-off-form-site-' . (int) $s['scope_id'] . '"') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($types === []): ?>
        <div class="card" id="time-off-form-none"><div class="card-body text-center py-4 text-muted">This restaurant has no kinds of time off set up yet. A manager can add them.</div></div>
    <?php else: ?>
    <form method="post" action="/time-off/request.php" hx-post="/time-off/request.php" hx-target="#flash" id="time-off-form">
        <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>">
        <div class="card mb-3"><div class="card-body">
            <?php if ($people !== []): ?>
                <label class="form-label fs-12 text-muted" for="time-off-form-field-member">For</label>
                <select name="member" id="time-off-form-field-member" class="form-select btn-touch mb-3">
                    <option value="<?= (int) $me ?>">Me</option>
                    <?php foreach ($people as $p): if ($p['member_id'] === $me) { continue; } ?><option value="<?= (int) $p['member_id'] ?>" <?= $p['member_id'] === $member ? 'selected' : '' ?>><?= e($p['display_name']) ?></option><?php endforeach; ?>
                </select>
            <?php endif; ?>
            <label class="form-label fs-12 text-muted" for="time-off-form-field-type">Kind of time off</label>
            <select name="time_off_type" id="time-off-form-field-type" class="form-select btn-touch mb-3" required>
                <?php foreach ($types as $t): ?><option value="<?= (int) $t['type_id'] ?>" <?= $type !== null && $t['type_id'] === $type['type_id'] ? 'selected' : '' ?>><?= e($t['name']) ?><?= $t['paid'] ? '' : ' (unpaid)' ?></option><?php endforeach; ?>
            </select>
            <div class="fs-12 text-muted mb-1" id="time-off-form-zone">Dates and times are <?= e($site['name']) ?> time (<?= e($site['timezone']) ?>).</div>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="time-off-form-field-from">From</label>
                    <input type="date" name="from" id="time-off-form-field-from" class="form-control btn-touch" value="<?= e($v['from']) ?>" required></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="time-off-form-field-to">Through</label>
                    <input type="date" name="to" id="time-off-form-field-to" class="form-control btn-touch" value="<?= e($v['to']) ?>"></div>
            </div>
            <details class="mb-3" id="time-off-form-part"<?= $v['from_time'] !== '' || $v['to_time'] !== '' ? ' open' : '' ?>>
                <summary class="btn btn-light btn-touch w-100 text-start" id="time-off-form-part-summary">Only part of the day?</summary>
                <div class="row g-2 mt-1">
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="time-off-form-field-from-time">From</label>
                        <input type="time" name="from_time" id="time-off-form-field-from-time" class="form-control btn-touch" value="<?= e($v['from_time']) ?>"></div>
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="time-off-form-field-to-time">To</label>
                        <input type="time" name="to_time" id="time-off-form-field-to-time" class="form-control btn-touch" value="<?= e($v['to_time']) ?>"></div>
                </div>
            </details>
            <label class="form-label fs-12 text-muted" for="time-off-form-field-hours">Hours (leave empty to count <?= e(days_label($site['time_off_day_hours'])) ?> a day)</label>
            <input type="number" name="hours" id="time-off-form-field-hours" class="form-control btn-touch mb-2" min="0.25" max="2000" step="0.25" value="<?= e($v['hours']) ?>" inputmode="decimal">
            <div id="time-off-form-preview" class="mb-3" hx-get="/time-off/form.php?preview=1" hx-trigger="change from:#time-off-form delay:150ms" hx-include="#time-off-form" hx-target="this" hx-swap="innerHTML" hx-sync="this:replace"><?= view('timeoff/partials/preview.php', ['p' => $preview, 'type' => $type]) ?></div>
            <label class="form-label fs-12 text-muted" for="time-off-form-field-note">Note (optional)</label>
            <input type="text" name="note" id="time-off-form-field-note" class="form-control btn-touch" maxlength="500" value="">
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="time-off-form-save-btn">Ask for time off</button>
        <?= hx_link('/time-off', 'Cancel', 'btn btn-light btn-touch w-100 mb-3', 'id="time-off-form-cancel-link"') ?>
    </form>
    <?php endif; ?>
</div>
