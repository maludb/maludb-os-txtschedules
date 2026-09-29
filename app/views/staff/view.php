<?php
/** One person (screen `staff-view`). Data: p, self, me, positions, cards, kinds, hours, balances, managed, mayManage, notes, editCert, addKind, back, notice, theirs */
$id = (int) $p['member_id'];
$here = '/staff/' . $id;
?>
<?= view('shared/header.php', ['id' => 'staff-view', 'title' => $p['display_name'], 'back' => $back, 'crumbs' => $mayManage ? [['Home', '/'], ['Staff', '/staff/'], [$p['display_name'], null]] : [['Home', '/'], [$p['display_name'], null]]]) ?>
<div class="main-content" id="staff-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="staff-view-summary"><div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
                <div class="fw-bold fs-5" id="staff-view-name"><?= e($p['display_name']) ?></div>
                <div class="text-muted fs-12" id="staff-view-main">Main restaurant: <?= e($p['main_site_name'] ?? ($p['main_site_id'] === null ? 'not set' : 'another restaurant')) ?><?= $p['main_site_id'] !== null ? ' — they can pick up shifts only here' : '' ?></div>
            </div>
            <div class="text-end">
                <?php if (!$p['on_schedule']): ?><span class="badge bg-soft-secondary text-secondary" id="staff-view-off">Off the schedule</span><?php endif; ?>
                <?php if ($p['is_minor']): ?><span class="badge bg-soft-warning text-warning" id="staff-view-minor">Minor<?= $p['minor_until'] !== null ? ' until ' . e(format_date($p['minor_until'])) : '' ?></span><?php endif; ?>
            </div>
        </div>
        <div class="fs-12 text-muted mt-2" id="staff-view-limit"><?= $p['max_hours_week'] !== null ? 'Up to ' . e(hours_label($p['max_hours_week'])) . ' a week' : 'No weekly hours limit' ?></div>
        <?php if ($notes !== null && $notes !== ''): ?><div class="mt-2 border-top pt-2" id="staff-view-notes"><div class="fs-11 text-muted text-uppercase">Manager's notes</div><?= nl2br(e($notes)) ?></div><?php endif; ?>
        <?php if ($mayManage): ?><div class="mt-3"><?= hx_link($here . '/edit', '<i class="feather-edit-2 me-1"></i>Change the profile', 'btn btn-primary btn-touch w-100', 'id="staff-view-edit-btn"') ?></div><?php endif; ?>
    </div></div>

    <?= view('staff/partials/positions-block.php', ['p' => $p, 'positions' => $positions, 'self' => $self, 'back' => $here]) ?>
    <?= view('staff/certifications.php', ['p' => $p, 'self' => $self, 'me' => $me, 'cards' => $cards, 'kinds' => $kinds, 'mayManage' => $mayManage, 'editCert' => $editCert, 'addKind' => $addKind, 'multi' => count($theirs) > 1, 'here' => $here]) ?>

    <h6 class="text-muted text-uppercase fs-11">Hours this week</h6>
    <div class="card mb-3" id="staff-view-hours"><ul class="list-group list-group-flush">
        <?php if ($hours === []): ?><li class="list-group-item text-muted">Nothing to show.</li><?php endif; ?>
        <?php foreach ($hours as $h): ?>
            <li class="list-group-item" id="staff-view-hours-<?= (int) $h['site_id'] ?>">
                <div class="fw-semibold"><?= e($h['site_name']) ?> · week of <?= e(format_date($h['week_start'])) ?></div>
                <div class="<?= $h['over_own_limit'] ? 'text-danger' : 'text-muted' ?>"><?= e(hours_label($h['scheduled_hours'])) ?> scheduled<?= $h['max_hours_week'] !== null ? ' of ' . e(hours_label($h['max_hours_week'])) : ' · no limit' ?><?= $h['over_own_limit'] ? ' — over their limit' : '' ?></div>
            </li>
        <?php endforeach; ?>
    </ul></div>

    <?php if ($balances !== []): ?>
        <h6 class="text-muted text-uppercase fs-11">Time off</h6>
        <div class="card mb-3" id="staff-view-time-off"><ul class="list-group list-group-flush">
            <?php foreach ($balances as $b): ?><li class="list-group-item d-flex justify-content-between" id="staff-view-balance-<?= (int) $b['type_id'] ?>"><span><?= e($b['name']) ?><?= count($theirs) > 1 ? ' · ' . e($b['site_name']) : '' ?></span><span class="fw-semibold"><?= e(hours_label($b['balance_hours'])) ?></span></li><?php endforeach; ?>
            <li class="list-group-item"><?= hx_link(with_back('/time-off/balances?member=' . $id, $here), 'Balances and the ledger', 'fw-semibold', 'id="staff-view-balances-link"') ?></li>
        </ul></div>
    <?php endif; ?>

    <h6 class="text-muted text-uppercase fs-11">Restaurants they work at</h6>
    <div class="card mb-3" id="staff-view-restaurants"><ul class="list-group list-group-flush">
        <?php foreach ($p['restaurants'] as $r): ?><li class="list-group-item d-flex justify-content-between" id="staff-view-restaurant-<?= (int) $r['site_id'] ?>"><span><?= e($r['site_name']) ?></span><span class="text-muted"><?= e($r['role_name']) ?></span></li><?php endforeach; ?>
        <li class="list-group-item fs-12 text-muted">Who may sign in where is granted in the kernel.</li>
    </ul></div>
</div>
