<?php
declare(strict_types=1);
/** @var array $user */
if (!Database::columnExists('base_users', 'IS_ACCEPTOR')) {
    return;
}
$checked = !empty($user['IS_ACCEPTOR']) ? ' checked' : '';
?>
<label class="check">
  <input type="checkbox" name="IS_ACCEPTOR" value="1"<?= $checked ?>>
  <?= te('contracts.user_edit.acceptor') ?>
</label>
