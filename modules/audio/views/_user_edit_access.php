<?php
declare(strict_types=1);
/** @var array $user */
$checked = !empty($user['AUDIO_ANALYTICS_ENABLED']) ? ' checked' : '';
?>
<label class="check">
  <input type="checkbox" name="AUDIO_ANALYTICS_ENABLED" value="1"<?= $checked ?>>
  <?= te('audio.user_edit.analytics') ?>
</label>
