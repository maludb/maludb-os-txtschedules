<?php
/** The form's preview line. Data: p = time_off_preview(), type */
?>
<div id="time-off-form-preview-line">
<?php if ($p['error'] !== null): ?>
    <div class="alert alert-danger fs-12 py-2 mb-0" id="time-off-form-preview-error"><i class="feather-slash me-1"></i><?= e($p['error']) ?></div>
<?php else: ?>
    <div class="fs-12" id="time-off-form-preview-hours"><i class="feather-clock me-1"></i>This uses <?= e(hours_label($p['hours'])) ?>.</div>
    <?php if ($p['balance'] !== null): ?>
        <div class="fs-12 <?= $p['over'] ? 'text-warning' : 'text-muted' ?>" id="time-off-form-preview-balance">Balance <?= e(hours_label($p['balance'])) ?> — this uses <?= e(hours_label($p['hours'])) ?>.<?= $p['over'] ? ' That is more than is left: you can still ask, but it cannot be approved unless the kind may go below zero.' : '' ?></div>
    <?php endif; ?>
<?php endif; ?>
</div>
