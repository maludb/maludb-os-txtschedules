<?php
/**
 * Shift view (screen `shift-view`). Data: s, site, isHolder, live (its exchange), canTrade, liveShift, inCutoff, canCover, give (colleagues), swapWith, swapShifts,
 * history, me, back, notice, zone, canApprove, manage (a builder's tools: live, builder, people, days, over — or null)
 */
$id = (int) $s['shift_id'];
$here = '/shifts/' . $id;
$role = $isHolder ? 'Your shift' : ($s['is_open'] ? 'Open shift — nobody has it yet' : 'Working: ' . $s['assignee_name']);
$may = $isHolder && $canTrade && $liveShift && $live === null;
$others = shift_names($s['others'] ?? [], 6);
?>
<?= view('shared/header.php', ['id' => 'shift', 'title' => 'Shift', 'crumbs' => [['Home', '/'], $manage !== null ? ['Builder', $manage['builder']] : ['My schedule', '/my-schedule'], [shift_when($s['starts_at'], $s['ends_at'], $s['timezone']), null]], 'back' => $back]) ?>
<div class="main-content" id="shift-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="shift-card">
        <div class="card-body d-flex gap-3 align-items-stretch">
            <?= pos_swatch($s['position_color']) ?>
            <div class="flex-grow-1 min-w-0">
                <div class="fw-bold fs-5" id="shift-when"><?= e(shift_when($s['starts_at'], $s['ends_at'], $s['timezone'], $zone)) ?></div>
                <div class="text-muted" id="shift-position"><?= e($s['position_name']) ?> · <?= e($s['site_name']) ?></div>
                <div class="mt-2" id="shift-role"><span class="badge bg-soft-<?= $isHolder ? 'primary text-primary' : 'secondary text-dark' ?>"><?= e($role) ?></span>
                    <?php if ($s['status'] === 'cancelled'): ?><span class="badge bg-soft-danger text-danger ms-1" id="shift-cancelled">Cancelled</span><?php endif; ?></div>
                <div class="fs-12 text-muted mt-2" id="shift-hours"><?= e(days_label($s['paid_hours'])) ?> paid hours<?= (int) $s['break_minutes'] > 0 ? ' · ' . (int) $s['break_minutes'] . ' min unpaid break' : '' ?></div>
                <?php if (($s['note'] ?? '') !== ''): ?><div class="mt-2" id="shift-note"><?= e($s['note']) ?></div><?php endif; ?>
                <div class="fs-12 mt-2" id="shift-others"><?= $others !== '' ? 'Also on: ' . e($others) : '<span class="text-muted">Nobody else is on at this time.</span>' ?></div>
            </div>
        </div>
    </div>

    <?php if ($live !== null): [$bl, $bc] = exchange_status_badge($live); ?>
    <div class="card mb-3" id="shift-exchange">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                <div class="fw-semibold"><i class="<?= e(EXCHANGE_KIND_ICON[$live['kind']] ?? 'feather-repeat') ?> me-1"></i><?= e(EXCHANGE_KIND_WORD[$live['kind']] ?? 'Trade') ?></div>
                <span class="badge bg-soft-<?= e($bc) ?> text-<?= e($bc) ?>" id="shift-exchange-badge"><?= e($bl) ?></span>
            </div>
            <div class="fs-12 text-muted">Closes <?= e(format_ts($live['expires_at'], $s['timezone'], 'D M j, g:i a')) ?></div>
            <div class="mt-2"><?= hx_link(with_back('/exchanges/' . (int) $live['exchange_id'], $here), 'See the trade', 'fs-12 fw-semibold', 'id="shift-exchange-link"') ?></div>
            <?php if ($isHolder || $canCover || $canApprove): ?>
                <form method="post" action="/exchanges/cancel.php" hx-post="/exchanges/cancel.php" hx-target="#flash" hx-confirm="Withdraw this trade? The shift stays with its holder." class="mt-3" id="shift-withdraw-form">
                    <?= csrf_field() ?><input type="hidden" name="exchange" value="<?= (int) $live['exchange_id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                    <button type="submit" class="btn btn-light btn-touch w-100" id="shift-withdraw-btn"><i class="feather-x me-1"></i>Withdraw</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isHolder && $liveShift && $live === null): ?>
    <div class="card mb-3" id="shift-actions">
        <div class="card-header"><h5 class="card-title mb-0">What you can do</h5></div>
        <div class="card-body">
            <?php if (!$canTrade): ?>
                <div class="text-muted">You may not trade shifts here.</div>
            <?php elseif ($inCutoff): ?>
                <div class="text-muted" id="shift-too-close">Too close to the start of the shift to change hands.</div>
            <?php elseif (!$site['allow_offer'] && !$site['allow_give'] && !$site['allow_swap']): ?>
                <div class="text-muted" id="shift-no-trades">This restaurant does not let staff trade shifts.</div>
            <?php else: ?>
                <?php if ($site['allow_offer']): ?>
                <form method="post" action="/exchanges/offer.php" hx-post="/exchanges/offer.php" hx-target="#flash" class="mb-2" id="offer-form">
                    <?= csrf_field() ?><input type="hidden" name="shift" value="<?= $id ?>">
                    <details id="shift-offer-details"><summary class="btn btn-primary btn-touch w-100" id="shift-offer-open">Offer this shift</summary>
                        <div class="pt-3"><label class="form-label fs-12 text-muted" for="offer-form-field-note">Note for your colleagues (optional)</label>
                            <input type="text" name="note" id="offer-form-field-note" class="form-control btn-touch mb-2" maxlength="500">
                            <button type="submit" class="btn btn-primary btn-touch w-100" id="offer-form-save-btn">Put it up</button></div>
                    </details>
                </form>
                <?php endif; ?>
                <?php if ($site['allow_give']): ?>
                <form method="post" action="/exchanges/give.php" hx-post="/exchanges/give.php" hx-target="#flash" class="mb-2" id="give-form">
                    <?= csrf_field() ?><input type="hidden" name="shift" value="<?= $id ?>">
                    <details id="shift-give-details"><summary class="btn btn-light btn-touch w-100" id="shift-give-open">Give to a colleague…</summary>
                        <div class="pt-3">
                            <?php if ($give === []): ?><div class="text-muted fs-12">No colleague here works this position.</div><?php else: ?>
                            <label class="form-label fs-12 text-muted" for="give-form-field-colleague">Colleague</label>
                            <select name="colleague" id="give-form-field-colleague" class="form-select btn-touch mb-2" required>
                                <option value="">Choose…</option>
                                <?php foreach ($give as $c): ?><option value="<?= (int) $c['member_id'] ?>"><?= e($c['display_name']) ?></option><?php endforeach; ?>
                            </select>
                            <label class="form-label fs-12 text-muted" for="give-form-field-note">Note (optional)</label>
                            <input type="text" name="note" id="give-form-field-note" class="form-control btn-touch mb-2" maxlength="500">
                            <button type="submit" class="btn btn-primary btn-touch w-100" id="give-form-save-btn">Ask them</button>
                            <?php endif; ?>
                        </div>
                    </details>
                </form>
                <?php endif; ?>
                <?php if ($site['allow_swap']): ?>
                <details id="shift-swap-details" <?= $swapWith !== null ? 'open' : '' ?>><summary class="btn btn-light btn-touch w-100" id="shift-swap-open">Swap with a colleague…</summary>
                    <div class="pt-3">
                    <?php if ($give === []): ?><div class="text-muted fs-12">No colleague here works this position.</div><?php else: ?>
                        <form method="get" action="/shifts/<?= $id ?>" class="mb-3" id="swap-pick-form" hx-get="/shifts/<?= $id ?>" hx-target="#page-content" hx-push-url="true">
                            <label class="form-label fs-12 text-muted" for="swap-form-field-with">Colleague</label>
                            <div class="d-flex gap-2">
                                <select name="swap_with" id="swap-form-field-with" class="form-select btn-touch" required>
                                    <option value="">Choose…</option>
                                    <?php foreach ($give as $c): ?><option value="<?= (int) $c['member_id'] ?>" <?= $swapWith === (int) $c['member_id'] ? 'selected' : '' ?>><?= e($c['display_name']) ?></option><?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-light btn-touch" id="swap-pick-btn">Show their shifts</button>
                            </div>
                        </form>
                        <?php if ($swapWith !== null): ?>
                            <?php if ($swapShifts === []): ?><div class="text-muted fs-12" id="swap-none">They have no upcoming shifts to swap for.</div><?php else: ?>
                            <form method="post" action="/exchanges/swap.php" hx-post="/exchanges/swap.php" hx-target="#flash" id="swap-form">
                                <?= csrf_field() ?><input type="hidden" name="shift" value="<?= $id ?>"><input type="hidden" name="colleague" value="<?= (int) $swapWith ?>">
                                <div class="fs-12 text-muted mb-1">Their shift you would take</div>
                                <?php foreach ($swapShifts as $w): ?>
                                    <label class="d-flex align-items-center gap-2 border rounded p-3 mb-2 btn-touch" for="swap-form-field-swap_shift-<?= (int) $w['shift_id'] ?>">
                                        <input type="radio" name="swap_shift" value="<?= (int) $w['shift_id'] ?>" id="swap-form-field-swap_shift-<?= (int) $w['shift_id'] ?>" required>
                                        <span><?= e(shift_when($w['starts_at'], $w['ends_at'], $w['timezone'], $zone)) ?> <span class="text-muted">· <?= e($w['position_name']) ?></span></span>
                                    </label>
                                <?php endforeach; ?>
                                <label class="form-label fs-12 text-muted" for="swap-form-field-note">Note (optional)</label>
                                <input type="text" name="note" id="swap-form-field-note" class="form-control btn-touch mb-2" maxlength="500">
                                <button type="submit" class="btn btn-primary btn-touch w-100" id="swap-form-save-btn">Ask for the swap</button>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    </div>
                </details>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$isHolder && $live !== null && $live['status'] === 'open' && in_array($live['kind'], ['offer', 'open'], true)): ?>
        <div class="card mb-3" id="shift-take"><div class="card-body">This shift is up for grabs. <?= hx_link('/marketplace#exchange-card-' . (int) $live['exchange_id'], 'See it on the marketplace', 'fw-semibold', 'id="shift-take-link"') ?></div></div>
    <?php endif; ?>

    <?php if ($manage !== null && $s['status'] === 'scheduled'): $mLive = $manage['live']; ?>
    <div class="card mb-3" id="shift-manage">
        <div class="card-header"><h5 class="card-title mb-0">Manage this shift</h5></div>
        <div class="card-body">
            <?php if ($mLive): ?><div class="alert alert-warning fs-12" id="shift-manage-live" role="note"><i class="feather-radio me-1"></i>This is live: staff will be told.</div>
            <?php else: ?><div class="fs-12 text-muted mb-2" id="shift-manage-draft">This week is a draft — staff cannot see this shift yet.</div><?php endif; ?>
            <?= hx_link(with_back('/shifts/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>' . ($mLive ? 'Change' : 'Edit'), 'btn btn-primary btn-touch w-100 mb-2', 'id="shift-edit-link"') ?>
            <?= hx_link($manage['builder'], '<i class="feather-grid me-1"></i>Open the week in the builder', 'btn btn-light btn-touch w-100 mb-2', 'id="shift-builder-link"') ?>
            <?php if (!$manage['over']) { echo view('builder/partials/move-form.php', ['s' => $s, 'days' => $manage['days'], 'people' => $manage['people'], 'live' => $mLive, 'idp' => 'shift']); } ?>
            <?php if ($mLive && !$manage['over']): ?>
                <form method="post" action="/shifts/cancel.php" hx-post="/shifts/cancel.php" hx-target="#flash" hx-confirm="This is live: staff will be told. Cancel this shift?" class="mt-3" id="shift-cancel-form">
                    <?= csrf_field() ?><input type="hidden" name="shift" value="<?= $id ?>">
                    <label class="form-label fs-12 text-muted" for="shift-form-field-cancel-reason">Why is it cancelled?</label>
                    <input type="text" name="reason" id="shift-form-field-cancel-reason" class="form-control btn-touch mb-2" maxlength="500" required>
                    <button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="shift-cancel-btn"><i class="feather-x-circle me-1"></i>Cancel shift</button>
                </form>
            <?php elseif (!$mLive): ?>
                <form method="post" action="/shifts/delete.php" hx-post="/shifts/delete.php" hx-target="#flash" hx-confirm="Delete this draft shift?" class="mt-3" id="shift-delete-form">
                    <?= csrf_field() ?><input type="hidden" name="shift" value="<?= $id ?>">
                    <button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="shift-delete-btn"><i class="feather-trash-2 me-1"></i>Delete this draft shift</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif ($manage !== null && $s['status'] === 'cancelled'): ?>
    <div class="card mb-3" id="shift-cancelled-card"><div class="card-body fs-12 text-muted">Cancelled<?= ($s['cancel_reason'] ?? '') !== '' ? ': ' . e($s['cancel_reason']) : '' ?>. A cancelled shift is kept in the history, never deleted.</div></div>
    <?php endif; ?>

    <?php if ($canCover && $liveShift && $live === null): ?>
        <div class="mb-3"><?= hx_link(with_back('/coverage?shift=' . $id, $here), '<i class="feather-life-buoy me-1"></i>Find cover', 'btn btn-light btn-touch w-100', 'id="shift-find-cover"') ?></div>
    <?php endif; ?>

    <div class="card" id="shift-history">
        <div class="card-header"><h5 class="card-title mb-0">History</h5></div>
        <ul class="list-group list-group-flush" id="shift-history-list">
            <?php if ($history === []): ?><li class="list-group-item text-muted" id="shift-history-empty">Nothing has happened to this shift yet.</li><?php endif; ?>
            <?php foreach ($history as $h): ?>
                <li class="list-group-item" id="shift-history-<?= (int) $h['activity_id'] ?>">
                    <div><?= e(activity_words($h)) ?></div>
                    <div class="fs-11 text-muted"><?= e(format_ts($h['occurred_at'], $s['timezone'], 'D M j, g:i a')) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
