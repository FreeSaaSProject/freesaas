<?php
declare(strict_types=1);
/** @var array $user */
$checked = !empty($user['STOCK_REVISION']) ? ' checked' : '';
?>
<label class="check">
  <input type="checkbox" name="STOCK_REVISION" value="1"<?= $checked ?>>
  <?= te('stocks.user_edit.revision') ?>
</label>
