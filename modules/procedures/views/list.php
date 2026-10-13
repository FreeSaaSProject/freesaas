<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $enabled */
/** @var int $typeId */
/** @var string $dateFrom */
/** @var string $dateTo */
/** @var string $dayFocus */
/** @var bool $isToday */
/** @var bool $dayNavActive */
/** @var string $prevDay */
/** @var string $nextDay */
/** @var string $today */
/** @var int $idUser */
/** @var int $idGood */
/** @var int $idAdmin */
/** @var int $idOperator */
/** @var string $restored */
/** @var array<int,string> $typeNames */
/** @var array<int,string> $staffNames */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/procedures';
$listId = 'procedures';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 7 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);

$listUrl = ListQuery::url($path, $filters);
$newHref = ListQuery::url('/procedures/new', ['ret' => $listUrl]);

$dayNavBase = $filters;
unset($dayNavBase['page']);
$urlToday = ListQuery::url($path, $dayNavBase, ['date_from' => $today, 'date_to' => $today, 'page' => 1]);
$urlClearDates = ListQuery::url($path, $dayNavBase, ['date_from' => '', 'date_to' => '', 'page' => 1]);
$urlPrev = ListQuery::url($path, $dayNavBase, ['date_from' => $prevDay, 'date_to' => $prevDay, 'page' => 1]);
$urlNext = ListQuery::url($path, $dayNavBase, ['date_from' => $nextDay, 'date_to' => $nextDay, 'page' => 1]);

$dayTs = strtotime($dayFocus . ' 12:00:00') ?: time();
$dayLabel = I18n::formatDate($dayTs, 'date');
?>
<section class="page-head">
  <div class="page-head-row">
    <div class="page-actions date-day-nav" style="gap:0.5rem;align-items:center;flex-wrap:wrap">
      <?php if (!empty($dayNavActive)): ?>
        <a class="btn-secondary" href="<?= e($urlPrev) ?>" title="<?= te('procedures.list.prev_day') ?>" aria-label="<?= te('procedures.list.prev_day') ?>">‹</a>
        <strong><?= e($dayLabel) ?></strong>
        <a class="btn-secondary" href="<?= e($urlNext) ?>" title="<?= te('procedures.list.next_day') ?>" aria-label="<?= te('procedures.list.next_day') ?>">›</a>
        <?php if (!$isToday): ?>
          <a class="btn-secondary" href="<?= e($urlToday) ?>"><?= te('procedures.list.today') ?></a>
        <?php else: ?>
          <span class="pill"><?= te('procedures.list.today') ?></span>
        <?php endif; ?>
        <a class="btn-secondary" href="<?= e($urlClearDates) ?>"><?= te('procedures.list.all_dates') ?></a>
      <?php else: ?>
        <a class="btn-secondary" href="<?= e($urlToday) ?>"><?= te('procedures.list.today') ?></a>
      <?php endif; ?>
    </div>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-primary" href="<?= e($newHref) ?>"><?= te('core.common.add') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('procedures.list.search_placeholder') ?>" data-filter-debounce>
  <label class="muted"><?= te('procedures.list.date_from') ?>
    <input type="date" name="date_from" value="<?= e($dateFrom) ?>" data-auto-submit>
  </label>
  <label class="muted"><?= te('procedures.list.date_to') ?>
    <input type="date" name="date_to" value="<?= e($dateTo) ?>" data-auto-submit>
  </label>
  <select name="type" data-auto-submit>
    <option value=""><?= te('procedures.list.all_types') ?></option>
    <?php foreach ($typeNames as $tid => $tlabel): ?>
      <option value="<?= (int)$tid ?>"<?= $typeId === (int)$tid ? ' selected' : '' ?>><?= e($tlabel) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="enabled" data-auto-submit>
    <option value=""<?= $enabled === '' ? ' selected' : '' ?>><?= te('core.common.all') ?></option>
    <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('procedures.list.filter_active') ?></option>
    <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('procedures.list.filter_disabled') ?></option>
  </select>
  <select name="restored" data-auto-submit>
    <option value=""<?= $restored === '' ? ' selected' : '' ?>><?= te('procedures.list.filter_restored_all') ?></option>
    <option value="1"<?= $restored === '1' ? ' selected' : '' ?>><?= te('procedures.list.filter_restored_yes') ?></option>
    <option value="0"<?= $restored === '0' ? ' selected' : '' ?>><?= te('procedures.list.filter_restored_no') ?></option>
  </select>
  <input type="number" min="0" name="id_user" value="<?= $idUser > 0 ? (int)$idUser : '' ?>" placeholder="<?= te('procedures.list.client_id') ?>" data-filter-debounce>
  <input type="number" min="0" name="id_good" value="<?= $idGood > 0 ? (int)$idGood : '' ?>" placeholder="<?= te('procedures.list.product_id') ?>" data-filter-debounce>
  <?php if (!empty($canViewAll)): ?>
  <select name="id_admin" data-auto-submit>
    <option value=""><?= te('procedures.col.admin') ?></option>
    <?php foreach ($staffNames as $sid => $slabel): ?>
      <option value="<?= (int)$sid ?>"<?= $idAdmin === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="id_operator" data-auto-submit>
    <option value=""><?= te('procedures.list.filter_operator') ?></option>
    <?php foreach ($staffNames as $sid => $slabel): ?>
      <option value="<?= (int)$sid ?>"<?= $idOperator === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
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
        <?= ListQuery::sortTh(t('procedures.col.id'), 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('procedures.col.dtstart'), 'dtstart', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('procedures.col.type'), 'type', $sort, $dir, $path, $filters) ?>
        <th><?= te('procedures.col.client') ?></th>
        <th><?= te('procedures.col.product') ?></th>
        <?= ListQuery::sortTh(t('procedures.col.summ'), 'summ', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('procedures.col.enabled'), 'enabled', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $p): ?>
      <?php
        $pid = (int)$p['id'];
        $editHref = ListQuery::url('/procedures/' . $pid . '/edit', ['ret' => $listUrl]);
        $dtstart = (int)($p['dtstart'] ?? 0);
        $dtcreate = (int)($p['dtcreate'] ?? 0);
        $summ = (string)($p['summ'] ?? '0');
        $summDefault = (string)($p['summ_default'] ?? '0');
        $statusName = trim((string)($p['client_status_name'] ?? ''));
        $rowExtra = EventBus::emit('procedures.list.row', [
            'row' => $p,
            'html_after_id' => '',
            'html_after_client' => '',
        ]);
        $htmlAfterId = is_array($rowExtra) ? (string)($rowExtra['html_after_id'] ?? '') : '';
        $htmlAfterClient = is_array($rowExtra) ? (string)($rowExtra['html_after_client'] ?? '') : '';
      ?>
      <tr>
        <?= BulkActions::td($listId, $pid) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="<?= e($editHref) ?>"><?= $pid ?></a>
          <?php else: ?>
            <?= $pid ?>
          <?php endif; ?>
          <?php if ($htmlAfterId !== ''): ?>
            <?= $htmlAfterId ?>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($dtstart > 0): ?>
            <?= e(I18n::formatDate($dtstart, 'datetime')) ?>
          <?php else: ?>
            —
          <?php endif; ?>
          <?php if ($dtcreate > 0): ?>
            <br><span class="muted"><?= te('procedures.list.created') ?>:
              <?php if ($dtstart > 0 && date('Y-m-d', $dtcreate) !== date('Y-m-d', $dtstart)): ?>
                <?= e(I18n::formatDate($dtcreate, 'datetime')) ?>
              <?php else: ?>
                <?= e(date('H:i', $dtcreate)) ?>
              <?php endif; ?>
            </span>
          <?php endif; ?>
        </td>
        <td><?= e((string)($p['type_name'] ?: ('#' . (int)$p['type']))) ?></td>
        <td>
          <?php if ((int)$p['id_user'] > 0): ?>
            <a href="/clients/<?= (int)$p['id_user'] ?>"><?= e((string)($p['client_name'] ?: ('#' . (int)$p['id_user']))) ?></a>
            <?php if ($statusName !== ''): ?>
              <br><b style="color:red"><?= e($statusName) ?></b>
            <?php endif; ?>
          <?php else: ?>
            —
          <?php endif; ?>
          <?php
            $opId = (int)($p['id_operator'] ?? 0);
            $admId = (int)($p['id_admin'] ?? 0);
            if ($opId > 0 || $admId > 0):
          ?>
            <div class="badge-row" style="margin-top:0.25rem">
              <?php if ($opId > 0): ?>
                <?= UsersRepository::badgeHtml($opId, true, true) ?>
              <?php endif; ?>
              <?php if ($admId > 0 && $admId !== $opId): ?>
                <?= UsersRepository::badgeHtml($admId, true, true) ?>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if ($htmlAfterClient !== ''): ?>
            <?= $htmlAfterClient ?>
          <?php endif; ?>
        </td>
        <td>
          <?php if ((int)$p['id_good'] > 0): ?>
            <a href="/products/<?= (int)$p['id_good'] ?>/edit"><?= e((string)($p['product_name'] ?: ('#' . (int)$p['id_good']))) ?></a>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td>
          <?= e($summ) ?>
          <?php if ($summDefault !== $summ): ?>
            <span class="muted">(<?= e($summDefault) ?>)</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!empty($p['enabled'])): ?>
            <b style="color:green"><?= te('procedures.list.enabled_on') ?></b>
          <?php else: ?>
            <b style="color:red"><?= te('procedures.list.enabled_off') ?></b>
          <?php endif; ?>
          <?php if (!empty($p['restored'])): ?>
            <br><span class="pill"><?= te('procedures.col.restored') ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('procedures.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
