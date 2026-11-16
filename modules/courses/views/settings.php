<?php
declare(strict_types=1);
?>
<h1><?= te('courses.settings.title') ?></h1>
<form method="post" class="form">
  <?= Csrf::field() ?>
  <label><?= te('courses.settings.storage_root') ?>
    <input name="storage_root" value="<?= e($storage_root) ?>">
  </label>
  <label><?= te('courses.settings.media_ttl') ?>
    <input type="number" name="media_ttl" value="<?= (int)$media_ttl ?>">
  </label>
  <p><button class="btn" type="submit"><?= te('courses.settings.save') ?></button></p>
</form>
