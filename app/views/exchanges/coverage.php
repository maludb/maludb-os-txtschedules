<?php
/** Coverage (screen `coverage`). Data: shift, candidates, open, siteId, zone, back, site, notice */
$here = $shift === null ? '/coverage' : '/coverage?shift=' . (int) $shift['shift_id'];
?>
<?= view('shared/header.php', ['id' => 'coverage', 'title' => 'Coverage', 'crumbs' => [['Home', '/'], ['Coverage', $shift === null ? null : '/coverage'], ...($shift === null ? [] : [['Cover a shift', null]])], 'back' => $back]) ?>
<div class="main-content" id="coverage-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($shift === null): ?>
        <div class="fw-semibold mb-2" id="coverage-open-title">Open shifts at <?= e($site['name'] ?? 'this restaurant') ?></div>
        <?php if ($open === []): ?><div class="card" id="coverage-empty"><div class="card-body text-center text-muted py-4">No open shifts to cover. To cover a shift someone holds, open it and choose Find cover.</div></div><?php endif; ?>
        <?php foreach ($open as $s) { echo view('schedule/partials/shift-card.php', ['s' => $s, 'back' => $here, 'showSite' => false, 'zone' => $zone]); ?>
            <div class="mb-3 -mt-1"><?= hx_link('/coverage?shift=' . (int) $s['shift_id'], '<i class="feather-life-buoy me-1"></i>Find cover', 'btn btn-light btn-touch w-100', 'id="coverage-find-' . (int) $s['shift_id'] . '"') ?></div>
        <?php } ?>
    <?php else: $id = (int) $shift['shift_id']; ?>
        <div class="card mb-3" id="coverage-shift"><div class="card-body d-flex gap-3 align-items-stretch">
            <?= pos_swatch($shift['position_color']) ?>
            <div class="flex-grow-1">
                <div class="fw-bold" id="coverage-shift-when"><?= hx_link(with_back('/shifts/' . $id, $here), e(shift_when($shift['starts_at'], $shift['ends_at'], $shift['timezone'], $zone)), 'text-dark') ?></div>
                <div class="text-muted fs-12"><?= e($shift['position_name']) ?> · <?= e($shift['site_name']) ?></div>
                <div class="fs-12 mt-1"><?= $shift['assignee_name'] !== null ? 'Held by ' . e($shift['assignee_name']) . ' — whoever says yes first takes it over.' : 'Nobody has it yet.' ?></div>
            </div>
        </div></div>
        <?php if ($shift['exchange_id'] !== null): ?>
            <div class="card mb-3" id="coverage-live"><div class="card-body">It is already up. <?= hx_link('/exchanges/' . (int) $shift['exchange_id'], 'See the trade', 'fw-semibold') ?></div></div>
        <?php elseif ($shift['status'] !== 'scheduled' || $shift['published_at'] === null): ?>
            <div class="card mb-3" id="coverage-not-live"><div class="card-body text-muted">That shift is not on the published schedule.</div></div>
        <?php else: ?>
        <form method="post" action="/exchanges/coverage.php" hx-post="/exchanges/coverage.php" hx-target="#flash" id="coverage-form">
            <?= csrf_field() ?><input type="hidden" name="shift" value="<?= $id ?>">
            <div class="card mb-3" id="coverage-candidates">
                <div class="card-header"><h5 class="card-title mb-0">Who could take it</h5><span class="fs-12 text-muted">fewest hours first</span></div>
                <ul class="list-group list-group-flush">
                    <?php if ($candidates === []): ?><li class="list-group-item text-muted" id="coverage-candidates-empty">Nobody at <?= e($shift['site_name']) ?> is free for this shift.</li><?php endif; ?>
                    <?php foreach ($candidates as $c): $cid = (int) $c['member_id']; ?>
                        <li class="list-group-item" id="coverage-candidate-<?= $cid ?>">
                            <label class="d-flex align-items-center gap-3 btn-touch mb-0" for="coverage-form-field-members-<?= $cid ?>">
                                <input type="checkbox" class="form-check-input mt-0" name="members[]" value="<?= $cid ?>" id="coverage-form-field-members-<?= $cid ?>">
                                <span class="flex-grow-1"><span class="fw-semibold"><?= e($c['display_name']) ?></span>
                                    <span class="text-muted fs-12 d-block"><?= e(days_label($c['hours_this_week'])) ?> h already this week</span></span>
                            </label>
                            <?php foreach ($c['warnings'] as $w): ?><span class="badge bg-soft-warning text-warning ms-4 mt-1 text-wrap text-start"><i class="feather-alert-triangle me-1"></i><?= e($w['message'] ?? '') ?></span><?php endforeach; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php if ($candidates !== []): ?>
                <label class="form-label fs-12 text-muted" for="coverage-form-field-note">Note (optional)</label>
                <input type="text" name="note" id="coverage-form-field-note" class="form-control btn-touch mb-3" maxlength="500">
                <button type="submit" class="btn btn-primary btn-touch w-100 mb-3" id="coverage-form-save-btn"><i class="feather-users me-1"></i>Ask these people</button>
            <?php endif; ?>
        </form>
        <?php if ($shift['assignee_name'] === null && ($site['allow_pickup'] ?? false)): ?>
            <form method="post" action="/exchanges/open.php" hx-post="/exchanges/open.php" hx-target="#flash" id="coverage-open-form"><?= csrf_field() ?><input type="hidden" name="shift" value="<?= $id ?>">
                <button type="submit" class="btn btn-light btn-touch w-100" id="coverage-open-btn">Or put it on the marketplace for anyone to take</button></form>
        <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
