<?php
/**
 * Exchange view (screen `exchange-view`). Data: x, claims (deciders only), canDecide, decideWhy, live, mine, toMe, check, myClaim, history, me, back, notice, zone, canCancel
 */
$id = (int) $x['exchange_id'];
$tz = (string) $x['timezone'];
$here = '/exchanges/' . $id;
[$bl, $bc] = exchange_status_badge($x);
$soft = array_values(array_filter($x['warnings'], static fn (array $w): bool => ($w['severity'] ?? 'soft') === 'soft'));
$isChoose = $live && $x['status'] === 'open' && $x['claim_mode'] === 'manager_chooses' && $canDecide;
?>
<?= view('shared/header.php', ['id' => 'exchange', 'title' => 'Trade', 'crumbs' => [['Home', '/'], ['Marketplace', '/marketplace'], [EXCHANGE_KIND_WORD[$x['kind']] ?? 'Trade', null]], 'back' => $back]) ?>
<div class="main-content" id="exchange-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="exchange-card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div class="fw-semibold" id="exchange-headline"><i class="<?= e(EXCHANGE_KIND_ICON[$x['kind']] ?? 'feather-repeat') ?> me-1"></i><?= e(exchange_headline($x)) ?></div>
                <span class="badge bg-soft-<?= e($bc) ?> text-<?= e($bc) ?> text-nowrap" id="exchange-status"><?= e($bl) ?></span>
            </div>
            <div class="d-flex gap-3 align-items-stretch">
                <?= pos_swatch($x['position_color']) ?>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-bold"><?= hx_link('/shifts/' . (int) $x['shift_id'] . '?back=' . rawurlencode($here), e(shift_when($x['shift_starts_at'], $x['shift_ends_at'], $tz, $zone)), 'text-dark', 'id="exchange-shift-link"') ?></div>
                    <div class="text-muted fs-12"><?= e($x['position_name'] ?? '') ?> · <?= e($x['site_name']) ?><?= $x['holder_name'] !== null ? ' · held by ' . e($x['holder_name']) : '' ?></div>
                    <?php if ($x['swap_starts_at'] !== null): ?><div class="mt-2" id="exchange-swap"><i class="feather-repeat me-1"></i>Swap for <?= hx_link('/shifts/' . (int) $x['swap_shift_id'] . '?back=' . rawurlencode($here), e(shift_when($x['swap_starts_at'], $x['swap_ends_at'], $tz, $zone)), 'fw-semibold') ?> <span class="text-muted">· <?= e($x['swap_position_name']) ?></span></div><?php endif; ?>
                    <dl class="row fs-12 mb-0 mt-2">
                        <?php if ($x['from_name'] !== null): ?><dt class="col-4 text-muted">From</dt><dd class="col-8" id="exchange-from"><?= e($x['from_name']) ?></dd><?php endif; ?>
                        <?php if ($x['to_name'] !== null): ?><dt class="col-4 text-muted"><?= $x['status'] === 'approved' ? 'To' : 'To (asked)' ?></dt><dd class="col-8" id="exchange-to"><?= e($x['to_name']) ?></dd><?php endif; ?>
                        <dt class="col-4 text-muted">Kind</dt><dd class="col-8"><?= e(EXCHANGE_KIND_WORD[$x['kind']] ?? $x['kind']) ?></dd>
                        <?php if ($live): ?><dt class="col-4 text-muted">Closes</dt><dd class="col-8" id="exchange-closes"><?= e(format_ts($x['expires_at'], $tz, 'D M j, g:i a')) ?></dd><?php endif; ?>
                        <?php if (($x['note'] ?? '') !== ''): ?><dt class="col-4 text-muted">Note</dt><dd class="col-8" id="exchange-note"><?= e($x['note']) ?></dd><?php endif; ?>
                        <?php if (($x['decision_note'] ?? '') !== ''): ?><dt class="col-4 text-muted">Decision</dt><dd class="col-8" id="exchange-decision-note"><?= e($x['decision_note']) ?></dd><?php endif; ?>
                    </dl>
                </div>
            </div>
            <?php if ($soft !== []): ?>
                <div class="mt-3" id="exchange-warnings">
                    <?php foreach ($soft as $i => $w): ?><span class="badge bg-soft-warning text-warning me-1 mb-1 text-wrap text-start" id="exchange-warning-<?= (int) $i ?>"><i class="feather-alert-triangle me-1"></i><?= e($w['message'] ?? '') ?></span><?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($check !== null): ?>
        <?= view('exchanges/partials/exchange-card.php', ['x' => $x, 'mode' => ($toMe && $x['status'] === 'pending_acceptance') ? 'forme' : 'grabs', 'check' => $check, 'zone' => $zone, 'back' => $here]) ?>
    <?php endif; ?>

    <?php if ($x['status'] === 'pending_approval' && $canDecide): ?>
    <div class="card mb-3" id="exchange-decide">
        <div class="card-header"><h5 class="card-title mb-0">Decide</h5></div>
        <div class="card-body">
            <form method="post" id="exchange-decide-form" action="/exchanges/approve.php">
                <?= csrf_field() ?><input type="hidden" name="exchange" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <label class="form-label fs-12 text-muted" for="exchange-form-field-note">Note (optional)</label>
                <input type="text" name="note" id="exchange-form-field-note" class="form-control btn-touch mb-3" maxlength="500">
                <div class="row g-2">
                    <div class="col-6"><button type="submit" class="btn btn-primary btn-touch w-100" id="exchange-approve-btn" hx-post="/exchanges/approve.php" hx-target="#flash">Approve</button></div>
                    <div class="col-6"><button type="submit" class="btn btn-light btn-touch w-100" id="exchange-decline-btn" formaction="/exchanges/decline.php" hx-post="/exchanges/decline.php" hx-target="#flash">Decline</button></div>
                </div>
            </form>
        </div>
    </div>
    <?php elseif ($x['status'] === 'pending_approval'): ?>
        <div class="card mb-3" id="exchange-waiting"><div class="card-body text-muted">Waiting for a manager to decide.</div></div>
    <?php endif; ?>

    <?php if ($isChoose): ?>
    <div class="card mb-3" id="exchange-choose">
        <div class="card-header"><h5 class="card-title mb-0">Who gets it?</h5></div>
        <ul class="list-group list-group-flush">
            <?php foreach (($claims ?? []) as $c): if ($c['status'] !== 'pending') { continue; } ?>
                <li class="list-group-item" id="exchange-claim-<?= (int) $c['claim_id'] ?>">
                    <div class="fw-semibold mb-1"><?= e($c['member_name']) ?></div>
                    <?php foreach ($c['warnings'] as $w): ?><span class="badge bg-soft-warning text-warning me-1 mb-1"><?= e($w['message'] ?? '') ?></span><?php endforeach; ?>
                    <form method="post" action="/exchanges/choose.php" hx-post="/exchanges/choose.php" hx-target="#flash" class="mt-2"><?= csrf_field() ?><input type="hidden" name="exchange" value="<?= $id ?>"><input type="hidden" name="member" value="<?= (int) $c['member_id'] ?>">
                        <button type="submit" class="btn btn-primary btn-touch w-100" id="exchange-choose-<?= (int) $c['member_id'] ?>-btn">Give it to <?= e($c['member_name']) ?></button></form>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php elseif ($claims !== null && $claims !== []): ?>
    <div class="card mb-3" id="exchange-claims">
        <div class="card-header"><h5 class="card-title mb-0">Claims</h5></div>
        <ul class="list-group list-group-flush">
            <?php foreach ($claims as $c): [$cl, $cc] = claim_status_badge($c['status']); ?>
                <li class="list-group-item d-flex justify-content-between" id="exchange-claim-<?= (int) $c['claim_id'] ?>"><span><?= e($c['member_name']) ?></span><span class="badge bg-soft-<?= e($cc) ?> text-<?= e($cc) ?>"><?= e($cl) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php elseif ($live && $x['claims'] > 0): ?>
        <div class="card mb-3" id="exchange-claim-count"><div class="card-body text-muted"><?= (int) $x['claims'] ?> <?= $x['claims'] === 1 ? 'person has' : 'people have' ?> asked for it.</div></div>
    <?php endif; ?>

    <?php if ($myClaim !== null && $myClaim['status'] === 'pending' && $x['status'] === 'open'): ?>
        <form method="post" action="/exchanges/withdraw.php" hx-post="/exchanges/withdraw.php" hx-target="#flash" class="mb-3" id="exchange-withdraw-claim-form"><?= csrf_field() ?><input type="hidden" name="exchange" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <button type="submit" class="btn btn-light btn-touch w-100" id="exchange-withdraw-claim-btn">Withdraw my claim</button></form>
    <?php endif; ?>

    <?php if ($canCancel): ?>
        <form method="post" action="/exchanges/cancel.php" hx-post="/exchanges/cancel.php" hx-target="#flash" hx-confirm="Withdraw this trade? The shift stays with its holder." class="mb-3" id="exchange-cancel-form">
            <?= csrf_field() ?><input type="hidden" name="exchange" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <button type="submit" class="btn btn-light btn-touch w-100" id="exchange-cancel-btn"><i class="feather-x me-1"></i>Withdraw</button>
        </form>
    <?php endif; ?>

    <div class="card" id="exchange-history">
        <div class="card-header"><h5 class="card-title mb-0">History</h5></div>
        <ul class="list-group list-group-flush">
            <?php if ($history === []): ?><li class="list-group-item text-muted" id="exchange-history-empty">Nothing you may see yet.</li><?php endif; ?>
            <?php foreach ($history as $h): ?>
                <li class="list-group-item" id="exchange-history-<?= (int) $h['activity_id'] ?>"><div><?= e(activity_words($h)) ?></div><div class="fs-11 text-muted"><?= e(format_ts($h['occurred_at'], $tz, 'D M j, g:i a')) ?></div></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
