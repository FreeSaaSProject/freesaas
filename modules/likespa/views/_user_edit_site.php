<?php
declare(strict_types=1);
/** @var array $user */
?>
<h2 class="form-section-title"><?= te('likespa.user_edit.sec_site') ?></h2>
<label><?= te('likespa.user_edit.tips_link') ?>
  <input name="link_ok" type="url" value="<?= e((string)($user['link_ok'] ?? '')) ?>" placeholder="https://…">
</label>
<label><?= te('likespa.user_edit.photo') ?>
  <input name="photo" value="<?= e((string)($user['photo'] ?? '')) ?>" placeholder="/UserFiles/…">
</label>
<label><?= te('likespa.user_edit.site_descr') ?>
  <input name="site_descr" value="<?= e((string)($user['site_descr'] ?? '')) ?>">
</label>
