<?php
declare(strict_types=1);
/** @var array $user */
$on = !empty($user['AUDIO_ANALYTICS_ENABLED']) ? te('core.common.on') : te('core.common.off');
?>
<dt><?= te('audio.user_edit.analytics') ?></dt><dd><?= e($on) ?></dd>
