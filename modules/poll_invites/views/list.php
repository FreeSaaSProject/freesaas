<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var int $unviewedTotal */
/** @var int $unfilledInFilter */
/** @var string $q */
/** @var int $idPoll */
/** @var string $progress */
/** @var int $idResp */
/** @var array<int,string> $pollNames */
/** @var array<int,string> $staffNames */
/** @var bool $canEdit */
/** @var bool $canDelete */
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
$unviewedTotal = (int)($unviewedTotal ?? 0);
$unfilledInFilter = (int)($unfilledInFilter ?? 0);
$canDelete = !empty($canDelete);
$staffNames = is_array($staffNames ?? null) ? $staffNames : [];
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 10 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
$retPath = ListQuery::url($path, $filters, []);
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
      <?php if ($unviewedTotal > 0): ?>
        <a class="btn-secondary<?= $progress === 'unviewed' ? ' is-active' : '' ?>"
           href="<?= e(ListQuery::url($path, array_merge($filters, ['progress' => 'unviewed', 'page' => '']), [])) ?>">
          <?= te('poll_invites.list.unviewed_chip', ['n' => $unviewedTotal]) ?>
        </a>
      <?php endif; ?>
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
      <option value="unviewed"<?= $progress === 'unviewed' ? ' selected' : '' ?>><?= te('poll_invites.list.progress_unviewed') ?></option>
      <option value="unfilled"<?= $progress === 'unfilled' ? ' selected' : '' ?>><?= te('poll_invites.list.progress_unfilled') ?></option>
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

<?php if ($canDelete && $unfilledInFilter > 0): ?>
  <p class="muted poll-invites-unfilled-hint">
    <?= te('poll_invites.bulk.filter_hint', ['n' => $unfilledInFilter]) ?>
  </p>
<?php endif; ?>

<?php if ($progress === 'unviewed' && $unviewedTotal > 0): ?>
  <form method="post" action="<?= e($path) ?>" class="poll-invites-mark-all"
        onsubmit="return confirm('<?= e(t('poll_invites.unviewed.mark_all_confirm')) ?>');">
    <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
    <input type="hidden" name="action" value="mark_viewed">
    <input type="hidden" name="mark_all" value="1">
    <input type="hidden" name="ret" value="<?= e($retPath) ?>">
    <button type="submit" class="btn-secondary"><?= te('poll_invites.unviewed.mark_all', ['n' => $unviewedTotal]) ?></button>
  </form>
<?php endif; ?>

<?= $pager ?>

<?php /* Mark-viewed lives outside the bulk form — nested <form> breaks bulk checkboxes. */ ?>
<form id="poll-invites-mark-form" method="post" action="<?= e($path) ?>" class="poll-invites-mark-host" hidden>
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <input type="hidden" name="action" value="mark_viewed">
  <input type="hidden" name="ret" value="<?= e($retPath) ?>">
</form>

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
        $unviewed = !empty($u['unviewed_for_me']);
      ?>
      <tr class="<?= $unviewed ? 'poll-invites-row-unviewed' : '' ?>">
        <?= BulkActions::td($listId, (int)$u['id']) ?>
        <td class="muted">
          <?php if ($unviewed): ?>
            <span class="poll-invites-new-mark" title="<?= te('poll_invites.list.unviewed_badge') ?>"><?= te('poll_invites.list.unviewed_badge') ?></span>
          <?php endif; ?>
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
          <?php if ($unviewed): ?>
            <button type="submit" form="poll-invites-mark-form" class="btn-secondary btn-small"
                    name="id" value="<?= (int)$u['id'] ?>"><?= te('poll_invites.unviewed.mark') ?></button>
          <?php endif; ?>
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
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted">
        <?= $progress === 'unviewed'
          ? te('poll_invites.unviewed.empty')
          : ($progress === 'unfilled'
            ? te('poll_invites.list.empty_unfilled')
            : te('poll_invites.list.empty')) ?>
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>

<?php if ($canDelete): ?>
<?= View::capture('_longjob_panel', [
  'longjobId' => 'poll-invites-job',
  'longjobTitle' => t('poll_invites.bulk.delete_unfilled'),
  'longjobDualBars' => false,
  'longjobShowBytes' => false,
], 'core') ?>
<?php
$longjobPending = $longjobPending ?? null;
if (is_array($longjobPending)):
?>
<script>
(function () {
  var filters = <?= json_encode($longjobPending['filters'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
  function boot() {
    var overlay = document.getElementById('poll-invites-job-overlay');
    if (!overlay) return;
    if (!window.LongJobPanel) {
      setTimeout(boot, 30);
      return;
    }
    var panel = LongJobPanel.mount({
      root: overlay,
      startUrl: '/poll-invites/longjob',
      tickUrl: '/poll-invites/longjob',
      cancelUrl: '/poll-invites/longjob',
      dualBars: false,
      showBytes: false,
      csrf: <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>,
      i18n: {
        title: <?= json_encode(t('poll_invites.bulk.delete_unfilled'), JSON_UNESCAPED_UNICODE) ?>,
        starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
        badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
        eta: <?= json_encode(t('core.longjob.eta'), JSON_UNESCAPED_UNICODE) ?>,
        doneTitle: <?= json_encode(t('core.longjob.done_title'), JSON_UNESCAPED_UNICODE) ?>,
        errorTitle: <?= json_encode(t('core.longjob.error_title'), JSON_UNESCAPED_UNICODE) ?>,
        cancelledTitle: <?= json_encode(t('core.longjob.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>
      }
    });
    var fd = new FormData();
    fd.set('filters_json', JSON.stringify(filters));
    panel.runFormData(fd, {});
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>
<?php endif; ?>
<?php endif; ?>
