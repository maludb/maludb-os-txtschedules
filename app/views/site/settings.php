<?php
/**
 * The restaurant's settings (screen `site-settings`; settings.manage). Data: site (find_site_row), sites, s (typed settings), notice.
 * One form, saved whole. The trade rows are stacked selects on a phone; the sentence under them and the example under the hours-a-day field follow what is typed.
 */
$siteId = (int) $site['site_id'];
$yn = static function (string $name, bool $on, string $idp): string {
    return '<select name="' . e($name) . '" id="' . e($idp) . '" class="form-select btn-touch">'
        . '<option value="yes"' . ($on ? ' selected' : '') . '>Yes</option><option value="no"' . (!$on ? ' selected' : '') . '>No</option></select>';
};
$pick = static function (string $name, array $choices, string $cur, string $idp): string {
    $o = '';
    foreach ($choices as $v => $label) {
        $o .= '<option value="' . e($v) . '"' . ($v === $cur ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return '<select name="' . e($name) . '" id="' . e($idp) . '" class="form-select btn-touch">' . $o . '</select>';
};
$kinds = [
    'offer' => ['Staff may offer a shift they cannot work', false],
    'pickup' => ['Staff may pick up an offered or open shift', true],
    'swap' => ['Staff may swap shifts with a colleague', true],
    'give' => ['Staff may give a shift to a colleague', true],
];
$f = 'site-settings-form';
?>
<?= view('shared/header.php', ['id' => 'site-settings', 'title' => 'Restaurant settings', 'crumbs' => [['Home', '/'], ['Settings', null]]]) ?>
<div class="main-content" id="site-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (count($sites) > 1): ?>
        <div class="d-flex flex-wrap gap-1 mb-3" id="site-settings-sites">
            <?php foreach ($sites as $x): ?><?= hx_link(site_url((int) $x['scope_id']), e($x['name']), 'btn btn-touch ' . ((int) $x['scope_id'] === $siteId ? 'btn-primary' : 'btn-light'), 'id="site-settings-site-' . (int) $x['scope_id'] . '"') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="d-flex gap-2 flex-wrap mb-3" id="site-settings-links">
        <?= hx_link(day_parts_url($siteId), '<i class="feather-sunrise me-1"></i>Day-parts', 'btn btn-light btn-touch flex-fill', 'id="site-settings-to-day-parts"') ?>
        <?= hx_link('/site/time-off?site=' . $siteId, '<i class="feather-sun me-1"></i>Time-off types', 'btn btn-light btn-touch flex-fill', 'id="site-settings-to-time-off-types"') ?>
        <?= hx_link(rules_url($siteId), '<i class="feather-shield me-1"></i>Rules', 'btn btn-light btn-touch flex-fill', 'id="site-settings-to-rules"') ?>
    </div>
    <div class="fs-12 text-muted mb-3" id="site-settings-site-name"><?= e($site['name']) ?> — these settings are this restaurant's alone. A change applies from the next thing anyone does; what is already asked keeps the rules it was asked under (the cutoff is read live).</div>

    <form method="post" action="/site/save.php" hx-post="/site/save.php" hx-target="#flash" id="<?= $f ?>">
        <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>">

        <div class="card mb-3" id="site-settings-trades"><div class="card-header"><h6 class="card-title mb-0">How shifts change hands</h6></div><div class="card-body">
            <?php foreach ($kinds as $k => [$label, $needsApproval]): ?>
                <div class="border rounded p-3 mb-3" id="site-settings-trade-<?= e($k) ?>">
                    <label class="form-label fw-semibold" for="<?= $f ?>-field-allow-<?= e($k) ?>"><?= e($label) ?></label>
                    <?= $yn('allow_' . $k, (bool) $s['allow_' . $k], $f . '-field-allow-' . $k) ?>
                    <?php if ($needsApproval): ?>
                        <label class="form-label fs-12 text-muted mt-2" for="<?= $f ?>-field-approval-<?= e($k) ?>">A manager must approve</label>
                        <?= $pick('approval_' . $k, APPROVAL_CHOICES, (string) $s['approval_' . $k], $f . '-field-approval-' . $k) ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <label class="form-label fw-semibold" for="<?= $f ?>-field-cutoff">No trade closer than (minutes before the start)</label>
            <input type="number" inputmode="numeric" step="1" min="0" max="10080" name="cutoff_minutes" id="<?= $f ?>-field-cutoff" class="form-control btn-touch mb-3" value="<?= (int) $s['cutoff_minutes'] ?>">
            <label class="form-label fw-semibold" for="<?= $f ?>-field-lead">Shift leads may approve same-day and next-day trades</label>
            <div class="mb-3"><?= $yn('shift_lead_approves_same_day', (bool) $s['shift_lead_approves_same_day'], $f . '-field-lead') ?></div>
            <label class="form-label fw-semibold" for="<?= $f ?>-field-claim">When several people ask for a shift</label>
            <div class="mb-3"><?= $pick('claim_mode', ['first' => 'The first one wins', 'manager_chooses' => 'A manager chooses'], (string) $s['claim_mode'], $f . '-field-claim') ?></div>
            <label class="form-label fw-semibold" for="<?= $f ?>-field-expires">An offer nobody takes ends</label>
            <div class="mb-3"><?= $pick('offer_expires', ['at_start' => 'When the shift starts', 'at_cutoff' => 'At the cutoff'], (string) $s['offer_expires'], $f . '-field-expires') ?></div>
            <div class="alert alert-secondary fs-13 mb-0" id="site-settings-preview" aria-live="polite" hx-get="/site/?preview=1" hx-trigger="change from:#<?= $f ?> delay:150ms, input from:#<?= $f ?> delay:300ms" hx-include="#<?= $f ?>" hx-target="this" hx-swap="innerHTML" hx-sync="this:replace"><?= view('site/partials/sentence.php', ['s' => $s]) ?></div>
        </div></div>

        <div class="card mb-3" id="site-settings-week"><div class="card-header"><h6 class="card-title mb-0">The week and reminders</h6></div><div class="card-body">
            <label class="form-label fw-semibold" for="<?= $f ?>-field-week-start">The week starts on</label>
            <div class="mb-3"><?= $pick('week_start', array_map('strval', WEEKDAYS), (string) $s['week_start'], $f . '-field-week-start') ?></div>
            <label class="form-label fw-semibold" for="<?= $f ?>-field-currency">Currency</label>
            <input type="text" name="currency" id="<?= $f ?>-field-currency" class="form-control btn-touch mb-1" maxlength="3" value="<?= e($s['currency']) ?>" autocomplete="off">
            <div class="fs-12 text-muted mb-3">A three-letter code like USD.</div>
            <label class="form-label fw-semibold" for="<?= $f ?>-field-avail">Availability changes need a manager</label>
            <div class="mb-3"><?= $yn('availability_needs_approval', (bool) $s['availability_needs_approval'], $f . '-field-avail') ?></div>
            <label class="form-label fw-semibold" for="<?= $f ?>-field-reminder">Remind people before a shift (minutes)</label>
            <input type="number" inputmode="numeric" step="1" min="0" max="2880" name="reminder_minutes_before" id="<?= $f ?>-field-reminder" class="form-control btn-touch" value="<?= (int) $s['reminder_minutes_before'] ?>">
            <div class="fs-12 text-muted mt-1">A person can choose their own lead time in My settings.</div>
        </div></div>

        <div class="card mb-3" id="site-settings-timeoff"><div class="card-header"><h6 class="card-title mb-0">Time off</h6></div><div class="card-body">
            <label class="form-label fw-semibold" for="<?= $f ?>-field-day-hours">A day of time off counts (hours)</label>
            <input type="number" inputmode="decimal" step="0.25" min="0.25" max="24" name="time_off_day_hours" id="<?= $f ?>-field-day-hours" class="form-control btn-touch mb-1" value="<?= e(days_label($s['time_off_day_hours'])) ?>"
                   hx-get="/site/?preview=day" hx-trigger="input delay:200ms, change" hx-include="#<?= $f ?>-field-day-hours" hx-target="#site-settings-day-hours-example" hx-swap="innerHTML">
            <div class="fs-12" id="site-settings-day-hours-example"><?= e(day_hours_sentence((float) $s['time_off_day_hours'])) ?></div>
            <div class="fs-12 text-muted mt-1">What a request with no hours counts for each day. Requests already asked keep the hours they were counted at.</div>
        </div></div>

        <div class="card mb-3" id="site-settings-overtime"><div class="card-header"><h6 class="card-title mb-0">Overtime</h6></div><div class="card-body">
            <label class="form-label fw-semibold" for="<?= $f ?>-field-ot-hours">Overtime after (hours a week)</label>
            <input type="number" inputmode="decimal" step="0.25" min="1" max="168" name="overtime_weekly_hours" id="<?= $f ?>-field-ot-hours" class="form-control btn-touch mb-3" value="<?= e(days_label($s['overtime_weekly_hours'])) ?>">
            <label class="form-label fw-semibold" for="<?= $f ?>-field-ot-mult">Overtime multiplier</label>
            <input type="number" inputmode="decimal" step="0.05" min="1" max="10" name="overtime_multiplier" id="<?= $f ?>-field-ot-mult" class="form-control btn-touch" value="<?= e(days_label($s['overtime_multiplier'])) ?>">
            <div class="fs-12 text-muted mt-1">Used by the hours bars and the overtime report.</div>
        </div></div>

        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="<?= $f ?>-save-btn">Save the settings</button>
        <?= hx_link('/', 'Cancel', 'btn btn-light btn-touch w-100 mb-3', 'id="' . $f . '-cancel-link"') ?>
    </form>
</div>
