<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<string,mixed>|null $type */
/** @var array<int,array<string,mixed>> $typeMeta */
/** @var array{defaults:array,buttons:array} $prevSuggestions */
/** @var array<string,bool> $prevApplied */
/** @var array<int,string> $accounts */
/** @var array<int,string> $staff */
/** @var array<int,string> $suppliers */
/** @var array<int,string> $actOptions */
/** @var string $dtValue */
/** @var string $dtPromisedValue */
/** @var string $message */
/** @var string $error */
/** @var bool $fundsEnabled */
/** @var bool $stickersEnabled */
/** @var string $extraSectionsHtml */
$hasType = $type !== null;
$dtPromisedValue = (string)($dtPromisedValue ?? '');
$showPromiseExecuted = $dtPromisedValue !== '' && strtotime($dtPromisedValue . ' 00:00:00') !== false
    && strtotime($dtPromisedValue . ' 00:00:00') <= time();
$types = [];
foreach ($typeMeta as $tid => $meta) {
    $types[(int)$tid] = (string)$meta['name'];
}
$typeMetaJson = json_encode($typeMeta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
if ($typeMetaJson === false) {
    $typeMetaJson = '{}';
}
$prevButtons = is_array($prevSuggestions['buttons'] ?? null) ? $prevSuggestions['buttons'] : [];
$dirVal = (int)($row['direction'] ?? -1);
$showAct = $hasType && $stickersEnabled && !empty($type['act_in_enabled']);
?>
<section class="page-head"><h1><?= $isNew ? te('ledger.moves.edit_new') : e('#' . (int)($row['id'] ?? 0)) ?><?= $moveBadgeHtml ?? '' ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e_nl($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e_nl($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide ledger-move-form" data-ledger-move-form="1"<?= $isNew ? ' data-ledger-move-new="1"' : '' ?>>
  <script type="application/json" id="ledger-type-meta"><?= $typeMetaJson ?></script>
  <label><?= te('ledger.moves.col.type') ?>
    <select name="type_id" id="ledger-move-type" required data-ledger-type-select<?= $isNew ? ' data-ledger-type-reload="1"' : '' ?>>
      <option value=""><?= te('ledger.moves.type_pick') ?></option>
      <?php foreach ($types as $tid => $tname): ?>
        <option value="<?= (int)$tid ?>"<?= (int)($row['type_id'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.col.dt') ?><input type="datetime-local" name="dt" value="<?= e($dtValue) ?>" required></label>
  <label><?= te('ledger.moves.col.sum') ?><input name="summ" value="<?= e((string)($row['summ'] ?? '0')) ?>" required></label>
  <label class="ledger-move-descr"><?= te('ledger.moves.col.descr') ?>
    <textarea name="descr" rows="10"><?= e((string)($row['descr'] ?? '')) ?></textarea>
  </label>

  <fieldset data-ledger-field="direction" class="ledger-dir-field"<?= $hasType ? '' : ' hidden' ?>>
    <legend><?= te('ledger.moves.col.direction') ?><?php if (!empty($prevApplied['direction'])): ?> <span class="muted"><?= te('ledger.moves.prev_mark') ?></span><?php endif; ?></legend>
    <div class="ledger-dir-options">
      <?php foreach ([-1 => 'ledger.dir.label.out', 1 => 'ledger.dir.label.in', 0 => 'ledger.dir.label.none'] as $dv => $dkey): ?>
        <label class="ledger-dir-opt">
          <input type="radio" name="direction" value="<?= (int)$dv ?>"<?= $dirVal === (int)$dv ? ' checked' : '' ?>>
          <?= te($dkey) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <?php if ($isNew && !empty($prevButtons['direction'])): ?>
      <div class="ledger-prev-btns">
        <?php foreach ($prevButtons['direction'] as $btn): ?>
          <button type="button" class="btn-secondary" data-ledger-prev-fill="direction" data-val="<?= e((string)$btn['val']) ?>">
            → <?= e((string)$btn['label']) ?><?php if ((int)$btn['total'] > 1): ?> (<?= (int)$btn['cnt'] ?>/<?= (int)$btn['total'] ?>)<?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </fieldset>

  <label data-ledger-field="account"<?= $hasType ? '' : ' hidden' ?>><?= te('ledger.moves.col.account') ?><?php if (!empty($prevApplied['account_id'])): ?> <span class="muted"><?= te('ledger.moves.prev_mark') ?></span><?php endif; ?>
    <select name="account_id" id="ledger-move-account">
      <option value=""><?= te('ledger.moves.filter_all') ?></option>
      <?php foreach ($accounts as $aid => $aname): ?>
        <option value="<?= (int)$aid ?>"<?= (int)($row['account_id'] ?? 0) === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($isNew && !empty($prevButtons['account_id'])): ?>
      <span class="ledger-prev-btns">
        <?php foreach ($prevButtons['account_id'] as $btn): ?>
          <button type="button" class="btn-secondary" data-ledger-prev-fill="account_id" data-val="<?= e((string)$btn['val']) ?>">
            → <?= e((string)$btn['label']) ?><?php if ((int)$btn['total'] > 1): ?> (<?= (int)$btn['cnt'] ?>/<?= (int)$btn['total'] ?>)<?php endif; ?>
          </button>
        <?php endforeach; ?>
      </span>
    <?php endif; ?>
  </label>

  <label data-ledger-field="payment"<?= ($hasType && !empty($type['payment_enabled'])) ? '' : ' hidden' ?>><?= te('ledger.moves.field.payment') ?>
    <input type="text" name="payment_id" inputmode="numeric" pattern="[0-9]*" autocomplete="off"
           value="<?= e((string)((int)($row['payment_id'] ?? 0))) ?>">
  </label>
  <label data-ledger-field="user_payment"<?= ($hasType && !empty($type['user_payment_enabled'])) ? '' : ' hidden' ?>><?= te('ledger.moves.field.user_payment') ?>
    <select name="user_payment">
      <option value="0">—</option>
      <?php foreach ($staff as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)($row['user_payment'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label data-ledger-field="supplier"<?= ($hasType && !empty($type['supplier_enabled'])) ? '' : ' hidden' ?>><?= te('ledger.moves.field.supplier') ?><?php if (!empty($prevApplied['supplier_id'])): ?> <span class="muted"><?= te('ledger.moves.prev_mark') ?></span><?php endif; ?>
    <select name="supplier_id" id="ledger-move-supplier">
      <option value="0">—</option>
      <?php foreach ($suppliers as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)($row['supplier_id'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($isNew && !empty($prevButtons['supplier_id'])): ?>
      <span class="ledger-prev-btns">
        <?php foreach ($prevButtons['supplier_id'] as $btn): ?>
          <button type="button" class="btn-secondary" data-ledger-prev-fill="supplier_id" data-val="<?= e((string)$btn['val']) ?>">
            → <?= e((string)$btn['label']) ?><?php if ((int)$btn['total'] > 1): ?> (<?= (int)$btn['cnt'] ?>/<?= (int)$btn['total'] ?>)<?php endif; ?>
          </button>
        <?php endforeach; ?>
      </span>
    <?php endif; ?>
  </label>

  <?php if ($stickersEnabled): ?>
  <label data-ledger-field="act"<?= $showAct ? '' : ' hidden' ?>><?= te('ledger.moves.field.act2_in') ?>
    <select name="act2_in">
      <option value="0">—</option>
      <?php foreach ($actOptions as $aid => $alabel): ?>
        <option value="<?= (int)$aid ?>"<?= (int)($row['act2_in'] ?? 0) === (int)$aid ? ' selected' : '' ?>><?= e($alabel) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="check" data-ledger-field="act_not_req"<?= $showAct ? '' : ' hidden' ?>>
    <input type="checkbox" name="act2_not_req" value="1"<?= !empty($row['act2_not_req']) ? ' checked' : '' ?>>
    <?= te('ledger.moves.field.act2_not_req') ?>
  </label>
  <?php endif; ?>

  <?php if (!empty($fundsEnabled)): ?>
  <label data-ledger-field="fund"<?= ($hasType && !empty($type['fund_enabled'])) ? '' : ' hidden' ?>><?= te('ledger.moves.field.fund_movement') ?>
    <input type="text" name="fund_movement_id" inputmode="numeric" pattern="[0-9]*" autocomplete="off"
           value="<?= e((string)((int)($row['fund_movement_id'] ?? 0))) ?>">
  </label>
  <?php endif; ?>
  <label class="check" data-ledger-field="unconfirmed"<?= $hasType ? '' : ' hidden' ?>>
    <input type="checkbox" name="unconfirmed" value="1"<?= !empty($row['unconfirmed']) ? ' checked' : '' ?>> <?= te('ledger.moves.field.unconfirmed') ?>
  </label>

  <fieldset data-ledger-field="promised" class="ledger-promised-field"<?= $hasType ? '' : ' hidden' ?>>
    <legend><?= te('ledger.moves.promised.legend') ?></legend>
    <p class="muted"><?= te('ledger.moves.promised.hint') ?></p>
    <label><?= te('ledger.moves.field.dt_promised') ?>
      <input type="date" name="dt_promised" id="ledger-dt-promised" value="<?= e($dtPromisedValue) ?>" data-ledger-promised-date>
    </label>
    <label class="check" data-ledger-promised-executed<?= $showPromiseExecuted ? '' : ' hidden' ?>>
      <input type="checkbox" name="promise_executed" value="1"<?= !empty($row['promise_executed']) ? ' checked' : '' ?>>
      <?= te('ledger.moves.field.promise_executed') ?>
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit" name="action" value="save" data-ledger-save<?= $hasType ? '' : ' hidden' ?>><?= te('core.common.save') ?></button>
    <p class="muted" data-ledger-type-hint<?= $hasType ? ' hidden' : '' ?>><?= te('ledger.moves.type_pick_hint') ?></p>
    <a class="btn-secondary" href="/ledger/moves"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!empty($extraSectionsHtml)): ?>
  <?= $extraSectionsHtml ?>
<?php endif; ?>
<?php if (!$isNew): ?>
<form method="post" class="danger-zone" onsubmit="return confirm(<?= tj('ledger.moves.confirm_delete') ?>);">
  <?= NavContext::hiddenField() ?>
  <input type="hidden" name="action" value="delete">
  <button class="btn-danger" type="submit"><?= te('core.common.delete') ?></button>
</form>
<?php endif; ?>
