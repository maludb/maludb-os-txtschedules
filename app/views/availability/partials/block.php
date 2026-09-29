<?php
/**
 * One availability block (RecordCard row). Data: b (a block row), mode = day | pending | approval, may = [remove, decide], back (return_to), showMember, multi
 * day / pending: kind, hours, dates, the restaurant; Remove. approval: the person, and a note with Approve / Decline.
 */
$id = (int) $b['availability_id'];
[$kl, $kc] = availability_kind_badge((string) $b['kind']);
$where = $b['site_id'] === null ? 'Every restaurant' : (string) $b['site_name'];
$mode = $mode ?? 'day';
?>
<div class="availability-block py-2 border-top" id="availability-block-<?= $id ?>">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0">
            <span class="badge bg-soft-<?= e($kc) ?> text-<?= e($kc) ?>" id="availability-block-<?= $id ?>-kind"><?= e($kl) ?></span>
            <span class="fw-semibold ms-1" id="availability-block-<?= $id ?>-when"><?= e(availability_span((string) $b['starts_at'], (string) $b['ends_at'])) ?></span>
            <div class="fs-12 text-muted" id="availability-block-<?= $id ?>-meta">
                <?php if (!empty($showMember)): ?><?= e($b['member_name']) ?> · <?= e(substr(WEEKDAY_NAMES[(int) $b['weekday']], 0, 3)) ?> · <?php endif; ?>
                <?= e(availability_dates($b)) ?><?= ($multi ?? false) || $mode === 'approval' ? ' · ' . e($where) : '' ?>
            </div>
            <?php if ($b['status'] === 'pending' && $mode !== 'approval'): ?>
                <div class="fs-12 text-warning mt-1" id="availability-block-<?= $id ?>-waiting"><i class="feather-clock me-1"></i>Waiting for a manager — it counts for nothing until approved.</div>
            <?php endif; ?>
        </div>
        <?php if ($mode !== 'approval' && !empty($may['remove'])): ?>
            <form method="post" action="/availability/remove.php" hx-post="/availability/remove.php" hx-target="#flash" hx-confirm="Remove this block?" class="flex-shrink-0">
                <?= csrf_field() ?><input type="hidden" name="availability" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                <button type="submit" class="btn btn-light btn-touch px-3" id="availability-block-<?= $id ?>-remove-btn" aria-label="Remove this block"><i class="feather-trash-2"></i></button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($mode === 'approval'): ?>
        <form method="post" action="/availability/approve.php" class="mt-2" id="availability-approval-<?= $id ?>">
            <?= csrf_field() ?><input type="hidden" name="availability" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
            <input type="text" name="note" id="availability-approval-<?= $id ?>-field-note" class="form-control btn-touch mb-2" maxlength="500" placeholder="Note (optional)" aria-label="Note">
            <div class="row g-2">
                <div class="col-6"><button type="submit" class="btn btn-primary btn-touch w-100" id="availability-block-<?= $id ?>-approve-btn" hx-post="/availability/approve.php" hx-target="#flash">Approve</button></div>
                <div class="col-6"><button type="submit" class="btn btn-light btn-touch w-100" id="availability-block-<?= $id ?>-decline-btn" formaction="/availability/decline.php" hx-post="/availability/decline.php" hx-target="#flash">Decline</button></div>
            </div>
        </form>
    <?php endif; ?>
</div>
