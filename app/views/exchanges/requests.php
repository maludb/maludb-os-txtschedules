<?php /** My requests (screen `my-requests`). Data: state, mine, claims, timeOff, availPending, availDone, zone, notice */
$states = ['waiting' => 'Waiting', 'decided' => 'Decided', 'all' => 'All'];
$here = '/requests' . ($state !== 'waiting' ? '?state=' . $state : '');
?>
<?= view('shared/header.php', ['id' => 'requests', 'title' => 'My requests', 'crumbs' => [['Home', '/'], ['My requests', null]]]) ?>
<div class="main-content" id="requests-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="btn-group w-100 mb-3" role="group" aria-label="State" id="requests-tabs">
        <?php foreach ($states as $k => $l): ?><?= hx_link('/requests?state=' . $k, e($l), 'btn btn-touch ' . ($state === $k ? 'btn-primary' : 'btn-light'), 'id="requests-tab-' . e($k) . '"') ?><?php endforeach; ?>
    </div>
    <h6 class="text-muted text-uppercase fs-11">My offers, swaps and gives</h6>
    <div id="requests-mine">
        <?php if ($mine === []): ?><div class="card mb-3" id="requests-mine-empty"><div class="card-body text-muted text-center py-3">None <?= $state === 'waiting' ? 'waiting' : 'yet' ?>.</div></div><?php endif; ?>
        <?php foreach ($mine as $x) { echo view('exchanges/partials/exchange-card.php', ['x' => $x, 'mode' => 'summary', 'zone' => $zone, 'back' => $here]); } ?>
    </div>
    <h6 class="text-muted text-uppercase fs-11 mt-3">My claims</h6>
    <div id="requests-claims">
        <?php if ($claims === []): ?><div class="card mb-3" id="requests-claims-empty"><div class="card-body text-muted text-center py-3">No claims <?= $state === 'waiting' ? 'waiting' : 'yet' ?>.</div></div><?php endif; ?>
        <?php foreach ($claims as $x) { echo view('exchanges/partials/exchange-card.php', ['x' => $x, 'mode' => 'summary', 'zone' => $zone, 'back' => $here]); } ?>
    </div>
    <h6 class="text-muted text-uppercase fs-11 mt-3">My time off</h6>
    <div id="requests-time-off">
        <?php if ($timeOff === []): ?><div class="card mb-3" id="requests-time-off-empty"><div class="card-body text-muted text-center py-3">No time off <?= $state === 'waiting' ? 'waiting' : 'yet' ?>.</div></div><?php endif; ?>
        <?php foreach ($timeOff as $t) { echo view('timeoff/partials/request-card.php', ['r' => $t, 'mode' => 'summary', 'back' => $here, 'zone' => $zone]); } ?>
    </div>
    <h6 class="text-muted text-uppercase fs-11 mt-3">My availability changes</h6>
    <div id="requests-availability">
        <?php if ($availPending === [] && $availDone === []): ?><div class="card mb-3" id="requests-availability-empty"><div class="card-body text-muted text-center py-3">No availability changes <?= $state === 'waiting' ? 'waiting' : 'yet' ?>.</div></div><?php endif; ?>
        <?php foreach (array_merge($availPending, $availDone) as $b): [$kl, $kc] = availability_kind_badge((string) $b['kind']); ?>
            <div class="card mb-2" id="requests-availability-<?= (int) $b['availability_id'] ?>"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div><span class="badge bg-soft-<?= e($kc) ?> text-<?= e($kc) ?>"><?= e($kl) ?></span> <span class="fw-semibold"><?= e(substr(WEEKDAY_NAMES[(int) $b['weekday']], 0, 3)) ?> <?= e(availability_span((string) $b['starts_at'], (string) $b['ends_at'])) ?></span>
                        <div class="fs-12 text-muted"><?= e(availability_dates($b)) ?></div></div>
                    <?php $sl = ['pending' => ['Waiting for a manager', 'warning'], 'approved' => ['Approved', 'success'], 'declined' => ['Declined', 'danger'], 'replaced' => ['No longer in effect', 'secondary']][$b['status']] ?? ['', 'secondary']; ?>
                    <span class="badge bg-soft-<?= e($sl[1]) ?> text-<?= e($sl[1]) ?> text-nowrap"><?= e($sl[0]) ?></span>
                </div>
            </div></div>
        <?php endforeach; ?>
        <div class="fs-12"><?= hx_link('/availability', 'Open my availability', 'fw-semibold', 'id="requests-availability-link"') ?></div>
    </div>
</div>
