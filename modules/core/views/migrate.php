<?php
/** @var string $message */
/** @var array $schemaReport */
/** @var array $dataReport */
/** @var bool $hasUsers */
/** @var bool $hasSystem */
/** @var int $userCount */
?>
<section class="page-head">
  <p class="lede"><?= t('core.migrate.lede', ['modules_link' => '<a href="/modules">' . te('core.menu.modules') . '</a>']) ?></p>
</section>

<div class="status-row">
  <div class="stat<?= $hasUsers ? ' ok' : '' ?>">base_users <?= $hasUsers ? '✓' : '✗' ?> (<?= (int)$userCount ?>)</div>
  <div class="stat<?= $hasSystem ? ' ok' : '' ?>">base_system <?= $hasSystem ? '✓' : '✗' ?></div>
</div>

<?php if ($message !== ''): ?>
  <div class="flash flash-ok"><?= e($message) ?></div>
<?php endif; ?>

<div class="actions">
  <form method="post">
    <input type="hidden" name="action" value="schema">
    <button class="btn-secondary" type="submit"><?= te('core.migrate.btn_schema') ?></button>
  </form>
  <form method="post" onsubmit="return confirm(<?= tj('core.migrate.confirm_data') ?>);">
    <input type="hidden" name="action" value="data">
    <button class="btn-primary" type="submit"><?= te('core.migrate.btn_data') ?></button>
  </form>
</div>

<?php if ($schemaReport): ?>
  <h2 class="subhead"><?= te('core.migrate.schema_report') ?></h2>
  <ul class="report">
    <?php foreach ($schemaReport as $r): ?>
      <li class="<?= e($r['status']) ?>"><?= e($r['module'].'/'.$r['id'].': '.$r['status']) ?><?= !empty($r['message']) ? ' — '.e($r['message']) : '' ?></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($dataReport): ?>
  <h2 class="subhead"><?= te('core.migrate.data_report') ?></h2>
  <ul class="report">
    <?php foreach ($dataReport as $r): ?>
      <li class="<?= e($r['status']) ?>">
        <?= e($r['module'].'/'.$r['id'].': '.$r['status']) ?>
        <?php if (!empty($r['stats']['copied'])): ?>
          — <?= te('core.migrate.copied', ['n' => (int)$r['stats']['copied']]) ?>
        <?php endif; ?>
        <?php if (!empty($r['message'])): ?> — <?= e($r['message']) ?><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
