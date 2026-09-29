<?php
/** Time off request (screen `time-off-view`). Data: r, mine, approver, canDecide, canCancel, withBalance, shifts, history, back, notice, zone */
$id = (int) $r['request_id'];
$here = '/time-off/' . $id;
[$bl, $bc] = time_off_status_badge((string) $r['status']);
$tz = (string) $r['timezone'];
$after = round((float) ($r['balance_hours'] ?? 0) - (float) $r['hours'], 2);
?>
<?= view('shared/header.php', ['id' => 'time-off-view', 'title' => 'Time off', 'crumbs' => [['Home', '/'], ['Time off', '/time-off'], [$r['type_name'], null]], 'back' => $back]) ?>
<div class="main-content" id="time-off-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="time-off-card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div class="fw-semibold" id="time-off-headline"><i class="feather-sun me-1"></i><?= $mine ? 'Your' : e($r['member_name']) . '&rsquo;s' ?> <?= e($r['type_name']) ?></div>
                <span class="badge bg-soft-<?= e($bc) ?> text-<?= e($bc) ?> text-nowrap" id="time-off-status"><?= e($bl) ?></span>
            </div>
            <div class="fw-bold fs-5" id="time-off-when"><?= e(time_off_when($r, $zone)) ?></div>
            <dl class="row fs-12 mb-0 mt-2">
                <dt class="col-4 text-muted">Person</dt><dd class="col-8" id="time-off-person"><?= e($r['member_name']) ?></dd>
                <dt class="col-4 text-muted">Restaurant</dt><dd class="col-8" id="time-off-site"><?= e($r['site_name']) ?> <span class="text-muted">(<?= e($tz) ?>)</span></dd>
                <dt class="col-4 text-muted">Hours</dt><dd class="col-8" id="time-off-hours"><?= e(hours_label($r['hours'])) ?><?= $r['paid'] ? ' · paid' : ' · unpaid' ?></dd>
                <dt class="col-4 text-muted">Asked</dt><dd class="col-8"><?= e(format_ts((string) $r['created_at'], $tz, 'D M j, g:i a')) ?></dd>
                <?php if (($r['note'] ?? '') !== ''): ?><dt class="col-4 text-muted">Note</dt><dd class="col-8" id="time-off-note"><?= e($r['note']) ?></dd><?php endif; ?>
                <?php if ($r['status'] !== 'pending' && $r['decided_by_name'] !== null): ?><dt class="col-4 text-muted">Decided</dt><dd class="col-8" id="time-off-decided"><?= e($bl) ?> by <?= e($r['decided_by_name']) ?>, <?= e(format_ts((string) $r['decided_at'], $tz, 'D M j')) ?></dd><?php endif; ?>
                <?php if (($r['decision_note'] ?? '') !== ''): ?><dt class="col-4 text-muted">Decision</dt><dd class="col-8" id="time-off-decision-note"><?= e($r['decision_note']) ?></dd><?php endif; ?>
            </dl>
            <?php if ($withBalance && $r['tracks_balance']): ?>
                <div class="fs-12 mt-3 <?= $r['status'] === 'pending' && $after < 0 && !$r['allow_negative'] ? 'text-danger' : '' ?>" id="time-off-balance"><i class="feather-clock me-1"></i>
                    <?php if ($r['status'] === 'pending'): ?>Balance <?= e(hours_label($r['balance_hours'] ?? 0)) ?> — this uses <?= e(hours_label($r['hours'])) ?>, leaving <?= e(hours_label($after)) ?>.<?= $after < 0 && !$r['allow_negative'] ? ' It cannot be approved: not enough is left.' : '' ?>
                    <?php else: ?>Balance now <?= e(hours_label($r['balance_hours'] ?? 0)) ?>. <?= hx_link(with_back('/time-off/balances?member=' . (int) $r['member_id'] . '&site=' . (int) $r['site_id'], $here), 'See the ledger', 'fw-semibold') ?><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (in_array($r['status'], ['pending', 'approved'], true)): ?>
    <div class="card mb-3" id="time-off-shifts">
        <div class="card-header"><h5 class="card-title mb-0"><?= $r['status'] === 'pending' ? 'Shifts this would cover' : 'Shifts still on the schedule' ?></h5></div>
        <ul class="list-group list-group-flush">
            <?php if ($shifts === []): ?><li class="list-group-item text-muted" id="time-off-shifts-empty">No scheduled shifts fall in this time.</li><?php endif; ?>
            <?php foreach ($shifts as $s): ?>
                <li class="list-group-item d-flex gap-3 align-items-stretch" id="time-off-shift-<?= (int) $s['shift_id'] ?>"><?= pos_swatch($s['position_color']) ?>
                    <div><?= hx_link(with_back('/shifts/' . (int) $s['shift_id'], $here), e(shift_when((string) $s['starts_at'], (string) $s['ends_at'], (string) $s['timezone'], $zone)), 'fw-semibold text-dark') ?><div class="fs-12 text-muted"><?= e($s['position_name']) ?></div></div></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($canDecide): ?>
    <div class="card mb-3" id="time-off-decide">
        <div class="card-header"><h5 class="card-title mb-0">Decide</h5></div>
        <div class="card-body">
            <form method="post" action="/time-off/approve.php" id="time-off-decide-form">
                <?= csrf_field() ?><input type="hidden" name="request" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <label class="form-label fs-12 text-muted" for="time-off-form-field-note">Note (optional)</label>
                <input type="text" name="note" id="time-off-form-field-note" class="form-control btn-touch mb-3" maxlength="500">
                <?php if ($shifts !== []): ?>
                    <label class="d-flex align-items-center gap-2 border rounded px-3 mb-3 btn-touch" for="time-off-decide-field-open"><input type="checkbox" class="form-check-input mt-0" name="open_shifts" value="yes" id="time-off-decide-field-open">Also open those shifts</label>
                <?php endif; ?>
                <div class="row g-2">
                    <div class="col-6"><button type="submit" class="btn btn-primary btn-touch w-100" id="time-off-approve-btn" hx-post="/time-off/approve.php" hx-target="#flash">Approve</button></div>
                    <div class="col-6"><button type="submit" class="btn btn-light btn-touch w-100" id="time-off-decline-btn" formaction="/time-off/decline.php" hx-post="/time-off/decline.php" hx-target="#flash">Decline</button></div>
                </div>
            </form>
        </div>
    </div>
    <?php elseif ($r['status'] === 'pending'): ?>
        <div class="card mb-3" id="time-off-waiting"><div class="card-body text-muted">Waiting for a manager to decide.</div></div>
    <?php endif; ?>

    <?php if ($canCancel): ?>
        <form method="post" action="/time-off/cancel.php" hx-post="/time-off/cancel.php" hx-target="#flash" hx-confirm="Cancel this time off?<?= $r['status'] === 'approved' ? ' Its hours go back to the balance.' : '' ?>" class="mb-3" id="time-off-cancel-form">
            <?= csrf_field() ?><input type="hidden" name="request" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <button type="submit" class="btn btn-light btn-touch w-100" id="time-off-cancel-btn"><i class="feather-x me-1"></i>Cancel this time off</button>
        </form>
    <?php endif; ?>

    <div class="card" id="time-off-history">
        <div class="card-header"><h5 class="card-title mb-0">History</h5></div>
        <ul class="list-group list-group-flush">
            <?php if ($history === []): ?><li class="list-group-item text-muted" id="time-off-history-empty">Nothing you may see yet.</li><?php endif; ?>
            <?php foreach ($history as $h): ?>
                <li class="list-group-item" id="time-off-history-<?= (int) $h['activity_id'] ?>"><div><?= e(activity_words($h)) ?></div><div class="fs-11 text-muted"><?= e(format_ts($h['occurred_at'], $tz, 'D M j, g:i a')) ?></div></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
