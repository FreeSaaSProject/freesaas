<?php
declare(strict_types=1);
/** @var array<string,mixed> $row */
/** @var bool $canSign */
/** @var bool $canOpen */
/** @var string $signerName */
/** @var string $message */
?>
<div class="auth-card docs-qr-card">
  <h1><?= te('docs.qr.title') ?></h1>
  <?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
  <dl class="docs-qr-meta">
    <dt><?= te('docs.field.def') ?></dt><dd><?= e((string)$row['def_name']) ?></dd>
    <dt><?= te('docs.qr.number') ?></dt><dd>#<?= (int)$row['id'] ?></dd>
    <dt><?= te('docs.field.dt_print') ?></dt><dd><?= e(I18n::formatDate((int)$row['dt_print'], 'datetime')) ?></dd>
    <dt><?= te('docs.field.status') ?></dt>
    <dd><?= $row['status'] === 'void' ? te('docs.qr.void') : te('docs.qr.valid') ?></dd>
    <dt><?= te('docs.qr.archive') ?></dt>
    <dd>
      <?php if ((int)$row['dt_signed'] > 0): ?>
        <?= te('docs.qr.signed', ['date' => I18n::formatDate((int)$row['dt_signed'], 'datetime')]) ?>
        <?php if ($signerName !== '' && $canOpen): ?>· <?= e($signerName) ?><?php endif; ?>
      <?php else: ?>
        <?= te('docs.qr.not_signed') ?>
      <?php endif; ?>
    </dd>
  </dl>
  <?php if ($canSign && (int)$row['dt_signed'] === 0 && $row['status'] !== 'void'): ?>
    <form method="post">
      <button class="btn-primary" type="submit"><?= te('docs.qr.sign') ?></button>
    </form>
  <?php endif; ?>
  <?php if ($canOpen): ?>
    <p><a href="/documents/<?= (int)$row['id'] ?>/edit"><?= te('docs.qr.open') ?></a></p>
  <?php endif; ?>
</div>
