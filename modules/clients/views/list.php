<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $enabled */
/** @var int $statusId */
/** @var int $tagId */
/** @var array<int,string> $statuses */
/** @var array<int,string> $tags */
/** @var bool $showStatusColumn */
/** @var bool $showTagsColumn */
/** @var bool $showBirthdayColumn */
/** @var list<array{key:string,label:string}> $extraColumns */
/** @var bool $canEdit */
/** @var bool $canViewPii */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/clients';
$listId = 'clients';
$showAll = !empty($showAll);
$showStatusColumn = !empty($showStatusColumn);
$showTagsColumn = !empty($showTagsColumn);
$showBirthdayColumn = !empty($showBirthdayColumn);
$canViewPii = !empty($canViewPii);
$extraColumns = is_array($extraColumns ?? null) ? $extraColumns : [];
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 6
    + ($showStatusColumn ? 1 : 0)
    + ($showTagsColumn ? 1 : 0)
    + ($showBirthdayColumn ? 1 : 0)
    + count($extraColumns)
    + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <p class="lede"><?= t('clients.list.lede', ['total' => (int)$total]) ?></p>
    </div>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-primary" href="/clients/new"><?= te('core.common.add') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('clients.list.search_placeholder') ?>" data-filter-debounce>
  <select name="enabled" data-auto-submit>
    <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('clients.list.filter_active') ?></option>
    <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('clients.list.filter_disabled') ?></option>
    <option value=""<?= $enabled === '' ? ' selected' : '' ?>><?= te('core.common.all') ?></option>
  </select>
  <?php if ($statuses): ?>
  <select name="status_id" data-auto-submit>
    <option value="0"><?= te('clients.list.all_statuses') ?></option>
    <?php foreach ($statuses as $sid => $sname): ?>
      <option value="<?= (int)$sid ?>"<?= $statusId === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <?php if ($tags): ?>
  <select name="tag_id" data-auto-submit>
    <option value="0"><?= te('clients.list.all_tags') ?></option>
    <?php foreach ($tags as $tid => $tname): ?>
      <option value="<?= (int)$tid ?>"<?= $tagId === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
</form>

<?= $pager ?>

<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('clients.col.name'), 'name', $sort, $dir, $path, $filters) ?>
        <?php if ($canViewPii): ?>
          <?= ListQuery::sortTh(t('clients.col.phone'), 'tel', $sort, $dir, $path, $filters) ?>
          <?= ListQuery::sortTh(t('clients.col.email'), 'mail', $sort, $dir, $path, $filters) ?>
        <?php endif; ?>
        <?php if ($showStatusColumn): ?>
          <?= ListQuery::sortTh(t('clients.col.status'), 'status', $sort, $dir, $path, $filters) ?>
        <?php endif; ?>
        <?php if ($showTagsColumn): ?>
          <th><?= te('clients.col.tags') ?></th>
        <?php endif; ?>
        <?php if ($showBirthdayColumn): ?>
          <?= ListQuery::sortTh(t('clients.col.birthday'), 'birthday', $sort, $dir, $path, $filters) ?>
        <?php endif; ?>
        <?= ListQuery::sortTh(t('clients.col.registered'), 'registered', $sort, $dir, $path, $filters) ?>
        <?php foreach ($extraColumns as $col): ?>
          <th><?= e((string)($col['label'] ?? $col['key'] ?? '')) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $c): ?>
      <tr<?= (int)$c['enabled'] !== 1 ? ' class="is-disabled"' : '' ?>>
        <?= BulkActions::td($listId, (int)$c['id']) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/clients/<?= (int)$c['id'] ?>/edit"><?= (int)$c['id'] ?></a>
          <?php else: ?>
            <?= (int)$c['id'] ?>
          <?php endif; ?>
        </td>
        <td>
          <a href="/clients/<?= (int)$c['id'] ?>"><?= e((string)($c['name'] ?: '—')) ?></a>
          <?php if ((int)$c['enabled'] !== 1): ?>
            <span class="pill off"><?= te('core.common.off') ?></span>
          <?php endif; ?>
        </td>
        <?php if ($canViewPii): ?>
          <td><code><?= e((string)($c['tel'] ?: '—')) ?></code></td>
          <td><?= e((string)($c['mail'] ?: '—')) ?></td>
        <?php endif; ?>
        <?php if ($showStatusColumn): ?>
          <td><?= e((string)($c['status_name'] ?? '—')) ?></td>
        <?php endif; ?>
        <?php if ($showTagsColumn): ?>
        <td>
          <?php
            $tagNames = $c['tag_names'] ?? [];
            if (!$tagNames):
          ?>
            <span class="muted">—</span>
          <?php else: ?>
            <?php foreach ($tagNames as $tn): ?>
              <span class="pill"><?= e((string)$tn) ?></span>
            <?php endforeach; ?>
          <?php endif; ?>
        </td>
        <?php endif; ?>
        <?php if ($showBirthdayColumn): ?>
        <td class="muted">
          <?php
            $bd = (string)($c['birthday'] ?? '');
            echo $bd !== '' ? e(I18n::formatDate((int)strtotime($bd), 'date')) : '—';
          ?>
        </td>
        <?php endif; ?>
        <td class="muted">
          <?php
            $reg = (int)($c['registered_at'] ?? 0);
            echo $reg > 0 ? e(I18n::formatDate($reg, 'datetime')) : '—';
          ?>
        </td>
        <?php foreach ($extraColumns as $col): ?>
          <?php
            $key = (string)($col['key'] ?? '');
            $val = $key !== '' ? trim((string)($c[$key] ?? '')) : '';
          ?>
          <td class="muted"><?= $val !== '' ? '<code>' . e($val) . '</code>' : '—' ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('clients.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
