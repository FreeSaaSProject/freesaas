<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $sources */
/** @var int $sourceId */
/** @var string $sourceDescr */
?>
<section class="form-section">
  <h2><?= te('acquisition.client.sec') ?></h2>
  <p class="muted"><?= te('acquisition.client.hint') ?></p>
  <label><?= te('acquisition.client.source') ?>
    <select name="acquisition_source_id">
      <option value="0"><?= te('acquisition.client.source_none') ?></option>
      <?php foreach ($sources as $s): ?>
        <option value="<?= (int)$s['id'] ?>"<?= (int)$s['id'] === $sourceId ? ' selected' : '' ?>><?= e((string)$s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('acquisition.client.source_descr') ?>
    <input type="text" name="acquisition_source_descr" value="<?= e($sourceDescr) ?>" maxlength="255">
  </label>
</section>
