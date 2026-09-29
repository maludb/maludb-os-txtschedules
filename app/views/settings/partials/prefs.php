<?php
/** How I am told. Data: prefs, default, refusal, osChannels */
$lead = $prefs['reminder_minutes'];
$choices = REMINDER_CHOICES;
if ($lead !== null && !isset($choices[$lead])) { $choices[$lead] = $lead . ' minutes'; ksort($choices); }
$defaultLabel = $default === null ? '' : ' (' . ($default % 60 === 0 ? ($default / 60) . ' hour' . ($default === 60 ? '' : 's') : $default . ' minutes') . ')';
?>
<form method="post" action="/settings/prefs.php" hx-post="/settings/prefs.php" hx-target="#flash" id="prefs">
    <?= csrf_field() ?><input type="hidden" name="return_to" value="/settings/?tab=notify">
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">How you are told</h5></div><div class="card-body">
        <input type="hidden" name="by_email" value="no"><input type="hidden" name="by_sms" value="no">
        <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-field-email"><input type="checkbox" class="form-check-input mt-0" name="by_email" value="yes" id="prefs-field-email" <?= $prefs['by_email'] ? 'checked' : '' ?>><i class="feather-mail"></i> Email</label>
        <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-field-sms"><input type="checkbox" class="form-check-input mt-0" name="by_sms" value="yes" id="prefs-field-sms" <?= $prefs['by_sms'] ? 'checked' : '' ?>><i class="feather-message-square"></i> Text message</label>
        <?php if ($refusal === 'no_verified_phone'): ?>
            <div class="alert alert-warning fs-12 mb-0" id="prefs-text-note">Texts need a phone number verified in the operating system. <a href="<?= e($osChannels) ?>" class="alert-link" id="prefs-text-link">Add or verify your phone</a>. Until then you are emailed.</div>
        <?php elseif ($refusal === 'opted_out'): ?>
            <div class="alert alert-warning fs-12 mb-0" id="prefs-text-note">You turned texts off (or replied STOP). You can turn them back on in the operating system: <a href="<?= e($osChannels) ?>" class="alert-link" id="prefs-text-link">your channels</a>. Until then you are emailed.</div>
        <?php elseif ($refusal === 'no_sender'): ?>
            <div class="alert alert-secondary fs-12 mb-0" id="prefs-text-note">This business has not set up texting yet, so you are emailed.</div>
        <?php else: ?>
            <div class="fs-12 text-muted" id="prefs-text-hint">Texts go to the phone you verified in the operating system. <a href="<?= e($osChannels) ?>" id="prefs-text-link">Your channels</a></div>
        <?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Tell me when</h5></div><div class="card-body" id="prefs-kinds">
        <input type="hidden" name="kinds[]" value="">
        <?php foreach (NOTICE_KINDS as $k => $label): ?>
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="prefs-field-kind-<?= e($k) ?>"><input type="checkbox" class="form-check-input mt-0" name="kinds[]" value="<?= e($k) ?>" id="prefs-field-kind-<?= e($k) ?>" <?= in_array($k, $prefs['kinds'], true) ? 'checked' : '' ?>><?= e($label) ?></label>
        <?php endforeach; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Remind me before a shift</h5></div><div class="card-body">
        <label class="form-label fs-12 text-muted" for="prefs-field-lead">How long before</label>
        <select name="reminder_minutes" id="prefs-field-lead" class="form-select btn-touch">
            <option value="" <?= $lead === null ? 'selected' : '' ?>>Restaurant's usual<?= e($defaultLabel) ?></option>
            <?php foreach ($choices as $m => $label): ?><option value="<?= (int) $m ?>" <?= $lead === (int) $m ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <div class="fs-12 text-muted mt-1">A reminder goes by every channel you have on, once a shift.</div>
    </div></div>
    <button type="submit" class="btn btn-primary btn-touch w-100" id="prefs-save-btn">Save</button>
</form>
