<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $idPoll */
/** @var string $progress */
/** @var int $idResp */
/** @var array<int,string> $pollNames */
/** @var array<int,string> $staffNames */
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
$idResp = (int)($idResp ?? 0);
$staffNames = is_array($staffNames ?? null) ? $staffNames : [];
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 10 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
$staffBadge = static function (int $id): string {
    if ($id < 1) {
        return '—';
    }
    if (class_exists('UsersRepository', false)) {
        return UsersRepository::badgeHtml($id, true, true);
    }
    return e('#' . $id);
};
$procLabel = static function (array $u): string {
    $procId = (int)($u['id_proc'] ?? 0);
    if ($procId < 1) {
        return '—';
    }
    $start = (int)($u['proc_dtstart'] ?? 0);
    $end = (int)($u['proc_dtend'] ?? 0);
    $product = trim((string)($u['proc_product_name'] ?? ''));
    $parts = ['#' . $procId];
    if ($start > 0) {
        $when = PollInvitesRepository::dtDisplay($start);
        if ($end > $start) {
            $when .= '–' . date('H:i', $end);
        }
        $parts[] = $when;
    }
    if ($product !== '') {
        $parts[] = $product;
    }
    return implode(' · ', $parts);
};
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <a class="btn-secondary" href="/poll-invites/unviewed"><?= te('poll_invites.menu.unviewed') ?></a>
    </div>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-primary" href="/poll-invites/new"><?= te('core.common.add') ?></a>
    </div>
    <?php endif; ?>
  </div>
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
  <label><?= te('poll_invites.list.filter_resp') ?>
    <select name="id_resp">
      <option value="0"><?= te('poll_invites.list.progress_all') ?></option>
      <?php foreach ($staffNames as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= $idResp === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
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
        <th><?= te('poll_invites.col.proc') ?></th>
        <th><?= te('poll_invites.col.staff') ?></th>
        <th><?= te('poll_invites.col.score') ?></th>
        <th><?= te('poll_invites.col.transcript') ?></th>
        <th><?= te('poll_invites.col.dates') ?></th>
        <th><?= te('poll_invites.col.actions') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $u): ?>
      <?php
        $opId = (int)($u['id_proc_operator'] ?? 0);
        $admId = (int)($u['id_proc_admin'] ?? 0);
        $score = $u['score'] ?? null;
        $procId = (int)($u['id_proc'] ?? 0);
        $askedAt = (int)($u['dt_create'] ?? 0);
        $doneAt = (int)($u['dt_completed'] ?? 0);
        $transcript = trim((string)($u['transcript'] ?? ''));
      ?>
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
        <td>
          <?php if ($procId > 0 && ModuleManager::isEnabled('procedures')): ?>
            <a href="/procedures/<?= $procId ?>"><?= e($procLabel($u)) ?></a>
          <?php else: ?>
            <?= e($procLabel($u)) ?>
          <?php endif; ?>
        </td>
        <td class="poll-invites-stack">
          <div><?= $staffBadge($opId) ?></div>
          <div><?= $staffBadge($admId) ?></div>
        </td>
        <td class="poll-invites-score"><?= $score !== null && (int)$score >= 1 ? (int)$score : '—' ?></td>
        <td class="poll-invites-transcript">
          <?= $transcript !== '' ? e_nl($transcript) : '—' ?>
        </td>
        <td class="poll-invites-stack">
          <div><?= e(PollInvitesRepository::dtDisplay($askedAt)) ?></div>
          <div class="muted"><?= e(PollInvitesRepository::dtDisplay($doneAt)) ?></div>
        </td>
        <td class="nowrap">
          <?php if ($canEdit): ?>
            <?php if ($doneAt < 1): ?>
              <a href="/poll-invites/<?= (int)$u['id'] ?>/fill"><?= te('poll_invites.action.fill') ?></a>
              ·
            <?php endif; ?>
            <a href="/poll-invites/<?= (int)$u['id'] ?>/edit"><?= te('poll_invites.action.edit') ?></a>
          <?php endif; ?>
          <?php
            $recordIds = is_array($u['speech_record_ids'] ?? null) ? $u['speech_record_ids'] : [];
            if ($canEdit && ModuleManager::isEnabled('audio') && $recordIds !== []):
              foreach ($recordIds as $rid):
          ?>
            · <a href="/audio/<?= (int)$rid ?>" target="_blank" rel="noopener"><?= te('poll_invites.action.audio_record', ['id' => (int)$rid]) ?></a>
          <?php
              endforeach;
            endif;
          ?>
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
