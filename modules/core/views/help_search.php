<?php
declare(strict_types=1);
/** @var string $q */
/** @var string $module */
/** @var list<array<string,mixed>> $items */
?>
<form class="filters" method="get" action="/help">
  <label>
    <?= e(t('core.help.q')) ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('core.help.q_ph')) ?>">
  </label>
  <label>
    <?= e(t('core.help.module')) ?>
    <input type="text" name="module" value="<?= e($module) ?>" placeholder="certificates">
  </label>
  <button type="submit"><?= e(t('core.help.search_btn')) ?></button>
</form>

<?php if ($items === []): ?>
  <p class="muted"><?= e(t('core.help.empty')) ?></p>
<?php else: ?>
  <ul class="help-list">
    <?php foreach ($items as $row): ?>
      <li>
        <a href="/help/<?= e(rawurlencode((string)$row['module'])) ?>"><?= e((string)$row['title']) ?></a>
        <span class="muted"><?= e((string)$row['module']) ?></span>
        <?php if (!empty($row['has_docs'])): ?>
          <span class="badge"><?= e(t('core.help.has_docs')) ?></span>
        <?php endif; ?>
        <?php if ((string)($row['summary'] ?? '') !== ''): ?>
          <p class="muted"><?= e((string)$row['summary']) ?></p>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
