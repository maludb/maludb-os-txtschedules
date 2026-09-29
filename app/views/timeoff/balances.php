<?php
/** Balances (screen `balances`). Data: member, me, name, balances (each with ledger, may_adjust), people, siteFilter, multi, notice */
$own = $member === $me;
$here = '/time-off/balances?member=' . $member . ($siteFilter !== null ? '&site=' . $siteFilter : '');
$reasonWords = ['grant' => 'Granted', 'adjustment' => 'Adjusted', 'request_approved' => 'Time off approved', 'request_cancelled' => 'Time off cancelled — hours returned'];
$adjustable = array_values(array_filter($balances, static fn (array $b): bool => $b['may_adjust']));
?>
<?= view('shared/header.php', ['id' => 'balances', 'title' => $own ? 'My balances' : 'Balances — ' . $name, 'crumbs' => [['Home', '/'], ['Time off', '/time-off'], [$own ? 'Balances' : $name, null]]]) ?>
<div class="main-content" id="balances-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($people !== []): ?>
        <form method="get" action="/time-off/balances" class="card mb-3" id="balances-person-form"><div class="card-body p-3">
            <label class="form-label fs-12 text-muted" for="balances-person-field-member">Whose balances</label>
            <div class="d-flex gap-2">
                <select name="member" id="balances-person-field-member" class="form-select btn-touch">
                    <option value="<?= (int) $me ?>">Mine</option>
                    <?php foreach ($people as $p): if ($p['member_id'] === $me) { continue; } ?><option value="<?= (int) $p['member_id'] ?>" <?= $p['member_id'] === $member ? 'selected' : '' ?>><?= e($p['display_name']) ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-light btn-touch" id="balances-person-btn">Show</button>
            </div>
        </div></form>
    <?php endif; ?>
    <?php if ($balances === []): ?>
        <div class="card" id="balances-empty"><div class="card-body text-center py-4">
            <div class="avatar-text avatar-xl rounded mx-auto mb-3"><i class="feather-clock"></i></div>
            <div class="fw-semibold">No balances to show.</div>
            <div class="text-muted fs-12 mt-1">A kind of time off that keeps a balance appears here once the restaurant sets one up.</div>
        </div></div>
    <?php endif; ?>
    <div class="row g-3">
    <?php foreach ($balances as $b): $tid = (int) $b['type_id']; ?>
        <div class="col-12 col-xl-6">
        <div class="card h-100" id="balance-card-<?= $tid ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div><div class="fw-semibold" id="balance-card-<?= $tid ?>-name"><?= e($b['name']) ?><?= $b['archived_at'] !== null ? ' <span class="badge bg-soft-secondary text-secondary">Archived</span>' : '' ?></div>
                        <div class="fs-12 text-muted"><?= e($b["site_name"]) ?> · <?= $b['paid'] ? 'paid' : 'unpaid' ?><?= $b['allow_negative'] ? ' · may go below zero' : '' ?></div></div>
                    <div class="fs-3 fw-bold text-nowrap <?= $b['balance_hours'] < 0 ? 'text-danger' : '' ?>" id="balance-card-<?= $tid ?>-hours"><?= e(hours_label($b['balance_hours'])) ?></div>
                </div>
                <div class="table-responsive mt-3" id="balance-card-<?= $tid ?>-ledger">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>What</th><th class="text-end">Hours</th></tr></thead>
                        <tbody>
                        <?php if ($b['ledger'] === []): ?><tr><td colspan="2" class="text-muted" id="balance-card-<?= $tid ?>-ledger-empty">Nothing recorded yet.</td></tr><?php endif; ?>
                        <?php foreach ($b['ledger'] as $l): ?>
                            <tr id="ledger-<?= (int) $l['ledger_id'] ?>">
                                <td class="text-wrap"><?= e($reasonWords[$l['reason']] ?? $l['reason']) ?><?php if ($l['request_id'] !== null): ?> <?= hx_link(with_back('/time-off/' . (int) $l['request_id'], $here), 'view', 'fs-12') ?><?php endif; ?>
                                    <div class="fs-11 text-muted"><?= e(format_ts((string) $l['created_at'], site_timezone($b['site_id']), 'M j, Y')) ?><?= $l['recorded_by_name'] !== null ? ' · ' . e($l['recorded_by_name']) : '' ?></div>
                                    <?php if (($l['note'] ?? '') !== ''): ?><div class="fs-11 text-muted"><?= e($l['note']) ?></div><?php endif; ?></td>
                                <td class="text-end text-nowrap align-top <?= $l['delta_hours'] < 0 ? 'text-danger' : 'text-success' ?>"><?= $l['delta_hours'] > 0 ? '+' : '−' ?><?= e(days_label(abs($l['delta_hours']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php if ($adjustable !== []): ?>
    <form method="post" action="/time-off/balance.php" hx-post="/time-off/balance.php" hx-target="#flash" hx-confirm="Change this balance? It goes in the ledger with your name." class="card mt-3" id="balance-form">
        <?= csrf_field() ?><input type="hidden" name="member" value="<?= $member ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
        <div class="card-header"><h5 class="card-title mb-0">Grant or remove hours</h5></div>
        <div class="card-body">
            <label class="form-label fs-12 text-muted" for="balance-form-field-type">Kind of time off</label>
            <select name="time_off_type" id="balance-form-field-type" class="form-select btn-touch mb-3">
                <?php foreach ($adjustable as $b): ?><option value="<?= (int) $b['type_id'] ?>"><?= e($b['name']) ?> · <?= e($b['site_name']) ?></option><?php endforeach; ?>
            </select>
            <label class="form-label fs-12 text-muted" for="balance-form-field-delta">Hours (positive grants, negative removes)</label>
            <input type="number" name="delta_hours" id="balance-form-field-delta" class="form-control btn-touch mb-3" step="0.25" min="-2000" max="2000" inputmode="decimal" required>
            <label class="form-label fs-12 text-muted" for="balance-form-field-reason">Reason</label>
            <input type="text" name="reason" id="balance-form-field-reason" class="form-control btn-touch mb-3" maxlength="500" required>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="balance-form-save-btn">Record it</button>
        </div>
    </form>
    <?php endif; ?>
</div>
