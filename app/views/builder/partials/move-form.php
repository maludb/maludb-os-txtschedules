<?php
/**
 * "Move to…": a day and a person, no drag needed — the keyboard's and the phone's way, and the same action the drag posts (shift_update for a draft week, shift_change for a live one).
 * Data: s (the shift), days [[date, dow, dom, isToday]], people [[member_id, display_name]], live (is the week published), here (where to land), idp (an id prefix)
 */
$id = (int) $s['shift_id'];
$path = $live ? '/shifts/change.php' : '/shifts/save.php';
$curDay = shift_day($s);
?>
<details class="move-details mt-2" id="<?= e($idp) ?>-move-<?= $id ?>">
    <summary class="btn btn-light btn-touch w-100" id="<?= e($idp) ?>-move-<?= $id ?>-open"><i class="feather-move me-1"></i>Move to…</summary>
    <form method="post" action="<?= e($path) ?>" hx-post="<?= e($path) ?>" hx-target="#flash" class="pt-2" id="<?= e($idp) ?>-move-<?= $id ?>-form"<?= $live ? ' hx-confirm="This is live: staff will be told."' : '' ?>>
        <?= csrf_field() ?><input type="hidden" name="shift" value="<?= $id ?>">
        <label class="form-label fs-12 text-muted mb-1" for="<?= e($idp) ?>-move-<?= $id ?>-day">Day</label>
        <select name="date" id="<?= e($idp) ?>-move-<?= $id ?>-day" class="form-select btn-touch mb-2">
            <?php foreach ($days as [$date, $dow, $dom]): ?><option value="<?= e($date) ?>" <?= $date === $curDay ? 'selected' : '' ?>><?= e($dow . ' ' . $dom) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label fs-12 text-muted mb-1" for="<?= e($idp) ?>-move-<?= $id ?>-person">Person</label>
        <select name="assignee" id="<?= e($idp) ?>-move-<?= $id ?>-person" class="form-select btn-touch mb-2">
            <option value="">Leave open</option>
            <?php foreach ($people as $p): ?><option value="<?= (int) $p['member_id'] ?>" <?= (int) $p['member_id'] === (int) $s['assignee_member_id'] ? 'selected' : '' ?>><?= e($p['display_name']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label fs-12 text-muted mb-1" for="<?= e($idp) ?>-move-<?= $id ?>-reason">If a warning stops it, say why (optional)</label>
        <input type="text" name="override_reason" id="<?= e($idp) ?>-move-<?= $id ?>-reason" class="form-control btn-touch mb-2" maxlength="500">
        <button type="submit" class="btn btn-primary btn-touch w-100" id="<?= e($idp) ?>-move-<?= $id ?>-save-btn">Move it</button>
    </form>
</details>
