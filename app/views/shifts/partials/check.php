<?php
/**
 * The INSIDE of the live check region of the shift form (the wrapper #shift-form-check lives in the form and stays put, so a change made while an answer is on its way is not lost): what the rules say about this person and these times. Data: check (shift_form_findings()), oob (true when answering an HTMX
 * change: the Save button is re-sent so a hard rule disables it), live (the week is published), reason (the override_reason typed so far).
 * A hard sentence blocks Save; a soft one asks for the reason; nothing wrong says so.
 */
$reason = $reason ?? '';
$hard = [];
$soft = [];
if (!isset($check['error'])) {
    $hard = $check['findings']['hard'];
    $soft = $check['findings']['soft'];
}
?>
<div id="shift-form-check-state" data-hard="<?= $hard === [] ? '0' : '1' ?>" data-soft="<?= $soft === [] ? '0' : '1' ?>">
    <?php if (isset($check['error'])): ?>
        <div class="fs-12 text-muted mb-2" id="shift-form-check-pending"><?= e($check['error']) ?></div>
    <?php elseif ($check['assignee'] === null): ?>
        <div class="fs-12 text-muted mb-2" id="shift-form-check-open">Left open: nobody to check yet.</div>
    <?php elseif ($hard === [] && $soft === []): ?>
        <div class="fs-12 text-success mb-2" id="shift-form-check-clear"><i class="feather-check-circle me-1"></i>No rule is broken.</div>
    <?php endif; ?>
    <?php foreach ($hard as $i => $msg): ?>
        <div class="alert alert-danger py-2 fs-12 mb-2" role="alert" id="shift-form-check-hard-<?= (int) $i ?>"><i class="feather-slash me-1"></i><?= e($msg) ?> <strong>This cannot be saved.</strong></div>
    <?php endforeach; ?>
    <?php foreach ($soft as $i => $w): ?>
        <div class="alert alert-warning py-2 fs-12 mb-2" role="alert" id="shift-form-check-soft-<?= (int) $i ?>"><i class="feather-alert-triangle me-1"></i><?= e($w['member_name'] . ': ' . $w['message']) ?></div>
    <?php endforeach; ?>
    <?php if ($soft !== []): ?>
        <label class="form-label fs-12 text-muted" for="shift-form-field-override-reason">Why go ahead? (recorded with each warning)</label>
        <textarea name="override_reason" id="shift-form-field-override-reason" class="form-control mb-2" rows="2" maxlength="500" required><?= e($reason) ?></textarea>
    <?php endif; ?>
    <?php if ($oob ?? false): ?>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="shift-form-save-btn" hx-swap-oob="true"<?= $hard === [] ? '' : ' disabled' ?>><?= e($saveLabel ?? 'Save the shift') ?></button>
    <?php endif; ?>
</div>
