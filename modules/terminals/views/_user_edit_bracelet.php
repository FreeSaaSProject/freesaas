<?php
declare(strict_types=1);
/** @var string $staffBraceletUid */
$staffBraceletUid = (string)($staffBraceletUid ?? '');
?>
<h2 class="form-section-title"><?= te('terminals.user_edit.sec') ?></h2>
<p class="form-hint"><?= te('terminals.user_edit.hint') ?></p>
<label><?= te('terminals.user_edit.bracelet_uid') ?>
  <input name="staff_bracelet_uid" value="<?= e($staffBraceletUid) ?>" maxlength="32" autocomplete="off">
</label>
