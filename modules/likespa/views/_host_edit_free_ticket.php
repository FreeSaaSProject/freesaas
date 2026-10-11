<?php
declare(strict_types=1);
/** @var array<string,mixed> $row */
/** @var bool $canEdit */
?>
<label class="check">
  <input type="checkbox" name="free_ticket" value="1"<?= !empty($row['free_ticket']) ? ' checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
  <?= te('likespa.hosts.field.free_ticket') ?>
</label>
