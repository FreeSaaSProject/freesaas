<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $accountId */
/** @var int $typeId */
/** @var int $year */
/** @var int $month */
/** @var int $paymentId */
/** @var int $supplierId */
/** @var int $promise */
/** @var int $bankReview */
/** @var bool $banksFilterAvailable */
/** @var int $bankReviewCount */
/** @var array<int,string> $accounts */
/** @var array<int,string> $types */
/** @var array<int,bool> $typeActIn */
/** @var array<int,bool> $typeSupplier */
/** @var array<int,string> $suppliersAll */
/** @var bool $stickersEnabled */
/** @var array<int,string> $suppliers */
/** @var float|null $balance */
/** @var array<int,float> $runningBalances */
/** @var bool $showBalanceCol */
/** @var bool $showRecon */
/** @var array<string,mixed>|null $reconToday */
/** @var array<int,list<array<string,mixed>>> $recsByMoveId */
/** @var list<array<string,mixed>> $orphanRecs */
/** @var int $firstMoveId */
/** @var string $newHref */
/** @var bool $canEdit */
/** @var bool $canRecon */
/** @var list<int> $years */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
/** @var string $reconError */
/** @var array<int,string> $moveBadges */
/** @var array<int,list<array{id:int,name:string,cnt:int,total:int}>> $supplierSuggestByMove */
/** @var string $listToolbarHtml */
/** @var array{type:string,message:string}|null $pageFlash */
$path = '/ledger/moves';
$listId = 'ledger_moves';
$moveBadges = is_array($moveBadges ?? null) ? $moveBadges : [];
$supplierSuggestByMove = is_array($supplierSuggestByMove ?? null) ? $supplierSuggestByMove : [];
$listToolbarHtml = (string)($listToolbarHtml ?? '');
$pageFlash = is_array($pageFlash ?? null) ? $pageFlash : null;
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$colSpan = 6 + ($showBalanceCol ? 1 : 0) + BulkActions::colSpanExtra($listId);
$sysBalance = $balance !== null ? round((float)$balance, 2) : null;
$todayYmd = date('Y-m-d');
$reconForms = [];
$supplierForms = [];
$typeActIn = is_array($typeActIn ?? null) ? $typeActIn : [];
$typeSupplier = is_array($typeSupplier ?? null) ? $typeSupplier : [];
$suppliersAll = is_array($suppliersAll ?? null) ? $suppliersAll : (is_array($suppliers ?? null) ? $suppliers : []);
$stickersEnabled = !empty($stickersEnabled);
$dateSortKeys = ['dt', 'dt_create', 'dt_update'];
$dateSortLabels = [
    'dt' => t('ledger.moves.date_acc'),
    'dt_create' => t('ledger.moves.date_create'),
    'dt_update' => t('ledger.moves.date_update'),
];
$dateSortTh = static function (string $key, string $label) use ($sort, $dir, $path, $filters): string {
    $isActive = $sort === $key;
    $nextDir = ($isActive && $dir === 'asc') ? 'desc' : 'asc';
    $href = ListQuery::url($path, $filters, [
        'sort' => $key,
        'dir' => $nextDir,
        'page' => 1,
    ]);
    $mark = '';
    if ($isActive) {
        $mark = $dir === 'asc' ? ' ↑' : ' ↓';
    }
    $cls = 'ledger-date-sort' . ($isActive ? ' is-active is-' . $dir : '');
    return '<a class="' . e($cls) . '" href="' . e($href) . '">' . e($label) . e($mark) . '</a>';
};
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="<?= e($newHref) ?>"><?= te('core.common.add') ?></a>
      <a class="btn-secondary" href="/ledger/transfers/new"><?= te('ledger.moves.transfer_new') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<?php if ($pageFlash): ?>
  <div class="flash flash-<?= e(($pageFlash['type'] ?? '') === 'ok' ? 'ok' : 'warn') ?>"><?= e_nl((string)($pageFlash['message'] ?? '')) ?></div>
<?php endif; ?>
<?php if ($reconError !== ''): ?><div class="flash flash-warn"><?= e($reconError) ?></div><?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('ledger.moves.search') ?>" data-filter-debounce>
  <label><?= te('ledger.moves.filter_account') ?>
    <select name="account_id">
      <option value="0"><?= te('ledger.moves.filter_all') ?></option>
      <?php foreach ($accounts as $aid => $aname): ?>
        <option value="<?= (int)$aid ?>"<?= (int)$accountId === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.filter_type') ?>
    <select name="type_id">
      <option value="0"><?= te('ledger.moves.filter_all') ?></option>
      <?php foreach ($types as $tid => $tname): ?>
        <option value="<?= (int)$tid ?>"<?= (int)$typeId === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.filter_year') ?>
    <select name="year">
      <option value="0"><?= te('ledger.moves.filter_all') ?></option>
      <?php foreach ($years as $y): ?>
        <option value="<?= (int)$y ?>"<?= (int)$year === (int)$y ? ' selected' : '' ?>><?= (int)$y ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.filter_month') ?>
    <select name="month">
      <option value="0">—</option>
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>"<?= (int)$month === $m ? ' selected' : '' ?>><?= sprintf('%02d', $m) ?></option>
      <?php endfor; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.filter_payment') ?>
    <input type="text" name="payment_id" inputmode="numeric" pattern="[0-9]*" autocomplete="off"
           value="<?= $paymentId > 0 ? (int)$paymentId : '' ?>">
  </label>
  <?php if ($suppliers): ?>
  <label><?= te('ledger.moves.filter_supplier') ?>
    <select name="supplier_id">
      <option value="0"><?= te('ledger.moves.filter_all') ?></option>
      <?php foreach ($suppliers as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)$supplierId === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>
  <label><?= te('ledger.moves.filter_promise') ?>
    <select name="promise">
      <option value="0"><?= te('ledger.moves.filter_promise_all') ?></option>
      <option value="1"<?= (int)$promise === 1 ? ' selected' : '' ?>><?= te('ledger.moves.filter_promise_open') ?></option>
      <option value="2"<?= (int)$promise === 2 ? ' selected' : '' ?>><?= te('ledger.moves.filter_promise_done') ?></option>
      <option value="3"<?= (int)$promise === 3 ? ' selected' : '' ?>><?= te('ledger.moves.filter_promise_overdue') ?></option>
    </select>
  </label>
  <?php if (!empty($banksFilterAvailable)):
    $bankReviewCount = (int)($bankReviewCount ?? 0);
    $bankReview = (int)($bankReview ?? 0);
    ?>
  <label><?= te('ledger.moves.filter_bank_review') ?>
    <?php if ($bankReviewCount > 0): ?>
    <select name="bank_review">
      <option value="0"><?= te('ledger.moves.filter_bank_review_all') ?></option>
      <option value="1"<?= $bankReview === 1 ? ' selected' : '' ?>>
        <?= e(t('ledger.moves.filter_bank_review_need_n', ['n' => $bankReviewCount])) ?>
      </option>
    </select>
    <?php else: ?>
    <input type="hidden" name="bank_review" value="0">
    <span class="muted ledger-bank-review-empty"><?= te('ledger.moves.filter_bank_review_none') ?></span>
    <?php endif; ?>
  </label>
  <?php endif; ?>
</form>

<?php if ($listToolbarHtml !== ''): ?>
  <?= $listToolbarHtml ?>
<?php endif; ?>

<?php if ($showRecon && $sysBalance !== null): ?>
  <p class="ledger-account-balance">
    <strong><?= e((string)($accounts[$accountId] ?? '')) ?></strong>:
    <?= e(number_format($sysBalance, 2, '.', ' ')) ?>
  </p>
<?php endif; ?>

<?= $pager ?>
<?= BulkActions::beginForm($listId, ListQuery::url($path, $filters, [])) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <th class="ledger-dates-th<?= in_array($sort, $dateSortKeys, true) ? ' is-active' : '' ?>">
    <span class="ledger-dates-th-title"><?= te('ledger.moves.col.dates') ?></span>
    <span class="ledger-dates-th-links">
      <?php foreach ($dateSortKeys as $dsk): ?>
        <?= $dateSortTh($dsk, $dateSortLabels[$dsk]) ?>
      <?php endforeach; ?>
    </span>
  </th>
  <?= ListQuery::sortTh(t('ledger.moves.col.account'), 'account', $sort, $dir, $path, $filters) ?>
  <th><?= te('ledger.moves.col.type') ?></th>
  <th><?= te('ledger.moves.col.descr') ?></th>
  <?= ListQuery::sortTh(t('ledger.moves.col.sum'), 'sum', $sort, $dir, $path, $filters) ?>
  <?php if ($showBalanceCol): ?>
  <th><?= te('ledger.moves.col.balance_after') ?></th>
  <?php endif; ?>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<?php
  $mid = (int)$r['id'];
  $isFirst = $showRecon && $mid === $firstMoveId;
  $rowBalance = $runningBalances[$mid] ?? null;
  $rowRecs = $showRecon ? ($recsByMoveId[$mid] ?? []) : [];
  $dtAcc = (int)($r['dt'] ?? 0);
  $dtCreate = (int)($r['dt_create'] ?? 0);
  $dtUpdate = (int)($r['dt_update'] ?? 0);
  $userPayId = (int)($r['user_payment'] ?? 0);
?>
<tr>
  <?= BulkActions::td($listId, $mid) ?>
  <?php
    $editHref = !empty($r['linked_id'])
        ? '/ledger/transfers/' . $mid . '/edit'
        : '/ledger/moves/' . $mid . '/edit';
  ?>
  <td class="muted"><?php if ($canEdit): ?><a href="<?= e($editHref) ?>"><?= $mid ?></a><?php else: ?><?= $mid ?><?php endif; ?></td>
  <td class="ledger-dates-cell">
    <div class="ledger-date-line ledger-date-acc"><strong><?= te('ledger.moves.date_acc') ?>:</strong>
      <?= $dtAcc > 0 ? e(date('d.m.Y H:i', $dtAcc)) : '—' ?>
    </div>
    <div class="ledger-date-line ledger-date-create"><?= te('ledger.moves.date_create') ?>:
      <?= $dtCreate > 0 ? e(date('d.m.Y H:i', $dtCreate)) : '—' ?>
      <?= UsersRepository::badgeHtml((int)($r['user_create'] ?? 0), true, true) ?>
    </div>
    <?php if ($dtUpdate > 0): ?>
    <div class="ledger-date-line ledger-date-update muted"><?= te('ledger.moves.date_update') ?>:
      <?= e(date('d.m.Y H:i', $dtUpdate)) ?>
      <?= UsersRepository::badgeHtml((int)($r['user_update'] ?? 0), true, true) ?>
    </div>
    <?php endif; ?>
  </td>
  <td><?= e((string)($accounts[(int)$r['account_id']] ?? ('#' . (int)$r['account_id']))) ?></td>
  <td><?= e((string)($types[(int)$r['type_id']] ?? ('#' . (int)$r['type_id']))) ?></td>
  <td><?= e(LedgerMovesRepository::descrForList((string)$r['descr'])) ?><?= $moveBadges[$mid] ?? '' ?>
    <?php
      $sid = (int)($r['supplier_id'] ?? 0);
      $needsSupplier = !empty($typeSupplier[(int)($r['type_id'] ?? 0)]);
      if ($sid > 0):
        $sName = (string)($suppliersAll[$sid] ?? ('#' . $sid));
    ?>
      <div class="ledger-supplier-line">
        <a class="ledger-supplier-badge" href="/suppliers/<?= $sid ?>/edit" title="<?= e($sName) ?>"><?= e($sName) ?></a>
      </div>
    <?php elseif ($needsSupplier): ?>
      <?php $sugList = is_array($supplierSuggestByMove[$mid] ?? null) ? $supplierSuggestByMove[$mid] : []; ?>
      <div class="ledger-supplier-line">
        <span class="ledger-supplier-badge is-missing" title="<?= te('ledger.moves.supplier_missing_hint') ?>"><?= te('ledger.moves.supplier_missing') ?></span>
        <?php if ($sugList !== []): ?>
          <span class="ledger-supplier-suggests" title="<?= te('ledger.moves.supplier_suggest_hint') ?>">
            <?php foreach ($sugList as $sug): ?>
              <?php
                $sugId = (int)($sug['id'] ?? 0);
                if ($sugId < 1) {
                    continue;
                }
                $fidSug = 'ledger-set-sup-' . $mid . '-' . $sugId;
                if ($canEdit) {
                    $supplierForms[$fidSug] = ['move_id' => $mid, 'supplier_id' => $sugId];
                }
                $cntLabel = ((int)($sug['total'] ?? 0) > 1)
                    ? ' (' . (int)$sug['cnt'] . '/' . (int)$sug['total'] . ')'
                    : '';
              ?>
              <?php if ($canEdit): ?>
                <button form="<?= e($fidSug) ?>" type="submit" class="ledger-supplier-suggest"
                        title="<?= te('ledger.moves.supplier_suggest_apply', ['name' => (string)$sug['name']]) ?>">
                  → <?= e((string)$sug['name']) ?><?= e($cntLabel) ?>
                </button>
              <?php else: ?>
                <span class="ledger-supplier-suggest is-readonly">→ <?= e((string)$sug['name']) ?><?= e($cntLabel) ?></span>
              <?php endif; ?>
            <?php endforeach; ?>
          </span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($userPayId > 0): ?>
      <div class="ledger-user-pay"><?= te('ledger.moves.user_payment_line') ?> <?= UsersRepository::badgeHtml($userPayId, true, true) ?></div>
    <?php endif; ?>
    <?php
      $dtPromised = (int)($r['dt_promised'] ?? 0);
      if ($dtPromised > 0 && (int)date('Y', $dtPromised) > 1970):
        $promExec = !empty($r['promise_executed']);
        $daysLeft = ($dtPromised - time()) / 86400;
    ?>
      <div class="ledger-promised<?= $promExec ? ' is-done' : ($dtPromised < time() ? ' is-overdue' : ' is-pending') ?>">
        <strong><?= te('ledger.moves.promised.label') ?></strong>
        <?= e(date('d.m.Y', $dtPromised)) ?>
        <?php if ($dtPromised > time()): ?>
          <span class="muted"><?= te('ledger.moves.promised.in_days', ['n' => round($daysLeft, 1)]) ?></span>
        <?php elseif ($promExec): ?>
          <span class="ledger-promised-ok"><?= te('ledger.moves.promised.done') ?></span>
        <?php else: ?>
          <span class="ledger-promised-bad"><?= te('ledger.moves.promised.not_done') ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php
      $act2In = (int)($r['act2_in'] ?? 0);
      $act2NotReq = !empty($r['act2_not_req']);
      $needsAct = $stickersEnabled && !empty($typeActIn[(int)$r['type_id']]);
    ?>
    <?php if ($needsAct): ?>
      <?php if ($act2In > 0): ?>
        <div class="ledger-act-line"><?= te('ledger.moves.act_linked') ?>
          <a class="ledger-payment-link" href="/stickers/receipts/<?= $act2In ?>/edit">#<?= $act2In ?></a>
        </div>
      <?php elseif ($act2NotReq): ?>
        <div class="ledger-act-line muted"><?= te('ledger.moves.act_not_req_mark') ?></div>
      <?php elseif ($canEdit): ?>
        <div class="ledger-act-missing">
          <strong><?= te('ledger.moves.act_missing') ?></strong>
          <a class="ledger-payment-link" href="<?= e(NavContext::withReturn('/ledger/moves/attach-act?id=' . $mid)) ?>"><?= te('ledger.moves.act_attach') ?></a>
        </div>
      <?php else: ?>
        <div class="ledger-act-missing"><strong><?= te('ledger.moves.act_missing') ?></strong></div>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($r['unconfirmed'])): ?>
      <span class="ledger-unconfirmed"><?= te('ledger.moves.unconfirmed_mark') ?></span>
    <?php endif; ?>
  </td>
  <td><?= money_signed_html((float)$r['summ'], (int)$r['direction']) ?>
    <?php if ((int)$r['payment_id'] > 0 && ModuleManager::isEnabled('payments')): ?>
      <a class="ledger-payment-link" href="/payments/<?= (int)$r['payment_id'] ?>/edit">#P<?= (int)$r['payment_id'] ?></a>
    <?php endif; ?>
  </td>
  <?php if ($showBalanceCol): ?>
  <td class="ledger-balance-cell">
    <?php if ($rowBalance !== null): ?>
      <div class="ledger-balance-num"><?= e(number_format((float)$rowBalance, 2, '.', ' ')) ?></div>
    <?php endif; ?>
    <?php foreach ($rowRecs as $rec): ?>
      <?php
        $rid = (int)$rec['id'];
        $bank = round((float)$rec['bank_balance'], 2);
        $savedSystem = round((float)$rec['system_balance'], 2);
        $isRecToday = date('Y-m-d', (int)$rec['dt']) === $todayYmd;
        $compareBalance = $isRecToday && $sysBalance !== null ? $sysBalance : (float)($rowBalance ?? $savedSystem);
        $delta = round($bank - $compareBalance, 2);
        $deltaClass = abs($delta) < 0.005 ? 'ledger-recon-delta-ok' : 'ledger-recon-delta-bad';
        // Only today's recon on the newest row is editable; older recons are read-only history.
        $canEditToday = $canRecon && $isFirst && $isRecToday && $reconToday && (int)$reconToday['id'] === $rid;
      ?>
      <div class="ledger-recon">
        <div class="ledger-recon-line">
          <strong><?= te('ledger.recon.label') ?></strong>
          <?= e(date('d.m.Y H:i', (int)$rec['dt'])) ?>
        </div>
        <?php if ($canEditToday): ?>
          <?php if ($sysBalance !== null && abs($savedSystem - $sysBalance) >= 0.005): ?>
            <div class="ledger-recon-warn">
              <?= te('ledger.recon.warn_system', [
                  'saved' => number_format($savedSystem, 2, '.', ''),
                  'current' => number_format($sysBalance, 2, '.', ''),
              ]) ?>
            </div>
          <?php endif; ?>
          <?php
            $fidUpd = 'ledger-recon-upd-' . $rid;
            $fidDel = 'ledger-recon-del-' . $rid;
            $reconForms[$fidUpd] = ['action' => 'update', 'recon_id' => $rid, 'account_id' => $accountId];
            $reconForms[$fidDel] = ['action' => 'delete', 'recon_id' => $rid, 'account_id' => $accountId];
          ?>
          <div class="ledger-recon-form inline-form">
            <input form="<?= e($fidUpd) ?>" name="bank_balance" inputmode="decimal" size="12"
                   value="<?= e((string)$rec['bank_balance']) ?>"
                   data-ledger-bank-normalize
                   title="<?= te('ledger.recon.bank') ?>">
            <span>=</span>
            <span class="<?= e($deltaClass) ?>"><?= e(number_format($delta, 2, '.', '')) ?></span>
            <button form="<?= e($fidUpd) ?>" type="submit" class="btn-secondary"><?= te('ledger.recon.ok') ?></button>
            <button form="<?= e($fidDel) ?>" type="submit" class="btn-secondary" title="<?= te('ledger.recon.delete') ?>">✕</button>
          </div>
        <?php else: ?>
          <div class="ledger-recon-line muted">
            <?= e(number_format($bank, 2, '.', '')) ?>
            = <span class="<?= e($deltaClass) ?>"><?= e(number_format($delta, 2, '.', '')) ?></span>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($showRecon && $isFirst && $canRecon && !$reconToday): ?>
      <?php
        $fidSave = 'ledger-recon-save';
        $reconForms[$fidSave] = ['action' => 'save', 'recon_id' => 0, 'account_id' => $accountId];
      ?>
      <div class="ledger-recon">
        <div class="ledger-recon-form inline-form">
          <input form="<?= e($fidSave) ?>" name="bank_balance" inputmode="decimal" size="12"
                 placeholder="<?= te('ledger.recon.bank_placeholder') ?>"
                 data-ledger-bank-normalize>
          <button form="<?= e($fidSave) ?>" type="submit" class="btn-secondary"><?= te('ledger.recon.ok') ?></button>
        </div>
      </div>
    <?php endif; ?>
  </td>
  <?php endif; ?>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?>
  <tr>
    <td colspan="<?= (int)$colSpan - ($showBalanceCol ? 1 : 0) ?>" class="muted"><?= te('ledger.moves.empty') ?></td>
    <?php if ($showBalanceCol): ?>
    <td class="ledger-balance-cell">
      <?php if ($showRecon && $sysBalance !== null): ?>
        <div class="ledger-balance-num"><?= e(number_format($sysBalance, 2, '.', ' ')) ?></div>
      <?php endif; ?>
      <?php if ($showRecon): foreach ($orphanRecs as $rec): ?>
        <?php
          $rid = (int)$rec['id'];
          $bank = round((float)$rec['bank_balance'], 2);
          $savedSystem = round((float)$rec['system_balance'], 2);
          $isRecToday = date('Y-m-d', (int)$rec['dt']) === $todayYmd;
          $compareBalance = $isRecToday && $sysBalance !== null ? $sysBalance : $savedSystem;
          $delta = round($bank - $compareBalance, 2);
          $deltaClass = abs($delta) < 0.005 ? 'ledger-recon-delta-ok' : 'ledger-recon-delta-bad';
          $canEditToday = $canRecon && $isRecToday && $reconToday && (int)$reconToday['id'] === $rid;
        ?>
        <div class="ledger-recon">
          <div class="ledger-recon-line">
            <strong><?= te('ledger.recon.label') ?></strong>
            <?= e(date('d.m.Y H:i', (int)$rec['dt'])) ?>
          </div>
          <?php if ($canEditToday): ?>
            <?php
              $fidUpd = 'ledger-recon-upd-' . $rid;
              $fidDel = 'ledger-recon-del-' . $rid;
              $reconForms[$fidUpd] = ['action' => 'update', 'recon_id' => $rid, 'account_id' => $accountId];
              $reconForms[$fidDel] = ['action' => 'delete', 'recon_id' => $rid, 'account_id' => $accountId];
            ?>
            <div class="ledger-recon-form inline-form">
              <input form="<?= e($fidUpd) ?>" name="bank_balance" inputmode="decimal" size="12"
                     value="<?= e((string)$rec['bank_balance']) ?>"
                     data-ledger-bank-normalize>
              <span>=</span>
              <span class="<?= e($deltaClass) ?>"><?= e(number_format($delta, 2, '.', '')) ?></span>
              <button form="<?= e($fidUpd) ?>" type="submit" class="btn-secondary"><?= te('ledger.recon.ok') ?></button>
              <button form="<?= e($fidDel) ?>" type="submit" class="btn-secondary" title="<?= te('ledger.recon.delete') ?>">✕</button>
            </div>
          <?php else: ?>
            <div class="ledger-recon-line muted">
              <?= e(number_format($bank, 2, '.', '')) ?>
              = <span class="<?= e($deltaClass) ?>"><?= e(number_format($delta, 2, '.', '')) ?></span>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
      <?php if ($showRecon && $canRecon && !$reconToday): ?>
        <?php
          $fidSave = 'ledger-recon-save';
          $reconForms[$fidSave] = ['action' => 'save', 'recon_id' => 0, 'account_id' => $accountId];
        ?>
        <div class="ledger-recon">
          <div class="ledger-recon-form inline-form">
            <input form="<?= e($fidSave) ?>" name="bank_balance" inputmode="decimal" size="12"
                   placeholder="<?= te('ledger.recon.bank_placeholder') ?>"
                   data-ledger-bank-normalize>
            <button form="<?= e($fidSave) ?>" type="submit" class="btn-secondary"><?= te('ledger.recon.ok') ?></button>
          </div>
        </div>
      <?php endif; ?>
    </td>
    <?php endif; ?>
  </tr>
<?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>

<?php foreach ($reconForms as $fid => $rf): ?>
<form id="<?= e($fid) ?>" method="post" action="<?= e(ListQuery::url($path, $filters, [])) ?>" hidden>
  <input type="hidden" name="recon_action" value="<?= e((string)$rf['action']) ?>">
  <input type="hidden" name="recon_account_id" value="<?= (int)$rf['account_id'] ?>">
  <?php if ((int)$rf['recon_id'] > 0): ?>
  <input type="hidden" name="recon_id" value="<?= (int)$rf['recon_id'] ?>">
  <?php endif; ?>
</form>
<?php endforeach; ?>

<?php foreach ($supplierForms as $fid => $sf): ?>
<form id="<?= e($fid) ?>" method="post" action="<?= e(ListQuery::url($path, $filters, [])) ?>" hidden>
  <input type="hidden" name="set_supplier_move_id" value="<?= (int)$sf['move_id'] ?>">
  <input type="hidden" name="set_supplier_id" value="<?= (int)$sf['supplier_id'] ?>">
</form>
<?php endforeach; ?>

<?= $pager ?>
