<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $idPoll */
/** @var string $progress */
/** @var array<int,string> $pollNames */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/poll-invites';
$listId = 'poll_invites';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 8 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/poll-invites/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('poll_invites.list.search_placeholder') ?>" data-filter-debounce>
  <label><?= te('poll_invites.list.filter_poll') ?>
    <select name="id_poll">
      <option value="0"><?= te('poll_invites.list.progress_all') ?></option>
      <?php foreach ($pollNames as $pid => $pname): ?>
        <option value="<?= (int)$pid ?>"<?= $idPoll === (int)$pid ? ' selected' : '' ?>><?= e($pname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('poll_invites.list.filter_progress') ?>
    <select name="progress">
      <option value=""<?= $progress === '' ? ' selected' : '' ?>><?= te('poll_invites.list.progress_all') ?></option>
      <option value="open"<?= $progress === 'open' ? ' selected' : '' ?>><?= te('poll_invites.list.progress_open') ?></option>
      <option value="done"<?= $progress === 'done' ? ' selected' : '' ?>><?= te('poll_invites.list.progress_done') ?></option>
      <option value="expired"<?= $progress === 'expired' ? ' selected' : '' ?>><?= te('poll_invites.list.progress_expired') ?></option>
    </select>
  </label>
</form>

<?= $pager ?>

<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <?= ListQuery::sortTh(t('poll_invites.col.id'), 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('poll_invites.col.poll'), 'poll', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('poll_invites.col.client'), 'client', $sort, $dir, $path, $filters) ?>
        <th><?= te('poll_invites.col.code') ?></th>
        <?= ListQuery::sortTh(t('poll_invites.col.created'), 'created', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('poll_invites.col.completed'), 'completed', $sort, $dir, $path, $filters) ?>
        <th><?= te('poll_invites.col.actions') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $u): ?>
      <tr>
        <?= BulkActions::td($listId, (int)$u['id']) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/poll-invites/<?= (int)$u['id'] ?>/edit"><?= (int)$u['id'] ?></a>
          <?php else: ?>
            <?= (int)$u['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= e((string)(($u['poll_name'] ?? '') !== '' ? $u['poll_name'] : ('#' . $u['id_poll']))) ?></td>
        <td>
          <?php if (ModuleManager::isEnabled('clients') && (int)$u['id_user'] > 0): ?>
            <a href="/clients/<?= (int)$u['id_user'] ?>"><?= (int)$u['id_user'] ?></a>
          <?php else: ?>
            <?= (int)$u['id_user'] ?>
          <?php endif; ?>
        </td>
        <td class="muted"><?= e((string)$u['code']) ?></td>
        <td><?= e(PollInvitesRepository::dtDisplay((int)$u['dt_create'])) ?></td>
        <td><?= e(PollInvitesRepository::dtDisplay((int)$u['dt_completed'])) ?></td>
        <td class="nowrap">
          <?php if ($canEdit): ?>
            <a href="/poll-invites/<?= (int)$u['id'] ?>/fill"><?= te('poll_invites.action.fill') ?></a>
            ·
            <a href="/poll-invites/<?= (int)$u['id'] ?>/edit"><?= te('poll_invites.action.edit') ?></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('poll_invites.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
