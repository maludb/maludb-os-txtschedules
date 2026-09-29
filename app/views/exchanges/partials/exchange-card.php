<?php
/**
 * One trade as a card (the RecordCard pattern). Data: x (an exchange row), mode = grabs | forme | claims | summary, check (marketplace_check() for grabs/forme), zone, back
 * grabs: one button — Take it — or a muted line saying why not, in the database's own words. forme: Accept / Refuse. claims: the claim's state, Withdraw. summary: state only.
 */
$id = (int) $x['exchange_id'];
$tz = (string) $x['timezone'];
[$bl, $bc] = exchange_status_badge($x);
$check = $check ?? ['reason' => null, 'warnings' => []];
$back = $back ?? '/marketplace';
$who = match (true) {
    $x['kind'] === 'offer' => 'Offered by ' . ($x['from_name'] ?? 'someone'),
    $x['kind'] === 'open' => 'Open shift',
    $x['kind'] === 'give' => ($x['from_name'] ?? 'Someone') . ' would like to give you this shift',
    $x['kind'] === 'swap' => ($x['from_name'] ?? 'Someone') . ' asks you to swap',
    default => $x['site_name'] . ' needs cover',
};
$rule = $x['kind'] === 'give' ? $x['approval_give'] : ($x['kind'] === 'swap' ? $x['approval_swap'] : ($x['kind'] === 'coverage' ? 'never' : $x['approval_pickup']));
$soft = array_values(array_filter($check['warnings'], static fn (array $w): bool => ($w['severity'] ?? 'soft') === 'soft'));
$goesToManager = $rule === 'always' || ($rule === 'on_warning' && $soft !== []);
?>
<div class="card exchange-card mb-3" id="exchange-card-<?= $id ?>">
    <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
            <div class="fs-12 text-muted"><i class="<?= e(EXCHANGE_KIND_ICON[$x['kind']] ?? 'feather-repeat') ?> me-1"></i><span id="exchange-card-<?= $id ?>-who"><?= e($who) ?></span></div>
            <?php if ($mode === 'claims' && isset($x['claim_status'])): [$cl, $cc] = claim_status_badge((string) $x['claim_status']); ?>
                <span class="badge bg-soft-<?= e($cc) ?> text-<?= e($cc) ?> text-nowrap" id="exchange-card-<?= $id ?>-claim"><?= e($cl) ?></span>
            <?php elseif ($mode === 'summary'): ?>
                <span class="badge bg-soft-<?= e($bc) ?> text-<?= e($bc) ?> text-nowrap" id="exchange-card-<?= $id ?>-badge"><?= e($bl) ?></span>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-3 align-items-stretch">
            <?= pos_swatch($x['position_color']) ?>
            <div class="flex-grow-1 min-w-0">
                <div class="fw-bold" id="exchange-card-<?= $id ?>-when"><?= hx_link(with_back('/exchanges/' . $id, $back), e(shift_when($x['shift_starts_at'], $x['shift_ends_at'], $tz, $zone)), 'text-dark') ?></div>
                <div class="text-muted fs-12"><?= e($x['position_name'] ?? '') ?> · <?= e($x['site_name']) ?></div>
                <?php if ($x['kind'] === 'swap' && $x['swap_starts_at'] !== null): ?>
                    <div class="fs-12 mt-1" id="exchange-card-<?= $id ?>-swap"><i class="feather-repeat me-1"></i>You would give them <?= e(shift_when($x['swap_starts_at'], $x['swap_ends_at'], $tz, $zone)) ?></div>
                <?php endif; ?>
                <?php if (($x['note'] ?? '') !== ''): ?><div class="mt-1" id="exchange-card-<?= $id ?>-note">&ldquo;<?= e($x['note']) ?>&rdquo;</div><?php endif; ?>
                <?php if (in_array($x['status'], ['open', 'pending_acceptance'], true)): ?>
                    <div class="fs-12 text-muted mt-1" id="exchange-card-<?= $id ?>-closes">Closes <?= e(format_ts($x['expires_at'], $tz, 'D g:i a')) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($mode === 'grabs' || ($mode === 'forme' && $x['kind'] === 'coverage')): ?>
            <div class="mt-3" id="exchange-card-<?= $id ?>-action">
                <?php if ($check['reason'] !== null): ?>
                    <button type="button" class="btn btn-light btn-touch w-100" disabled id="exchange-card-<?= $id ?>-take-btn">Take it</button>
                    <div class="text-muted fs-12 mt-2" id="exchange-card-<?= $id ?>-why"><i class="feather-slash me-1"></i><?= e($check['reason']) ?></div>
                <?php else: ?>
                    <?php if ($goesToManager): ?>
                        <div class="alert alert-warning fs-12 py-2 mb-2" id="exchange-card-<?= $id ?>-warning"><i class="feather-alert-triangle me-1"></i>A manager will look at this one<?= $soft !== [] ? ': ' . e(implode(' ', array_map(static fn (array $w): string => (string) $w['message'], $soft))) : '.' ?></div>
                    <?php endif; ?>
                    <?php if ($x['claim_mode'] === 'manager_chooses' && $x['kind'] !== 'coverage'): ?><div class="text-muted fs-12 mb-2">A manager chooses among the people who ask.</div><?php endif; ?>
                    <form method="post" action="/exchanges/claim.php" hx-post="/exchanges/claim.php" hx-target="#flash">
                        <?= csrf_field() ?><input type="hidden" name="exchange" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                        <button type="submit" class="btn btn-primary btn-touch w-100" id="exchange-card-<?= $id ?>-take-btn">Take it</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php elseif ($mode === 'forme'): ?>
            <div class="mt-3" id="exchange-card-<?= $id ?>-action">
                <?php if ($check['reason'] !== null): ?><div class="text-muted fs-12 mb-2" id="exchange-card-<?= $id ?>-why"><i class="feather-slash me-1"></i><?= e($check['reason']) ?></div><?php endif; ?>
                <?php if ($goesToManager && $check['reason'] === null): ?><div class="alert alert-warning fs-12 py-2 mb-2" id="exchange-card-<?= $id ?>-warning"><i class="feather-alert-triangle me-1"></i>A manager will look at this one<?= $soft !== [] ? ': ' . e(implode(' ', array_map(static fn (array $w): string => (string) $w['message'], $soft))) : '.' ?></div><?php endif; ?>
                <div class="row g-2">
                    <div class="col-6"><form method="post" action="/exchanges/accept.php" hx-post="/exchanges/accept.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="exchange" value="<?= $id ?>">
                        <button type="submit" class="btn btn-primary btn-touch w-100" id="exchange-card-<?= $id ?>-accept-btn" <?= $check['reason'] !== null ? 'disabled' : '' ?>>Accept</button></form></div>
                    <div class="col-6"><form method="post" action="/exchanges/refuse.php" hx-post="/exchanges/refuse.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="exchange" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($back) ?>">
                        <button type="submit" class="btn btn-light btn-touch w-100" id="exchange-card-<?= $id ?>-refuse-btn">Refuse</button></form></div>
                </div>
            </div>
        <?php elseif ($mode === 'claims'): ?>
            <div class="mt-3 fs-12 text-muted" id="exchange-card-<?= $id ?>-state"><?= e($bl) ?></div>
            <?php if (($x['claim_status'] ?? '') === 'pending' && $x['status'] === 'open'): ?>
                <form method="post" action="/exchanges/withdraw.php" hx-post="/exchanges/withdraw.php" hx-target="#flash" class="mt-2"><?= csrf_field() ?><input type="hidden" name="exchange" value="<?= $id ?>">
                    <button type="submit" class="btn btn-light btn-touch w-100" id="exchange-card-<?= $id ?>-withdraw-btn">Withdraw my claim</button></form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
