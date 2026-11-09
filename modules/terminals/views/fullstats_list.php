<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $day */
/** @var string $from */
/** @var string $to */
/** @var string $mark */
/** @var string $q */
/** @var bool $canEdit */
/** @var bool $canSync */
/** @var string $loadFrom */
/** @var string $loadTo */
/** @var bool $syncEnabled */
/** @var bool $onecLive */
/** @var int $oasisBackfillRemaining */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/terminals/fullstats';
$showAll = !empty($showAll);
$canSync = !empty($canSync);
$syncEnabled = !empty($syncEnabled);
$onecLive = !empty($onecLive);
$loadFrom = (string)($loadFrom ?? date('Y-m-d'));
$loadTo = (string)($loadTo ?? $loadFrom);
$mark = (string)($mark ?? '');
$q = (string)($q ?? '');
$canEdit = !empty($canEdit);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);

$day = (string)$day;
$dayNavActive = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $day);
$today = date('Y-m-d');
$isToday = ($dayNavActive && $day === $today);
$urlToday = ListQuery::url($path, $filters, ['day' => $today, 'from' => '', 'to' => '', 'page' => 1]);
$urlPrev = '';
$urlNext = '';
$dayLabel = '';
if ($dayNavActive) {
    $focus = $day;
    $prevDay = date('Y-m-d', strtotime($focus . ' -1 day') ?: time());
    $nextDay = date('Y-m-d', strtotime($focus . ' +1 day') ?: time());
    $urlPrev = ListQuery::url($path, $filters, ['day' => $prevDay, 'from' => '', 'to' => '', 'page' => 1]);
    $urlNext = ListQuery::url($path, $filters, ['day' => $nextDay, 'from' => '', 'to' => '', 'page' => 1]);
    $dayLabel = I18n::formatDate(strtotime($focus . ' 12:00:00') ?: time(), 'date');
}
$loadReady = $canSync && $syncEnabled && $onecLive;
$oasisBackfillRemaining = (int)($oasisBackfillRemaining ?? 0);
?>
<section class="page-head">
  <div class="page-actions" style="gap:0.5rem;align-items:center;flex-wrap:wrap">
    <?php if ($dayNavActive): ?>
      <nav class="pager" aria-label="<?= te('terminals.day_nav.aria') ?>"
           data-pager-prev="<?= e($urlPrev) ?>"
           data-pager-next="<?= e($urlNext) ?>">
        <a class="btn-secondary" href="<?= e($urlPrev) ?>" title="<?= te('terminals.day_nav.prev') ?>" aria-label="<?= te('terminals.day_nav.prev') ?>">‹</a>
        <strong><?= e($dayLabel) ?></strong>
        <a class="btn-secondary" href="<?= e($urlNext) ?>" title="<?= te('terminals.day_nav.next') ?>" aria-label="<?= te('terminals.day_nav.next') ?>">›</a>
        <?php if (!$isToday): ?>
          <a class="btn-secondary" href="<?= e($urlToday) ?>"><?= te('terminals.day_nav.today') ?></a>
        <?php else: ?>
          <span class="pill"><?= te('terminals.day_nav.today') ?></span>
        <?php endif; ?>
        <span class="muted"><?= te('terminals.day_nav.keyboard') ?></span>
      </nav>
    <?php else: ?>
      <a class="btn-secondary" href="<?= e($urlToday) ?>"><?= te('terminals.day_nav.today') ?></a>
    <?php endif; ?>
    <?php if ($canEdit): ?>
      <a class="btn-secondary" href="/terminals/fullstats/classify"><?= te('terminals.fullstats.classify.btn') ?></a>
    <?php endif; ?>
    <?php if ($canSync): ?>
      <button type="button" class="btn-primary" id="terminals-tickets-load-toggle"
              aria-expanded="false" aria-controls="terminals-tickets-load-panel">
        <?= te('terminals.fullstats.load.btn') ?>
      </button>
    <?php endif; ?>
  </div>
</section>

<?php if ($canSync): ?>
<section id="terminals-tickets-load-panel" hidden style="margin:1rem 0">
  <h2><?= te('terminals.fullstats.load.title') ?></h2>
  <p class="muted"><?= te('terminals.fullstats.load.hint') ?></p>
  <?php if (!$syncEnabled): ?>
    <p class="muted"><?= te('terminals.fullstats.load.err_sync') ?></p>
  <?php elseif (!$onecLive): ?>
    <p class="muted"><?= te('terminals.fullstats.load.err_onec') ?></p>
  <?php endif; ?>
  <form method="post" class="filters" data-terminals-tickets-load style="align-items:flex-end">
    <label><?= te('terminals.fullstats.from') ?>
      <input type="date" name="from" value="<?= e($loadFrom) ?>" required>
    </label>
    <label><?= te('terminals.fullstats.to') ?>
      <input type="date" name="to" value="<?= e($loadTo) ?>" required>
    </label>
    <button type="submit" class="btn-primary"<?= $loadReady ? '' : ' disabled' ?>><?= te('terminals.fullstats.load.btn') ?></button>
  </form>
</section>
<?= View::capture('_longjob_panel', [
  'longjobId' => 'terminals-tickets-import',
  'longjobTitle' => t('terminals.fullstats.load.title'),
  'longjobDualBars' => false,
  'longjobShowBytes' => false,
], 'core') ?>
<script>
(function () {
  var toggle = document.getElementById('terminals-tickets-load-toggle');
  var panel = document.getElementById('terminals-tickets-load-panel');
  if (toggle && panel) {
    toggle.addEventListener('click', function () {
      var open = panel.hasAttribute('hidden');
      if (open) panel.removeAttribute('hidden');
      else panel.setAttribute('hidden', '');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }
  function boot() {
    var form = document.querySelector('[data-terminals-tickets-load]');
    var overlay = document.getElementById('terminals-tickets-import-overlay');
    if (!form || !overlay) return;
    if (!window.LongJobPanel) { setTimeout(boot, 30); return; }
    if (overlay.getAttribute('data-bound') === '1') return;
    overlay.setAttribute('data-bound', '1');
    var lj = LongJobPanel.mount({
      root: overlay,
      startUrl: '/terminals/fullstats/longjob',
      tickUrl: '/terminals/fullstats/longjob',
      cancelUrl: '/terminals/fullstats/longjob',
      dualBars: false,
      showBytes: false,
      reloadOnOk: true,
      csrf: <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>,
      i18n: {
        title: <?= json_encode(t('terminals.fullstats.load.title'), JSON_UNESCAPED_UNICODE) ?>,
        starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
        badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
        doneTitle: <?= json_encode(t('core.longjob.done_title'), JSON_UNESCAPED_UNICODE) ?>,
        errorTitle: <?= json_encode(t('core.longjob.error_title'), JSON_UNESCAPED_UNICODE) ?>,
        cancelledTitle: <?= json_encode(t('core.longjob.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>,
        ok: <?= json_encode(t('core.longjob.ok'), JSON_UNESCAPED_UNICODE) ?>
      }
    });
    form.addEventListener('submit', function (ev) {
      var btn = form.querySelector('button[type="submit"]');
      if (btn && btn.disabled) return;
      ev.preventDefault();
      lj.runFormData(new FormData(form), {
        title: <?= json_encode(t('terminals.fullstats.load.title'), JSON_UNESCAPED_UNICODE) ?>
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
<?php endif; ?>

<?php if ($canEdit && $oasisBackfillRemaining > 0): ?>
<section style="margin:1rem 0">
  <h2><?= te('terminals.oasis_backfill.title') ?></h2>
  <p class="muted"><?= te('terminals.oasis_backfill.hint', ['n' => $oasisBackfillRemaining]) ?></p>
  <form method="post" data-terminals-oasis-backfill>
    <button type="submit" class="btn-secondary"><?= te('terminals.oasis_backfill.btn') ?></button>
  </form>
</section>
<?= View::capture('_longjob_panel', [
  'longjobId' => 'terminals-oasis-backfill',
  'longjobTitle' => t('terminals.oasis_backfill.title'),
  'longjobDualBars' => false,
  'longjobShowBytes' => false,
], 'core') ?>
<script>
(function () {
  function boot() {
    var form = document.querySelector('[data-terminals-oasis-backfill]');
    var overlay = document.getElementById('terminals-oasis-backfill-overlay');
    if (!form || !overlay) return;
    if (!window.LongJobPanel) { setTimeout(boot, 30); return; }
    if (overlay.getAttribute('data-bound') === '1') return;
    overlay.setAttribute('data-bound', '1');
    var lj = LongJobPanel.mount({
      root: overlay,
      startUrl: '/terminals/fullstats/oasis-backfill',
      tickUrl: '/terminals/fullstats/oasis-backfill',
      cancelUrl: '/terminals/fullstats/oasis-backfill',
      dualBars: false,
      showBytes: false,
      reloadOnOk: true,
      csrf: <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>,
      i18n: {
        title: <?= json_encode(t('terminals.oasis_backfill.title'), JSON_UNESCAPED_UNICODE) ?>,
        starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
        badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
        doneTitle: <?= json_encode(t('core.longjob.done_title'), JSON_UNESCAPED_UNICODE) ?>,
        errorTitle: <?= json_encode(t('core.longjob.error_title'), JSON_UNESCAPED_UNICODE) ?>,
        cancelledTitle: <?= json_encode(t('core.longjob.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>,
        ok: <?= json_encode(t('core.longjob.ok'), JSON_UNESCAPED_UNICODE) ?>
      }
    });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      lj.runFormData(new FormData(form), {
        title: <?= json_encode(t('terminals.oasis_backfill.title'), JSON_UNESCAPED_UNICODE) ?>
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
<?php endif; ?>


<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <label><?= te('terminals.fullstats.search_day') ?>
    <input type="date" name="day" value="<?= e($day) ?>">
  </label>
  <label><?= te('terminals.fullstats.from') ?>
    <input type="date" name="from" value="<?= e($from) ?>">
  </label>
  <label><?= te('terminals.fullstats.to') ?>
    <input type="date" name="to" value="<?= e($to) ?>">
  </label>
  <label><?= te('terminals.fullstats.mark') ?>
    <select name="mark">
      <option value=""<?= $mark === '' ? ' selected' : '' ?>><?= te('terminals.fullstats.mark.all') ?></option>
      <option value="unclassified"<?= $mark === 'unclassified' ? ' selected' : '' ?>><?= te('terminals.fullstats.mark.unclassified') ?></option>
      <option value="classified"<?= $mark === 'classified' ? ' selected' : '' ?>><?= te('terminals.fullstats.mark.classified') ?></option>
    </select>
  </label>
  <label><?= te('terminals.fullstats.search_q') ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('terminals.fullstats.search_q_ph') ?>">
  </label>
</form>

<?= $pager ?>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('terminals.fullstats.col.dtstart'), 'dtstart', $sort, $dir, $path, $filters) ?>
        <th><?= te('terminals.fullstats.col.title') ?></th>
        <?= ListQuery::sortTh(t('terminals.fullstats.col.ticket_type'), 'ticket_type', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('terminals.fullstats.col.id_type'), 'id_type', $sort, $dir, $path, $filters) ?>
        <th><?= te('terminals.fullstats.col.is_limited') ?></th>
        <th><?= te('terminals.fullstats.col.is_payer') ?></th>
        <th><?= te('terminals.fullstats.col.is_oasis') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $u): ?>
      <tr>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/terminals/fullstats/<?= (int)$u['id'] ?>/edit"><?= (int)$u['id'] ?></a>
          <?php else: ?>
            <?= (int)$u['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= (int)$u['dtstart'] > 0 ? e(date('Y-m-d H:i', (int)$u['dtstart'])) : '—' ?></td>
        <td><?= e((string)$u['title_full']) ?></td>
        <td><?= e((string)$u['ticket_type']) ?></td>
        <td><?= e(TicketTypeEnums::labelIdType((int)$u['id_type'])) ?></td>
        <td><?= e(TicketTypeEnums::labelIsLimited((int)$u['is_limited'])) ?></td>
        <td><?= e(TicketTypeEnums::labelIsPayer((int)$u['is_payer'])) ?></td>
        <td><?= e(TicketTypeEnums::labelIsOasis((int)($u['is_oasis'] ?? 0))) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="8" class="muted"><?= te('terminals.fullstats.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?= $pager ?>
