<?php
/** What a week action just did (copy, template, auto-fill), shown once on the builder. Data: result (remember_result()) */
$r = $result;
$kind = $r['kind'];
$left = $r['left_open'] ?? [];
?>
<div class="card mb-3 border-info" id="builder-result" role="status">
    <div class="card-body">
        <?php if ($kind === 'autofill'): ?>
            <div class="fw-semibold mb-1" id="builder-result-title">Auto-fill: <?= count($r['filled']) ?> filled, <?= count($r['still_open']) ?> still open<?= (int) $r['warnings'] > 0 ? ', ' . (int) $r['warnings'] . ' warning' . ((int) $r['warnings'] === 1 ? '' : 's') . ' left' : '' ?>.</div>
            <?php if (($r['seeded'] ?? null) !== null): ?><div class="fs-12 text-muted mb-1">First the week was started from <?= $r['seeded']['from'] === 'template' ? 'the template' : 'the last published week' ?> (<?= (int) $r['seeded']['placed'] ?> shifts placed<?= (int) $r['seeded']['left_open'] > 0 ? ', ' . (int) $r['seeded']['left_open'] . ' left open' : '' ?>).</div><?php endif; ?>
            <?php if ($r['filled'] !== []): ?><ul class="fs-12 mb-2" id="builder-result-filled"><?php foreach ($r['filled'] as $f): ?><li><?= e($f['when'] . ' ' . $f['position'] . ' → ' . $f['name']) ?><?= $f['warnings'] !== [] ? ' — ⚠ ' . e(implode(' ', $f['warnings'])) : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php if ($r['still_open'] !== []): ?><div class="fs-12 fw-semibold">Still open</div><ul class="fs-12 mb-2" id="builder-result-open"><?php foreach ($r['still_open'] as $o): ?><li><?= e($o['when'] . ' ' . $o['position'] . ' — ' . $o['reason']) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php if ($r['hours'] !== []): ?><div class="fs-12 text-muted" id="builder-result-hours">Hours: <?= e(implode(', ', array_map(static fn (array $h): string => $h['name'] . ' ' . days_label($h['hours']), $r['hours']))) ?></div><?php endif; ?>
        <?php else: ?>
            <div class="fw-semibold mb-1" id="builder-result-title"><?= $kind === 'template' ? 'From the template' : 'Copied' ?>: <?= (int) $r['placed'] ?> shift<?= (int) $r['placed'] === 1 ? '' : 's' ?> placed<?= $left !== [] ? ', ' . count($left) . ' left open' : '' ?>.</div>
            <?php if ((int) ($r['existing'] ?? 0) > 0): ?><div class="fs-12 text-muted mb-1"><?= (int) $r['existing'] ?> shift<?= (int) $r['existing'] === 1 ? ' was' : 's were' ?> already in this week; these were added beside them.</div><?php endif; ?>
            <?php if ($left !== []): ?><ul class="fs-12 mb-0" id="builder-result-open"><?php foreach ($left as $o): ?><li><?= e($o['when'] . ' ' . $o['position'] . ' — ' . $o['reason']) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php foreach ($r['skipped'] ?? [] as $sk): ?><div class="fs-12 text-muted">Skipped <?= e($sk['date']) ?>: <?= e($sk['reason']) ?></div><?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
