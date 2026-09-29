<?php
/**
 * The rules the restaurant runs (screen `rules`). Data: site, sites, rules (find_site_rules), overrides, may (change), notice, tz, preset (the preset's name)
 * A card a rule: its sentence, a severity selector and its values; saved one rule at a time. Everyone who builds may read them; only settings.manage changes them.
 */
$siteId = (int) $site['site_id'];
$here = rules_url($siteId);
$badge = ['hard' => ['danger', 'Hard'], 'soft' => ['warning', 'Soft'], 'off' => ['secondary', 'Off']];
?>
<?= view('shared/header.php', ['id' => 'rules', 'title' => 'Rules', 'crumbs' => [['Home', '/'], ['Rules', null]]]) ?>
<div class="main-content" id="rules-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (count($sites) > 1): ?>
        <div class="d-flex flex-wrap gap-1 mb-3" id="rules-sites">
            <?php foreach ($sites as $x): ?><?= hx_link(rules_url((int) $x['scope_id']), e($x['name']), 'btn btn-touch ' . ((int) $x['scope_id'] === $siteId ? 'btn-primary' : 'btn-light'), 'id="rules-site-' . (int) $x['scope_id'] . '"') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="alert alert-secondary fs-13" id="rules-disclaimer"><i class="feather-info me-1"></i><?= e(RULES_DISCLAIMER) ?> <span class="d-block mt-1"><strong>Hard</strong> — the builder, the marketplace and publishing refuse it. <strong>Soft</strong> — they warn, and a manager can go ahead with a reason. <strong>Off</strong> — not checked. Rules are checked from the next time anyone asks.</span></div>
    <div class="fs-12 text-muted mb-3" id="rules-site-name"><?= e($site['name']) ?> — started from the <?= e($preset) ?> preset.</div>

    <div class="row g-3 mb-3" id="rules-cards">
        <?php foreach ($rules as $r): $k = $r['rule_key']; [$kind, $word] = $badge[$r['severity']]; ?>
            <div class="col-12 col-xl-6" id="rule-<?= e($k) ?>">
                <div class="card h-100"><div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="fw-semibold" id="rule-<?= e($k) ?>-name"><?= e($r['name']) ?></div>
                        <span class="badge bg-soft-<?= $kind ?> text-<?= $kind ?>" id="rule-<?= e($k) ?>-severity"><?= e($word) ?></span>
                    </div>
                    <div class="fs-13 text-muted mt-1 mb-2" id="rule-<?= e($k) ?>-explains"><?= e($r['explains']) ?></div>
                    <?php if ($may): ?>
                        <form method="post" action="/rules/save.php" hx-post="/rules/save.php" hx-target="#flash" id="rule-form-<?= e($k) ?>">
                            <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>"><input type="hidden" name="rule" value="<?= e($k) ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <label class="form-label fs-12 text-muted" for="rule-form-<?= e($k) ?>-field-severity">How strict</label>
                            <select name="severity" id="rule-form-<?= e($k) ?>-field-severity" class="form-select btn-touch mb-2">
                                <?php foreach (RULE_SEVERITIES as $v => $label): ?><option value="<?= e($v) ?>"<?= $v === $r['severity'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                            </select>
                            <?php foreach ($r['help'] as $name => [$label, $unit, $type, $min, $max, $default]): $val = $r['params'][$name] ?? $default; $fid = 'rule-form-' . $k . '-field-' . str_replace('_', '-', $name); ?>
                                <label class="form-label fs-12 text-muted" for="<?= e($fid) ?>"><?= e($label) ?> (<?= e($unit) ?>)</label>
                                <?php if ($type === 'time'): ?>
                                    <input type="time" name="params[<?= e($name) ?>]" id="<?= e($fid) ?>" class="form-control btn-touch mb-2" required value="<?= e($val) ?>">
                                <?php else: ?>
                                    <input type="number" inputmode="<?= $type === 'int' ? 'numeric' : 'decimal' ?>" step="<?= $type === 'int' ? '1' : '0.25' ?>" min="<?= e($min) ?>" max="<?= e($max) ?>" name="params[<?= e($name) ?>]" id="<?= e($fid) ?>" class="form-control btn-touch mb-2" required value="<?= e(days_label($val)) ?>">
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-light btn-touch w-100" id="rule-form-<?= e($k) ?>-save-btn">Save this rule</button>
                        </form>
                    <?php elseif ($r['help'] !== []): ?>
                        <div class="fs-12" id="rule-<?= e($k) ?>-values"><?php foreach ($r['help'] as $name => [$label, $unit]): ?><span class="me-2"><?= e($label) ?>: <?= e($r['params'][$name] ?? '') ?> <?= $unit === 'a time of day' ? '' : e($unit) ?></span><?php endforeach; ?></div>
                    <?php endif; ?>
                    <?php if ($k === 'cert_required'): ?>
                        <div class="fs-12 text-muted mt-2" id="rule-cert-required-kinds"><?= $certs === [] ? 'This restaurant has no certification kinds yet.' : 'This restaurant\'s kinds: ' . e(implode(', ', array_column($certs, 'name'))) . '.' ?> <?= hx_link('/certifications/?site=' . $siteId, 'Certifications', 'fw-semibold') ?></div>
                    <?php endif; ?>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($may): ?>
        <div class="card mb-3" id="rules-preset"><div class="card-body">
            <h6 class="mb-1">Start again from a preset</h6>
            <div class="fs-13 text-muted mb-2">Generic — a starting point with no jurisdiction's law behind it. It puts every rule back to its starting values.</div>
            <form method="post" action="/rules/preset.php" hx-post="/rules/preset.php" hx-target="#flash" hx-confirm="Put every rule back to the Generic starting values? Your changes to the rules are lost.">
                <?= csrf_field() ?><input type="hidden" name="site" value="<?= $siteId ?>"><input type="hidden" name="preset" value="generic"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <button type="submit" class="btn btn-light btn-touch w-100" id="rules-preset-apply-btn">Apply the Generic preset</button>
            </form>
        </div></div>
    <?php endif; ?>

    <h6 class="text-muted text-uppercase fs-11">Overrides in the last 30 days</h6>
    <div class="card mb-3" id="rules-overrides">
        <ul class="list-group list-group-flush">
            <?php if ($overrides === []): ?><li class="list-group-item text-muted" id="rules-overrides-empty">No rule was overridden in the last 30 days.</li><?php endif; ?>
            <?php foreach ($overrides as $o): $oid = (int) $o['override_id']; ?>
                <li class="list-group-item" id="override-<?= $oid ?>">
                    <div class="fw-semibold"><span id="override-<?= $oid ?>-rule"><?= e($o['rule_name']) ?></span><?= $o['person'] !== null ? ' — ' . e($o['person']) : '' ?></div>
                    <div class="fs-13" id="override-<?= $oid ?>-reason">“<?= e($o['reason']) ?>”</div>
                    <div class="fs-12 text-muted"><?= e($o['message']) ?> · <span id="override-<?= $oid ?>-by"><?= e($o['by_name'] ?? 'Someone') ?></span> · <?= e(format_ts($o['created_at'], $tz, 'M j, g:i A')) ?> · <?= e($o['context']) ?>
                        <?php if ($o['shift_id'] !== null && has_right('schedule.build', $siteId)): ?> · <?= hx_link(with_back('/shifts/' . (int) $o['shift_id'], $here), 'the shift') ?><?php endif; ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php if (has_right('schedule.build', $siteId)): ?>
        <div class="mb-3"><?= hx_link('/reports/?report=overrides&site=' . $siteId, '<i class="feather-bar-chart-2 me-1"></i>The overrides report', 'btn btn-light btn-touch', 'id="rules-to-overrides-report"') ?></div>
    <?php endif; ?>
</div>
