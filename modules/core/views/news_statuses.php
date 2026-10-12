<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var string $sort */
/** @var string $dir */
/** @var array $filters */
$sort = $sort ?? 'id';
$dir = $dir ?? 'asc';
$filters = $filters ?? [];
$path = '/news-statuses';
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <p class="lede"><?= t('core.news_statuses.lede') ?></p>
    </div>
    <div class="page-actions">
      <a class="btn-primary" href="/news-statuses/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
</section>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('core.news_statuses.col_name'), 'name', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('core.news_statuses.col_type'), 'type', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('core.news_statuses.col_policy'), 'policy', $sort, $dir, $path, $filters) ?>
        <th><?= te('core.news_statuses.col_color') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $s): ?>
      <?php
        $sid = (int)$s['ID'];
        $policy = (string)($s['DEADLINE_POLICY'] ?? 'optional');
      ?>
      <tr>
        <td class="muted"><a href="/news-statuses/<?= $sid ?>/edit"><?= $sid ?></a></td>
        <td>
          <a href="/news-statuses/<?= $sid ?>/edit"><?= e((string)$s['NAME']) ?></a>
          <?= status_pill([
              'NAME' => (string)$s['NAME'],
              'COLOR' => (string)($s['COLOR'] ?? ''),
          ]) ?>
        </td>
        <td><?= (int)($s['TYPE'] ?? 0) ?></td>
        <td><?= e(t('core.news_statuses.policy.' . $policy)) ?></td>
        <td class="muted"><code><?= e((string)($s['COLOR'] ?? '') ?: '—') ?></code></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="5" class="muted"><?= te('core.news_statuses.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
