<?php
/** Approvals (screen `approvals`). Data: rows (exchanges, oldest first), timeOff, avail, kind, zone, notice, sites. Each: the request in one card, its warnings or its covered shifts, Approve / Decline inline. */
$kinds = ['' => 'All', 'time_off' => 'Time off', 'availability' => 'Availability', 'exchange' => 'Trades'];
?>
<?= view('shared/header.php', ['id' => 'approvals', 'title' => 'Approvals', 'crumbs' => [['Home', '/'], ['Approvals', null]]]) ?>
<div class="main-content" id="approvals-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="approvals-kind-chips">
        <?php foreach ($kinds as $k => $l): ?><?= hx_link('/approvals' . ($k === '' ? '' : '?kind=' . $k), e($l), 'btn btn-touch ' . (($kind ?? '') === $k ? 'btn-primary' : 'btn-light'), 'id="approvals-kind-' . ($k === '' ? 'all' : e($k)) . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($timeOff !== []): ?><h6 class="text-muted text-uppercase fs-11" id="approvals-time-off-heading">Time off</h6><?php endif; ?>
    <?php foreach ($timeOff as $t) { echo view('timeoff/partials/request-card.php', ['r' => $t, 'mode' => 'approval', 'back' => '/approvals', 'zone' => $zone, 'withBalance' => true, 'shifts' => $t['covered'], 'showMember' => true]); } ?>
    <?php if ($avail !== []): ?>
        <h6 class="text-muted text-uppercase fs-11" id="approvals-availability-heading">Availability changes</h6>
        <div class="card mb-3" id="approvals-availability"><div class="card-body pt-2">
            <?php foreach ($avail as $b) { echo view('availability/partials/block.php', ['b' => $b, 'mode' => 'approval', 'may' => ['decide' => true], 'back' => '/approvals', 'showMember' => true, 'multi' => true]); } ?>
        </div></div>
    <?php endif; ?>
    <?php if ($rows !== []): ?><h6 class="text-muted text-uppercase fs-11" id="approvals-trades-heading">Trades</h6><?php endif; ?>
    <?php if ($rows === [] && $timeOff === [] && $avail === []): ?>
        <div class="card" id="approvals-empty"><div class="card-body text-center py-4">
            <div class="avatar-text avatar-xl rounded mx-auto mb-3"><i class="feather-check-circle"></i></div>
            <div class="fw-semibold">Nothing waits for you.</div>
            <div class="text-muted fs-12 mt-1">Time off, availability changes and trades that need a manager appear here.</div>
        </div></div>
    <?php endif; ?>
    <?php foreach ($rows as $x): $id = (int) $x['exchange_id']; $choose = $x['status'] === 'open'; $soft = array_values(array_filter($x['warnings'], static fn (array $w): bool => ($w['severity'] ?? 'soft') === 'soft')); ?>
        <div class="card mb-3 approval-card" id="approval-card-<?= $id ?>">
            <div class="card-body p-3">
                <div class="d-flex gap-3 align-items-stretch">
                    <?= pos_swatch($x['position_color']) ?>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold" id="approval-card-<?= $id ?>-line"><i class="<?= e(EXCHANGE_KIND_ICON[$x['kind']] ?? 'feather-repeat') ?> me-1"></i><?= hx_link(with_back('/exchanges/' . $id, '/approvals'), e(exchange_headline($x)), 'text-dark') ?></div>
                        <div class="fs-12 text-muted"><?= e($x['site_name']) ?> · asked <?= e(format_ts($x['created_at'], $x['timezone'], 'D M j, g:i a')) ?></div>
                        <?php if ($soft !== []): ?><div class="mt-2" id="approval-card-<?= $id ?>-warnings"><?php foreach ($soft as $i => $w): ?><span class="badge bg-soft-warning text-warning me-1 mb-1 text-wrap text-start"><i class="feather-alert-triangle me-1"></i><?= e($w['message'] ?? '') ?></span><?php endforeach; ?></div><?php endif; ?>
                    </div>
                </div>
                <?php if ($choose): ?>
                    <div class="mt-3" id="approval-card-<?= $id ?>-claimants">
                        <?php foreach ($x['claimants'] as $c): ?>
                            <form method="post" action="/exchanges/choose.php" hx-post="/exchanges/choose.php" hx-target="#flash" class="mb-2"><?= csrf_field() ?><input type="hidden" name="exchange" value="<?= $id ?>"><input type="hidden" name="member" value="<?= (int) $c['member_id'] ?>"><input type="hidden" name="return_to" value="/approvals">
                                <?php foreach (($c['warnings'] ?? []) as $w): ?><div class="fs-12 text-warning mb-1"><?= e($c['name']) ?>: <?= e($w['message'] ?? '') ?></div><?php endforeach; ?>
                                <button type="submit" class="btn btn-primary btn-touch w-100" id="approval-card-<?= $id ?>-choose-<?= (int) $c['member_id'] ?>-btn">Give it to <?= e($c['name']) ?></button></form>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <form method="post" action="/exchanges/approve.php" class="mt-3" id="approval-form-<?= $id ?>">
                        <?= csrf_field() ?><input type="hidden" name="exchange" value="<?= $id ?>"><input type="hidden" name="return_to" value="/approvals">
                        <input type="text" name="note" id="approval-form-<?= $id ?>-field-note" class="form-control btn-touch mb-2" maxlength="500" placeholder="Note (optional)" aria-label="Note">
                        <div class="row g-2">
                            <div class="col-6"><button type="submit" class="btn btn-primary btn-touch w-100" id="approval-card-<?= $id ?>-approve-btn" hx-post="/exchanges/approve.php" hx-target="#flash">Approve</button></div>
                            <div class="col-6"><button type="submit" class="btn btn-light btn-touch w-100" id="approval-card-<?= $id ?>-decline-btn" formaction="/exchanges/decline.php" hx-post="/exchanges/decline.php" hx-target="#flash">Decline</button></div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
