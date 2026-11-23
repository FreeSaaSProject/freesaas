<?php
declare(strict_types=1);
/** @var string $name */
/** @var string $sourceDescr */
/** @var int $sourceId */
?>
<section class="profile-section">
  <h2><?= te('acquisition.client.sec') ?></h2>
  <dl class="kv">
    <dt><?= te('acquisition.client.source') ?></dt>
    <dd><?= $sourceId > 0 ? e($name) : te('acquisition.client.source_none') ?></dd>
    <?php if ($sourceDescr !== ''): ?>
      <dt><?= te('acquisition.client.source_descr') ?></dt>
      <dd><?= e($sourceDescr) ?></dd>
    <?php endif; ?>
  </dl>
</section>
