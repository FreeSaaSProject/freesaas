<?php
declare(strict_types=1);
/** @var list<array> $items */
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <p class="lede"><?= t('core.statuses.lede') ?></p>
    </div>
    <div class="page-actions">
      <a class="btn-primary" href="/statuses/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
</section>

<?php
/** @var string $sort */
/** @var string $dir */
/** @var array $filters */
$path = '/statuses';
$sort = $sort ?? 'id';
$dir = $dir ?? 'asc';
$filters = $filters ?? [];
?>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('core.statuses.col_name'), 'name', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('core.statuses.col_type'), 'type', $sort, $dir, $path, $filters) ?>
        <th><?= te('core.statuses.col_flags') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $s): ?>
      <?php
        $flags = [];
        if (!empty($s['SUPERADMIN_ACCESS'])) $flags[] = 'super';
        if (!empty($s['CONTROL_ACCESS'])) $flags[] = 'control';
        if (!empty($s['SHOW_NEWS'])) $flags[] = 'news';
        $type = !empty($s['ASSIST_STA']) ? t('core.statuses.type_partner') : t('core.statuses.type_staff');
      ?>
      <tr>
        <td class="muted"><a href="/statuses/<?= (int)$s['ID'] ?>/edit"><?= (int)$s['ID'] ?></a></td>
        <td>
          <?= e((string)$s['NAME']) ?>
          <?php if (empty($s['ENABLED'])): ?>
            <span class="pill off"><?= te('core.common.off') ?></span>
          <?php endif; ?>
        </td>
        <td><?= e($type) ?></td>
        <td class="muted"><?= e($flags ? implode(', ', $flags) : '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="4" class="muted"><?= te('core.statuses.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
