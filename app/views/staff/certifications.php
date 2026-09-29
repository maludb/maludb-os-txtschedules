<?php
/**
 * A person's certifications (staff-view). Data: p, self, me, cards, kinds, mayManage, editCert, addKind, multi, here.
 * A card: expired in danger, inside the kind's warning days in warning, verified or waiting; a manager verifies, adds, corrects and removes; the person corrects their own on /certifications/mine.
 */
$id = (int) $p['member_id'];
?>
<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="text-muted text-uppercase fs-11 mb-0">Certifications</h6>
    <?php if ($self && empty($mine)): ?><?= hx_link('/certifications/mine', 'My certifications', 'fs-12 fw-semibold', 'id="staff-view-my-certifications-link"') ?><?php endif; ?></div>
<div class="row g-3 mb-3" id="staff-view-certifications">
    <?php if ($cards === []): ?><div class="col-12"><div class="card"><div class="card-body text-muted" id="staff-view-certifications-empty">No certifications on file.</div></div></div><?php endif; ?>
    <?php foreach ($cards as $c): $cid = (int) $c['certification_id']; [$txt, $col] = cert_card_status($c); ?>
        <div class="col-12 col-md-6">
            <div class="card h-100" id="certification-<?= $cid ?>"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="fw-semibold" id="certification-<?= $cid ?>-kind"><?= e($c['kind_name']) ?><?= $multi ? ' <span class="text-muted fw-normal">· ' . e($c['site_name']) . '</span>' : '' ?></div>
                    <span class="badge bg-soft-<?= e($col) ?> text-<?= e($col) ?> text-nowrap" id="certification-<?= $cid ?>-status"><?= e($txt) ?></span>
                </div>
                <div class="fs-12 text-muted mt-1" id="certification-<?= $cid ?>-dates"><?= $c['issued_on'] !== null ? 'Issued ' . e(format_date($c['issued_on'])) : 'No issue date' ?><?= ($c['reference'] ?? '') !== '' ? ' · ' . e($c['reference']) : '' ?></div>
                <div class="fs-12 mt-1 <?= $c['verified'] ? 'text-success' : 'text-warning' ?>" id="certification-<?= $cid ?>-verified"><i class="feather-<?= $c['verified'] ? 'check-circle' : 'clock' ?> me-1"></i><?= e(cert_verified_words($c)) ?></div>
                <?php if ($mayManage || $self): ?>
                    <div class="row g-2 mt-2">
                        <?php if ($mayManage): ?>
                            <div class="col-6"><form method="post" action="/staff/certifications/verify.php" hx-post="/staff/certifications/verify.php" hx-target="#flash">
                                <?= csrf_field() ?><input type="hidden" name="certification" value="<?= $cid ?>"><input type="hidden" name="verified" value="<?= $c['verified'] ? 'no' : 'yes' ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                                <button type="submit" class="btn btn-<?= $c['verified'] ? 'light' : 'primary' ?> btn-touch w-100" id="certification-<?= $cid ?>-verify-btn"><?= $c['verified'] ? 'Mark unchecked' : 'Verify' ?></button></form></div>
                        <?php endif; ?>
                        <div class="col-6"><?= hx_link($here . '?cert=' . $cid . '#certification-form', 'Correct', 'btn btn-light btn-touch w-100', 'id="certification-' . $cid . '-edit-btn"') ?></div>
                        <div class="col-6"><form method="post" action="/staff/certifications/remove.php" hx-post="/staff/certifications/remove.php" hx-target="#flash" hx-confirm="Remove <?= e($c['kind_name']) ?> from <?= e($p['display_name']) ?>?">
                            <?= csrf_field() ?><input type="hidden" name="certification" value="<?= $cid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <button type="submit" class="btn btn-light btn-touch w-100" id="certification-<?= $cid ?>-remove-btn">Remove</button></form></div>
                    </div>
                <?php endif; ?>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>
<?php
$edit = null;
foreach ($cards as $c) { if ($editCert !== null && $c['certification_id'] === $editCert) { $edit = $c; } }
if ($mayManage || $self):
    $formKinds = $kinds;
?>
    <form method="post" action="<?= $edit ? '/staff/certifications/update.php' : '/staff/certifications/add.php' ?>" hx-post="<?= $edit ? '/staff/certifications/update.php' : '/staff/certifications/add.php' ?>" hx-target="#flash" class="card mb-3" id="certification-form">
        <?= csrf_field() ?><input type="hidden" name="member" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
        <?php if ($edit): ?><input type="hidden" name="certification" value="<?= (int) $edit['certification_id'] ?>"><?php endif; ?>
        <div class="card-header"><h5 class="card-title mb-0"><?= $edit ? 'Correct the ' . e($edit['kind_name']) . ' card' : 'Add a card' ?></h5></div>
        <div class="card-body">
            <?php if (!$edit): ?>
                <label class="form-label fs-12 text-muted" for="certification-form-field-kind">Certification</label>
                <select name="kind" id="certification-form-field-kind" class="form-select btn-touch mb-3" required>
                    <option value="">Choose…</option>
                    <?php foreach ($formKinds as $k): ?><option value="<?= (int) $k['kind_id'] ?>" <?= $addKind === (int) $k['kind_id'] ? 'selected' : '' ?>><?= e($k['name']) ?><?= $k['track_expiry'] ? '' : ' (does not expire)' ?></option><?php endforeach; ?>
                </select>
            <?php endif; ?>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="certification-form-field-issued">Issued</label>
                    <input type="date" name="issued_on" id="certification-form-field-issued" class="form-control btn-touch" value="<?= e($edit['issued_on'] ?? '') ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="certification-form-field-expires">Expires</label>
                    <input type="date" name="expires_on" id="certification-form-field-expires" class="form-control btn-touch" value="<?= e($edit['expires_on'] ?? '') ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="certification-form-field-reference">Card number or note</label>
            <input type="text" name="reference" id="certification-form-field-reference" class="form-control btn-touch mb-3" maxlength="120" value="<?= e($edit['reference'] ?? '') ?>">
            <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="certification-form-save-btn"><?= $edit ? 'Save the card' : 'Add the card' ?></button>
            <?php if ($edit): ?><?= hx_link($here, 'Cancel', 'btn btn-light btn-touch w-100', 'id="certification-form-cancel-link"') ?><?php endif; ?>
            <div class="fs-12 text-muted mt-2"><?= $mayManage && !$self ? 'A card a manager enters is verified at once.' : 'A manager will check the card.' ?></div>
        </div>
    </form>
<?php endif; ?>
