<?php
declare(strict_types=1);
/** @var array $user */
$yes = !empty($user['STOCK_REVISION']) ? te('core.common.yes') : te('core.common.no');
?>
<dt><?= te('stocks.user_edit.revision') ?></dt><dd><?= e($yes) ?></dd>
