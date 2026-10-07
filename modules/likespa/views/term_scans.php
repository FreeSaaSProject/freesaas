<?php
declare(strict_types=1);
/** @var array $stats */
/** @var string $periodLabel */
/** @var int $year */
/** @var int $month */
/** @var int $day */
/** @var bool $isDayView */
/** @var int $idAdmin */
/** @var int $windowHours */
/** @var int $gapMin */
/** @var array<int,string> $staffOptions */
/** @var string $prevHref */
/** @var string $nextHref */
/** @var string $todayHref */
/** @var string $monthHref */
/** @var string $filterAction */
/** @var list<array<string,mixed>> $items */
/** @var array<int,array<string,mixed>> $enrich */
/** @var int $detailTotal */
/** @var string $detailPager */
/** @var int $pageOffset */

$admins = is_array($stats['admins'] ?? null) ? $stats['admins'] : [];
$totals = is_array($stats['totals'] ?? null) ? $stats['totals'] : [];
$isDayView = !empty($isDayView);
$pagerAttrs = '';
if ($prevHref !== '') {
    $pagerAttrs .= ' data-pager-prev="' . e($prevHref) . '"';
}
if ($nextHref !== '') {
    $pagerAttrs .= ' data-pager-next="' . e($nextHref) . '"';
}
?>
<section class="page-head">
  <h1><?= te('likespa.term_scans.title') ?></h1>
</section>

<nav class="pager likespa-term-scans-period" aria-label="<?= te('likespa.term_scans.nav_aria') ?>"<?= $pagerAttrs ?>>
  <div class="pager-row">
    <div class="pager-nav">
      <?php if ($prevHref !== ''): ?>
        <a class="pager-chip pager-arrow" href="<?= e($prevHref) ?>">‹</a>
      <?php else: ?>
        <span class="pager-chip pager-arrow is-disabled">‹</span>
      <?php endif; ?>
      <span class="pager-chip is-current"><?= e($periodLabel) ?></span>
      <?php if ($nextHref !== ''): ?>
        <a class="pager-chip pager-arrow" href="<?= e($nextHref) ?>">›</a>
      <?php else: ?>
        <span class="pager-chip pager-arrow is-disabled">›</span>
      <?php endif; ?>
    </div>
    <div class="pager-aside">
      <a class="btn-secondary" href="<?= e($todayHref) ?>"><?= te('likespa.term_scans.today') ?></a>
      <a class="btn-secondary" href="<?= e($monthHref) ?>"><?= te('likespa.term_scans.month') ?></a>
    </div>
  </div>
</nav>

<form class="filters likespa-term-scans-filters" method="get" action="<?= e($filterAction) ?>" data-auto-filter="1">
  <input type="hidden" name="year" value="<?= (int)$year ?>">
  <input type="hidden" name="month" value="<?= (int)$month ?>">
  <input type="hidden" name="day" value="<?= (int)$day ?>">
  <label><?= te('likespa.term_scans.filter.admin') ?>
    <select name="admin" data-auto-submit="1">
      <option value="0"><?= te('likespa.term_scans.filter.all_admins') ?></option>
      <?php foreach ($staffOptions as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= $idAdmin === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('likespa.term_scans.filter.window') ?>
    <input type="number" name="window" min="1" max="24" value="<?= (int)$windowHours ?>" data-filter-change="1">
  </label>
  <?php if ($isDayView): ?>
  <label><?= te('likespa.term_scans.filter.gap') ?>
    <input type="number" name="gap" min="1" max="120" value="<?= (int)$gapMin ?>" data-filter-change="1">
  </label>
  <?php else: ?>
    <input type="hidden" name="gap" value="<?= (int)$gapMin ?>">
  <?php endif; ?>
</form>

<section class="likespa-term-scans-summary">
  <h2><?= te('likespa.term_scans.summary', ['period' => $periodLabel]) ?></h2>
  <p class="muted"><?= te('likespa.term_scans.hint_proc', ['h' => (string)(int)$windowHours]) ?></p>
  <?php if ($isDayView): ?>
    <p class="muted"><?= te('likespa.term_scans.hint_sessions', ['m' => (string)(int)$gapMin]) ?></p>
  <?php endif; ?>

  <?php if ($admins === []): ?>
    <p class="muted"><?= te('likespa.term_scans.empty') ?></p>
  <?php else: ?>
    <table class="data-table likespa-term-scans">
      <thead>
        <tr>
          <th><?= te('likespa.term_scans.col.admin') ?></th>
          <th><?= te('likespa.term_scans.col.scans') ?></th>
          <th><?= te('likespa.term_scans.col.bracelets') ?></th>
          <th><?= te('likespa.term_scans.col.clients') ?></th>
          <th><?= te('likespa.term_scans.col.with_proc') ?></th>
          <?php if ($isDayView): ?>
            <th><?= te('likespa.term_scans.col.sessions') ?></th>
          <?php endif; ?>
          <th><?= te('likespa.term_scans.col.promoter_scans') ?></th>
          <th><?= te('likespa.term_scans.col.promoter_clients') ?></th>
          <th><?= te('likespa.term_scans.col.discounts') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($admins as $aid => $row):
            $clients = (int)($row['unique_clients'] ?? 0);
            $withProc = (int)($row['unique_clients_with_procedures'] ?? 0);
            $pct = $clients > 0 ? round($withProc * 100 / $clients, 1) : 0;
            $sess = $isDayView
                ? TermScansRepository::formatSessionGroups(
                    is_array($row['session_group_sizes'] ?? null) ? $row['session_group_sizes'] : []
                )
                : null;
            ?>
          <tr>
            <td><?= UsersRepository::badgeHtml((int)$aid, true, true) ?></td>
            <td><?= (int)($row['scans'] ?? 0) ?></td>
            <td><?= (int)($row['unique_bracelets'] ?? 0) ?></td>
            <td><?= $clients ?></td>
            <td class="likespa-term-scans-proc"><?= $withProc ?> <span class="muted">(<?= e((string)$pct) ?>%)</span></td>
            <?php if ($isDayView && is_array($sess)): ?>
              <td class="likespa-term-scans-sessions">
                <strong><?= (int)($row['session_groups'] ?? 0) ?></strong>
                <?php if (($sess['text'] ?? '—') !== '—'): ?>
                  <div class="muted"><?= e((string)$sess['text']) ?></div>
                <?php endif; ?>
                <?php if (($sess['histogram'] ?? '') !== ''): ?>
                  <div class="muted likespa-term-scans-hist"><?= e((string)$sess['histogram']) ?></div>
                <?php endif; ?>
              </td>
            <?php endif; ?>
            <td><?= (int)($row['promoter_scans'] ?? 0) ?></td>
            <td><?= (int)($row['promoter_unique_clients'] ?? 0) ?></td>
            <td><?= (int)($row['discount_activations'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php
          $tClients = (int)($totals['unique_clients'] ?? 0);
          $tWith = (int)($totals['unique_clients_with_procedures'] ?? 0);
          $tPct = $tClients > 0 ? round($tWith * 100 / $tClients, 1) : 0;
          $tSess = $isDayView
              ? TermScansRepository::formatSessionGroups(
                  is_array($totals['session_group_sizes'] ?? null) ? $totals['session_group_sizes'] : []
              )
              : null;
        ?>
        <tr class="likespa-term-scans-total">
          <td><strong><?= te('likespa.term_scans.totals') ?></strong></td>
          <td><?= (int)($totals['scans'] ?? 0) ?></td>
          <td><?= (int)($totals['unique_bracelets'] ?? 0) ?></td>
          <td><?= $tClients ?></td>
          <td class="likespa-term-scans-proc"><?= $tWith ?> <span class="muted">(<?= e((string)$tPct) ?>%)</span></td>
          <?php if ($isDayView && is_array($tSess)): ?>
            <td class="likespa-term-scans-sessions">
              <strong><?= (int)($totals['session_groups'] ?? 0) ?></strong>
              <?php if (($tSess['text'] ?? '—') !== '—'): ?>
                <div class="muted"><?= e((string)$tSess['text']) ?></div>
              <?php endif; ?>
              <?php if (($tSess['histogram'] ?? '') !== ''): ?>
                <div class="muted likespa-term-scans-hist"><?= e((string)$tSess['histogram']) ?></div>
              <?php endif; ?>
            </td>
          <?php endif; ?>
          <td><?= (int)($totals['promoter_scans'] ?? 0) ?></td>
          <td><?= (int)($totals['promoter_unique_clients'] ?? 0) ?></td>
          <td><?= (int)($totals['discount_activations'] ?? 0) ?></td>
        </tr>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="likespa-term-scans-detail">
  <h2><?= te('likespa.term_scans.detail_title', ['n' => (string)count($items)]) ?></h2>
  <p class="muted"><?= te('likespa.term_scans.detail_hint', ['h' => (string)(int)$windowHours]) ?></p>
  <?= $detailPager ?>
  <?php if ($items === []): ?>
    <p class="muted"><?= te('likespa.term_scans.empty') ?></p>
  <?php else: ?>
    <table class="data-table likespa-term-scans-journal">
      <thead>
        <tr>
          <th>#</th>
          <th><?= te('likespa.term_scans.col.dt') ?></th>
          <th><?= te('likespa.term_scans.col.admin') ?></th>
          <th><?= te('likespa.term_scans.col.bracelet') ?></th>
          <th><?= te('likespa.term_scans.col.client') ?></th>
          <th><?= te('likespa.term_scans.col.phone') ?></th>
          <th><?= te('likespa.term_scans.col.procs') ?></th>
          <th><?= te('likespa.term_scans.col.terminal') ?></th>
          <th><?= te('likespa.term_scans.col.promo_flags') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $i => $row):
            $scanId = (int)($row['id'] ?? 0);
            $info = is_array($enrich[$scanId] ?? null) ? $enrich[$scanId] : [];
            $enrichUser = (int)($info['id_user'] ?? 0);
            $enrichName = trim((string)($info['name'] ?? ''));
            $enrichTel = trim((string)($info['tel'] ?? ''));
            $enrichSource = (string)($info['source'] ?? '');
            $procCount = (int)($info['proc_count'] ?? 0);
            $procDt = (int)($info['proc_dt'] ?? 0);
            $dtScan = (int)($row['dt_scan'] ?? 0);
            $id1c = trim((string)($row['id_1c'] ?? ''));
            $phoneScan = trim((string)($row['phone'] ?? ''));
            $phone1c = trim((string)($row['phone_1c'] ?? ''));
            ?>
          <tr>
            <td><?= (int)$pageOffset + (int)$i + 1 ?></td>
            <td><?= $dtScan > 0 ? e(date('d.m.Y H:i:s', $dtScan)) : '—' ?></td>
            <td><?= UsersRepository::badgeHtml((int)($row['id_admin'] ?? 0), true, true) ?></td>
            <td><code><?= e((string)($row['bracelet_uid'] ?? '')) ?></code></td>
            <td>
              <?php
                $clientBits = '';
                if ($enrichUser > 1) {
                    $clientBits = '<strong>#' . (int)$enrichUser . '</strong>';
                    if ($enrichName !== '') {
                        $clientBits .= ' ' . e($enrichName);
                    }
                    if (ModuleManager::isEnabled('clients') && Auth::can('clients.view')) {
                        $clientBits = '<a href="/clients/' . $enrichUser . '">' . $clientBits . '</a>';
                    }
                } elseif ((int)($row['id_user'] ?? 0) > 0) {
                    $clientBits = '#' . (int)$row['id_user'];
                }
                if ($enrichSource === 'procedure' || $enrichSource === 'scan+procedure') {
                    $clientBits .= ' <span class="likespa-src likespa-src--proc" title="'
                        . e(t('likespa.term_scans.src.procedure')) . '">'
                        . te('likespa.term_scans.src.procedure_short') . '</span>';
                } elseif ($enrichSource === 'shopusers') {
                    $clientBits .= ' <span class="likespa-src likespa-src--crm" title="'
                        . e(t('likespa.term_scans.src.crm')) . '">'
                        . te('likespa.term_scans.src.crm_short') . '</span>';
                }
                if ($id1c !== '') {
                    if ($clientBits !== '') {
                        $clientBits .= '<br>';
                    }
                    $clientBits .= '<span class="muted">1C: ' . e($id1c) . '</span>';
                }
                echo $clientBits !== '' ? $clientBits : '<span class="muted">—</span>';
              ?>
            </td>
            <td>
              <?php if ($enrichTel !== '' && $enrichTel !== '0'): ?>
                +<?= e($enrichTel) ?>
              <?php elseif ($phoneScan !== '' && $phoneScan !== '0'): ?>
                +<?= e($phoneScan) ?>
              <?php elseif ($phone1c !== '' && $phone1c !== '0'): ?>
                <span class="muted">1С: +<?= e($phone1c) ?></span>
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($procCount > 0):
                  $df = $dtScan > 0 ? date('Y-m-d', $dtScan) : '';
                  $dt = $dtScan > 0 ? date('Y-m-d', $dtScan + $windowHours * 3600) : '';
                  $procHref = '/procedures?enabled=1';
                  if ($enrichUser > 1) {
                      $procHref .= '&id_user=' . $enrichUser;
                  }
                  if ($df !== '') {
                      $procHref .= '&date_from=' . rawurlencode($df);
                  }
                  if ($dt !== '') {
                      $procHref .= '&date_to=' . rawurlencode($dt);
                  }
                  ?>
                <?php if (ModuleManager::isEnabled('procedures') && Auth::can('procedures.view')): ?>
                  <a href="<?= e($procHref) ?>" title="<?= te('likespa.term_scans.procs_hint', ['h' => (string)(int)$windowHours]) ?>"><?= $procCount ?></a>
                <?php else: ?>
                  <?= $procCount ?>
                <?php endif; ?>
                <?php if ($procDt > 0): ?>
                  <span class="muted"><?= e(date('H:i', $procDt)) ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span class="muted">0</span>
              <?php endif; ?>
            </td>
            <td>
              <?php
                $tid = (int)($row['id_terminal'] ?? 0);
                if ($tid < 1) {
                    echo '<span class="muted">—</span>';
                } elseif (ModuleManager::isEnabled('terminals') && class_exists('TerminalsRepository')) {
                    static $termNames = null;
                    if ($termNames === null) {
                        $termNames = TerminalsRepository::names();
                    }
                    $tName = (string)($termNames[$tid] ?? '');
                    echo $tName !== ''
                        ? e($tName) . ' <span class="muted">#' . $tid . '</span>'
                        : '#' . $tid;
                } else {
                    echo '#' . $tid;
                }
              ?>
            </td>
            <td>
              <?php if (!empty($row['is_promoter'])): ?>
                <span class="likespa-flag likespa-flag--promo"><?= te('likespa.term_scans.flag.promoter') ?></span>
              <?php endif; ?>
              <?php if (!empty($row['discount_activated'])): ?>
                <span class="likespa-flag likespa-flag--discount"><?= te('likespa.term_scans.flag.discount') ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?= $detailPager ?>
  <?php endif; ?>
</section>
