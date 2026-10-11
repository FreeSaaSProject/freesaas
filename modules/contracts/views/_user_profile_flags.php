<?php
declare(strict_types=1);
/** @var array $user */
if (!Database::columnExists('base_users', 'IS_ACCEPTOR')) {
    return;
}
$yes = !empty($user['IS_ACCEPTOR']) ? te('core.common.yes') : te('core.common.no');
?>
<dt><?= te('contracts.user_edit.acceptor') ?></dt><dd><?= e($yes) ?></dd>
