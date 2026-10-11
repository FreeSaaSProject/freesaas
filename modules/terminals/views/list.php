<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $enabled */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
/** @var array<int,string> $staffNames */
$path = '/terminals';
$listId = 'terminals';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 8 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
$staffNames = $staffNames ?? [];
$selOn = '<span class="term-sel-on">' . e(t('terminals.list.sel_on')) . '</span>';
$selOff = '<span class="term-sel-off">' . e(t('terminals.list.sel_off')) . '</span>';
$flagYes = static function (array $u, string $key): bool {
    return !empty($u[$key]);
};
$staffLabel = static function (int $id) use ($staffNames): string {
    if ($id < 1) {
        return '';
    }
    return $staffNames[$id] ?? ('#' . $id);
};
$fmtUntil = static function (int $ts): string {
    if ($ts < 1) {
        return '';
    }
    return date('d.m.Y H:i', $ts);
};
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/terminals/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('terminals.list.search_placeholder') ?>" data-filter-debounce>
  <label><?= te('terminals.list.filter.enabled') ?>
    <select name="enabled">
      <option value=""><?= te('terminals.list.filter.all') ?></option>
      <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('terminals.list.filter.on') ?></option>
      <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('terminals.list.filter.off') ?></option>
    </select>
  </label>
</form>

<?= $pager ?>

<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap">
  <table class="data terminals-list">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('terminals.col.dt_create'), 'dt_create', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('terminals.col.name'), 'name', $sort, $dir, $path, $filters) ?>
        <th><?= te('terminals.col.code') ?></th>
        <th><?= te('terminals.col.id_admin') ?></th>
        <th><?= te('terminals.col.id_executor') ?></th>
        <?= ListQuery::sortTh(t('terminals.col.enabled'), 'enabled', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $u):
        $limits = [];
        if (!empty($u['id_user_locked'])) {
            $limits[] = t('terminals.list.limit.lock', [
                'name' => $staffLabel((int)$u['id_user_locked']),
            ]);
        }
        if ($flagYes($u, 'is_admin_reg_ignore')) {
            $limits[] = t('terminals.list.limit.reg_ignore');
        }
        if ($flagYes($u, 'masseur_mode')) {
            $limits[] = t('terminals.list.limit.masseur');
        }
        if ($flagYes($u, 'is_term_admin_mode')) {
            $limits[] = t('terminals.list.limit.term_admin');
        }
        if (trim((string)($u['executors'] ?? '')) !== '') {
            $limits[] = t('terminals.list.limit.executors', ['ids' => (string)$u['executors']]);
        }
        if (trim((string)($u['goods'] ?? '')) !== '') {
            $limits[] = t('terminals.list.limit.goods', ['ids' => (string)$u['goods']]);
        }
        $adminId = (int)($u['id_admin'] ?? 0);
        $execId = (int)($u['id_executor'] ?? 0);
        $adminUntil = $fmtUntil((int)($u['dt_admin_valid'] ?? 0));
        $execUntil = $fmtUntil((int)($u['dt_executor_free'] ?? 0));
        $created = (int)($u['dt_create'] ?? 0);
    ?>
      <tr>
        <?= BulkActions::td($listId, (int)$u['id']) ?>
        <td class="muted">
          <a href="/terminals/<?= (int)$u['id'] ?>/edit"><?= (int)$u['id'] ?></a>
        </td>
        <td class="muted"><?= $created > 0 ? e(date('d.m.Y H:i', $created)) : '—' ?></td>
        <td>
          <a href="/terminals/<?= (int)$u['id'] ?>/edit"><?= e((string)$u['name']) ?></a>
          <?php if ($limits): ?>
          <div class="term-limits">
            <strong><?= te('terminals.list.limits_title') ?></strong>
            <ul>
              <?php foreach ($limits as $line): ?>
              <li><?= e($line) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>
          <div class="term-selectors">
            <?= te('terminals.list.selectors') ?>
            <?= te('terminals.list.sel.notify') ?>:<?= $flagYes($u, 'is_employee_procedure_notify') ? $selOn : $selOff ?>;
            <?= te('terminals.list.sel.num') ?>:<?= $flagYes($u, 'is_bracelet_num_prompt') ? $selOn : $selOff ?>;
            <?= te('terminals.list.sel.review') ?>:<?= $flagYes($u, 'is_terminal_review_invite') ? $selOn : $selOff ?>;
            <?= te('terminals.list.sel.show_day') ?>:<?= $flagYes($u, 'is_terminal_show_day') ? $selOn : $selOff ?>;
            <?= te('terminals.list.sel.flyer') ?>:<?= $flagYes($u, 'is_terminal_flyer_discount') ? $selOn : $selOff ?>;
            <?= te('terminals.list.sel.history') ?>:<?= $flagYes($u, 'is_terminal_client_history') ? $selOn : $selOff ?>
          </div>
        </td>
        <td><code><?= e((string)($u['code'] ?? '')) ?></code></td>
        <td class="muted">
          <?php if ($adminId > 0): ?>
            <?= e($staffLabel($adminId)) ?>
            <?php if ($adminUntil !== ''): ?><br><span class="term-until"><?= te('terminals.list.until', ['dt' => $adminUntil]) ?></span><?php endif; ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td class="muted">
          <?php if ($execId > 0): ?>
            <?= e($staffLabel($execId)) ?>
            <?php if ($execUntil !== ''): ?><br><span class="term-until"><?= te('terminals.list.until', ['dt' => $execUntil]) ?></span><?php endif; ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td><?= !empty($u['enabled'])
            ? '<span class="term-sel-on">' . e(t('terminals.list.filter.on')) . '</span>'
            : '<span class="term-sel-off">' . e(t('terminals.list.filter.off')) . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('terminals.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
