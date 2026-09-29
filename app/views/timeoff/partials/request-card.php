<?php
/**
 * One time-off request as a card (the RecordCard pattern). Data: r (a request row), mode = summary | approval, back, zone, withBalance, shifts (approval: the published shifts it covers), showMember
 * summary: state, dates, hours, the person's note, who decided. approval: + balance before and after, the shifts it would cover, Approve (with "Also open those shifts") and Decline.
 */
$id = (int) $r['request_id'];
$mode = $mode ?? 'summary';
[$bl, $bc] = time_off_status_badge((string) $r['status']);
$back = $back ?? '/time-off';
$shifts = $shifts ?? [];
$after = round((float) ($r['balance_hours'] ?? 0) - (float) $r['hours'], 2);
?>
<div class="card mb-3 request-card" id="request-card-<?= $id ?>">
    <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
            <div class="fs-12 text-muted" id="request-card-<?= $id ?>-who"><i class="feather-sun me-1"></i><?= !empty($showMember) ? e($r['member_name']) . ' · ' : '' ?><?= e($r['type_name']) ?> · <?= e($r['site_name']) ?></div>
            <span class="badge bg-soft-<?= e($bc) ?> text-<?= e($bc) ?> text-nowrap" id="request-card-<?= $id ?>-badge"><?= e($bl) ?></span>
        </div>
        <div class="fw-bold" id="request-card-<?= $id ?>-when"><?= hx_link(with_back('/time-off/' . $id, $back), e(time_off_when($r, $zone ?? false)), 'text-dark') ?></div>
        <div class="text-muted fs-12" id="request-card-<?= $id ?>-hours"><?= e(hours_label($r['hours'])) ?><?= $r['paid'] ? ' · paid' : ' · unpaid' ?></div>
        <?php if (($r['note'] ?? '') !== ''): ?><div class="mt-1" id="request-card-<?= $id ?>-note">&ldquo;<?= e($r['note']) ?>&rdquo;</div><?php endif; ?>
        <?php if ($r['status'] !== 'pending' && $r['decided_by_name'] !== null): ?>
            <div class="fs-12 text-muted mt-1" id="request-card-<?= $id ?>-decided"><?= e($bl) ?> by <?= e($r['decided_by_name']) ?><?= ($r['decision_note'] ?? '') !== '' ? ': ' . e($r['decision_note']) : '' ?></div>
        <?php endif; ?>
        <?php if ($mode === 'approval'): ?>
            <?php if (!empty($withBalance) && $r['tracks_balance']): ?>
                <div class="fs-12 mt-2 <?= $after < 0 && !$r['allow_negative'] ? 'text-danger' : '' ?>" id="request-card-<?= $id ?>-balance"><i class="feather-clock me-1"></i>Balance <?= e(hours_label($r['balance_hours'] ?? 0)) ?> — this uses <?= e(hours_label($r['hours'])) ?>, leaving <?= e(hours_label($after)) ?>.<?= $after < 0 && !$r['allow_negative'] ? ' It cannot be approved: not enough is left.' : '' ?></div>
            <?php endif; ?>
            <div class="mt-2" id="request-card-<?= $id ?>-shifts">
                <?php if ($shifts === []): ?><div class="fs-12 text-muted">No scheduled shifts fall in this time.</div>
                <?php else: ?>
                    <div class="fs-12 fw-semibold mb-1">Shifts this would cover</div>
                    <?php foreach ($shifts as $s): ?><div class="fs-12" id="request-card-<?= $id ?>-shift-<?= (int) $s['shift_id'] ?>"><?= hx_link(with_back('/shifts/' . (int) $s['shift_id'], $back), e(shift_when((string) $s['starts_at'], (string) $s['ends_at'], (string) $s['timezone'], $zone ?? false)), 'text-dark') ?> <span class="text-muted">· <?= e($s['position_name']) ?></span></div><?php endforeach; ?>
                <?php endif; ?>
            </div>
            <form method="post" action="/time-off/approve.php" class="mt-3" id="request-form-<?= $id ?>">
                <?= csrf_field() ?><input type="hidden" name="request" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                <input type="text" name="note" id="request-form-<?= $id ?>-field-note" class="form-control btn-touch mb-2" maxlength="500" placeholder="Note (optional)" aria-label="Note">
                <?php if ($shifts !== []): ?>
                    <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="request-form-<?= $id ?>-field-open"><input type="checkbox" class="form-check-input mt-0" name="open_shifts" value="yes" id="request-form-<?= $id ?>-field-open">Also open those shifts</label>
                <?php endif; ?>
                <div class="row g-2">
                    <div class="col-6"><button type="submit" class="btn btn-primary btn-touch w-100" id="request-card-<?= $id ?>-approve-btn" hx-post="/time-off/approve.php" hx-target="#flash">Approve</button></div>
                    <div class="col-6"><button type="submit" class="btn btn-light btn-touch w-100" id="request-card-<?= $id ?>-decline-btn" formaction="/time-off/decline.php" hx-post="/time-off/decline.php" hx-target="#flash">Decline</button></div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
