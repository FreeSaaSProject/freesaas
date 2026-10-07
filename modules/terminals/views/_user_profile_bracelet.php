<?php
declare(strict_types=1);
/** @var string $staffBraceletUid */
$staffBraceletUid = (string)($staffBraceletUid ?? '');
?>
<dt><?= te('terminals.user_edit.bracelet_uid') ?></dt>
<dd><?= $staffBraceletUid !== '' ? e($staffBraceletUid) : '—' ?></dd>
