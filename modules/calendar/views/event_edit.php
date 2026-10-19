<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
/** @var string $returnUrl */
/** @var array<int,string> $staffNames */
/** @var list<array{id:int,name:string,price:float,qty:int}> $selectedItems */
/** @var list<array{id:int,name:string,price:float,uses:int}> $frequentGoods */
/** @var string $clientLabel */
/** @var bool $canModify */
/** @var bool $canDelete */
/** @var bool $canMakeProcedures */
/** @var list<array<string,mixed>> $linkedProcedures */
/** @var bool $staffLocked */
/** @var bool $visitsMode */
/** @var string $bonusExtraHtml */
$visitsMode = !empty($visitsMode);
$selectedItems = is_array($selectedItems ?? null) ? $selectedItems : [];
$frequentGoods = is_array($frequentGoods ?? null) ? $frequentGoods : [];

$dtstart = '';
$dtend = '';
if (isset($row['dtstart']) && is_string($row['dtstart']) && strpos($row['dtstart'], 'T') !== false) {
    $dtstart = (string)$row['dtstart'];
} else {
    $dtstart = EventsRepository::tsToLocal((int)($row['dtstart'] ?? 0));
}
if (isset($row['dtend']) && is_string($row['dtend']) && strpos($row['dtend'], 'T') !== false) {
    $dtend = (string)$row['dtend'];
} else {
    $dtend = EventsRepository::tsToLocal((int)($row['dtend'] ?? 0));
}

$readonly = !$canModify;
$isBlock = !empty($row['is_block']) || !$visitsMode;
$realSumm = (float)($row['real_summ'] ?? 0);
$phoneDigits = EventsRepository::normalizeEventPhone((string)($row['phone'] ?? ''));
?>
<section class="page-head">
  <h1><?= $isNew ? te('calendar.event.heading_new') : te('calendar.event.title', ['id' => (int)($row['id'] ?? 0)]) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if (!$visitsMode): ?>
  <p class="muted"><?= te('calendar.event.blocks_mode_hint') ?></p>
<?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form" id="event-edit-form"
      data-calendar-event-form="1"
      data-i18n-client-picked="<?= e(t('calendar.event.client_picked')) ?>"
      data-i18n-client-new="<?= e(t('calendar.event.client_walkin')) ?>"
      data-i18n-client-empty="<?= e(t('calendar.event.client_no_match')) ?>"
      data-i18n-remove="<?= e(t('calendar.event.remove_item')) ?>"
      data-i18n-qty="<?= e(t('calendar.event.col_qty')) ?>"
      data-i18n-price="<?= e(t('calendar.event.col_price')) ?>"
      data-i18n-staff-goods="<?= e(t('calendar.event.staff_goods_hint')) ?>"
      data-i18n-duration="<?= e(t('calendar.event.duration_hint')) ?>">
  <input type="hidden" name="ret" value="<?= e($returnUrl) ?>">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="auto_duration" id="event-auto-duration" value="1">

  <fieldset>
    <legend><?= te('calendar.event.sec_time') ?></legend>
    <label><?= te('calendar.event.col_dtstart') ?>
      <input type="datetime-local" name="dtstart" value="<?= e($dtstart) ?>" required<?= $readonly ? ' disabled' : '' ?>>
    </label>
    <label><?= te('calendar.event.col_dtend') ?>
      <input type="datetime-local" name="dtend" value="<?= e($dtend) ?>" required<?= $readonly ? ' disabled' : '' ?>>
    </label>
    <label><?= te('calendar.event.col_operator') ?>
      <select name="id_operator" id="event-operator" required<?= $readonly || $staffLocked ? ' disabled' : '' ?>>
        <option value="">—</option>
        <?php foreach ($staffNames as $sid => $slabel): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['id_operator'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($staffLocked): ?>
        <input type="hidden" name="id_operator" id="event-operator-locked" value="<?= (int)($row['id_operator'] ?? 0) ?>">
      <?php endif; ?>
    </label>
    <?php if ($visitsMode): ?>
      <label class="check">
        <input type="checkbox" name="is_block" value="1" id="event-is-block"<?= $isBlock ? ' checked' : '' ?><?= $readonly ? ' disabled' : '' ?>>
        <?= te('calendar.event.col_is_block') ?>
      </label>
    <?php else: ?>
      <input type="hidden" name="is_block" value="1">
      <p class="form-hint"><?= te('calendar.event.col_is_block') ?></p>
    <?php endif; ?>
  </fieldset>

  <?php if ($visitsMode): ?>
  <fieldset id="event-client-sec"<?= $isBlock ? ' hidden' : '' ?>>
    <legend><?= te('calendar.event.sec_client') ?></legend>
    <input type="hidden" name="id_user" id="event-client-id" value="<?= (int)($row['id_user'] ?? 0) > 0 ? (int)$row['id_user'] : 0 ?>">

    <label><?= te('calendar.event.col_phone') ?>
      <input type="tel" name="phone" id="event-phone" class="js-phone-ru"
             value="<?= e($phoneDigits) ?>"
             placeholder="+7 (___) ___-__-__"
             data-phone-invalid="<?= e(t('calendar.event.err.phone_format')) ?>"
             <?= $readonly ? ' disabled' : '' ?>>
    </label>
    <label><?= te('calendar.event.col_name') ?>
      <input type="text" name="name" id="event-client-name" maxlength="255"
             value="<?= e((string)($row['name'] ?? '')) ?>"
             autocomplete="off"
             placeholder="<?= te('calendar.event.client_search_ph') ?>"
             <?= $readonly ? ' disabled' : '' ?>>
    </label>
    <div id="event-client-suggest" class="calendar-suggest" hidden></div>
    <p id="event-client-status" class="muted calendar-client-status">
      <?php if ((int)($row['id_user'] ?? 0) > 0): ?>
        <?= e(t('calendar.event.client_picked', [
            'name' => $clientLabel !== '' ? $clientLabel : (string)($row['name'] ?? ''),
            'tel' => $phoneDigits,
            'id' => (string)(int)$row['id_user'],
        ])) ?>
        · <a href="/clients/<?= (int)$row['id_user'] ?>"><?= te('calendar.event.open_client') ?></a>
      <?php else: ?>
        <?= te('calendar.event.client_walkin') ?>
      <?php endif; ?>
    </p>
    <label><?= te('calendar.event.col_descr') ?>
      <textarea name="descr" rows="2"<?= $readonly ? ' disabled' : '' ?>><?= e((string)($row['descr'] ?? '')) ?></textarea>
    </label>
  </fieldset>

  <fieldset id="event-content-sec"<?= $isBlock ? ' hidden' : '' ?>>
    <legend><?= te('calendar.event.sec_content') ?></legend>
    <p class="form-hint"><?= te('calendar.event.content_hint_compact') ?></p>
    <p class="form-hint muted" id="event-staff-goods-hint"><?= te('calendar.event.staff_goods_hint') ?></p>

    <?php if (!$readonly): ?>
      <label class="calendar-product-search-label"><?= te('calendar.event.product_search') ?>
        <input type="search" id="event-product-q" autocomplete="off"
               placeholder="<?= te('calendar.event.product_search_ph') ?>">
      </label>
      <div id="event-product-suggest" class="calendar-suggest" hidden></div>
      <details class="calendar-frequent-wrap" id="event-frequent-wrap"<?= $frequentGoods ? '' : ' hidden' ?>>
        <summary class="btn-secondary btn-sm calendar-frequent-toggle"><?= te('calendar.event.frequent_toggle') ?></summary>
        <div class="calendar-frequent" id="event-frequent">
          <?php foreach ($frequentGoods as $fg): ?>
            <button type="button" class="btn-secondary btn-sm calendar-frequent-btn"
                    data-id="<?= (int)$fg['id'] ?>"
                    data-name="<?= e((string)$fg['name']) ?>"
                    data-price="<?= e((string)(int)round((float)$fg['price'])) ?>"
                    data-time="<?= (int)($fg['time_sec'] ?? 0) ?>">
              <?= e((string)$fg['name']) ?>
            </button>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>

    <div id="event-selected-items" class="calendar-selected-items"
         data-readonly="<?= $readonly ? '1' : '0' ?>">
      <?php foreach ($selectedItems as $it):
          $timeSec = 0;
          if (class_exists('ProductsRepository', false)) {
              $p = ProductsRepository::find((int)$it['id']);
              $timeSec = $p ? max(0, (int)($p['time_sec'] ?? 0)) : 0;
          }
          ?>
        <div class="calendar-selected-row" data-id="<?= (int)$it['id'] ?>" data-time="<?= $timeSec ?>"
             data-qty-locked="<?= $timeSec > 0 ? '1' : '0' ?>">
          <input type="hidden" name="content[]" value="<?= (int)$it['id'] ?>">
          <span class="calendar-selected-name"><?= e((string)$it['name']) ?></span>
          <label class="calendar-selected-price"><?= te('calendar.event.col_price') ?>
            <input type="number" min="0" step="1" name="content_price[<?= (int)$it['id'] ?>]"
                   value="<?= e((string)(int)round((float)$it['price'])) ?>"<?= $readonly ? ' disabled' : '' ?>>
          </label>
          <?php if ($timeSec > 0): ?>
            <input type="hidden" name="content_qty[<?= (int)$it['id'] ?>]" value="1">
            <span class="calendar-selected-qty muted" title="<?= te('calendar.event.qty_locked_hint') ?>">×1</span>
          <?php else: ?>
            <label class="calendar-selected-qty"><?= te('calendar.event.col_qty') ?>
              <input type="number" min="1" step="1" name="content_qty[<?= (int)$it['id'] ?>]"
                     value="<?= (int)$it['qty'] ?>"<?= $readonly ? ' disabled' : '' ?>>
            </label>
          <?php endif; ?>
          <?php if (!$readonly): ?>
            <button type="button" class="btn-secondary btn-sm calendar-selected-remove" aria-label="<?= te('calendar.event.remove_item') ?>">×</button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="form-hint"><?= te('calendar.event.col_real_summ') ?>: <strong id="event-sum-display"><?= e(number_format($realSumm, 0, '.', ' ')) ?></strong>
      <span class="muted" id="event-duration-hint"></span>
    </p>
  </fieldset>
  <?php else: ?>
  <fieldset>
    <legend><?= te('calendar.event.sec_client') ?></legend>
    <label><?= te('calendar.event.col_descr') ?>
      <textarea name="descr" rows="3"<?= $readonly ? ' disabled' : '' ?>><?= e((string)($row['descr'] ?? '')) ?></textarea>
    </label>
  </fieldset>
  <?php endif; ?>

  <?php if (!empty($bonusExtraHtml)): ?>
    <?= $bonusExtraHtml ?>
  <?php endif; ?>

  <div class="page-actions">
    <?php if ($canModify): ?>
      <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <?php endif; ?>
    <a class="btn-secondary" href="<?= e($returnUrl) ?>"><?= te('core.common.cancel') ?></a>
    <?php if (!empty($canMakeProcedures)): ?>
      <button class="btn-secondary" type="submit" name="action" value="create_procedures"><?= te('calendar.event.btn_procedures') ?></button>
    <?php endif; ?>
  </div>
</form>
<?php if (!$isNew): ?>
<?= EntityDelete::form('calendar_events', (int)($row['id'] ?? 0), [
    'confirm' => 'calendar.event.confirm_delete',
]) ?>
<?php endif; ?>

<?php if (!$isNew && $visitsMode): ?>
<section class="calendar-section">
  <h2><?= te('calendar.event.sec_procedures') ?></h2>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>ID</th>
          <th><?= te('calendar.day.col_product') ?></th>
          <th><?= te('calendar.day.col_time') ?></th>
          <th><?= te('calendar.day.col_sum') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach (($linkedProcedures ?? []) as $pr): ?>
        <tr>
          <td><a href="/procedures/<?= (int)$pr['id'] ?>/edit">#<?= (int)$pr['id'] ?></a></td>
          <td>#<?= (int)($pr['id_good'] ?? 0) ?></td>
          <td><?= e(EventsRepository::formatRange((int)($pr['dtstart'] ?? 0), (int)($pr['dtend'] ?? 0))) ?></td>
          <td><?= e(number_format((float)($pr['summ'] ?? 0), 0, '.', ' ')) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($linkedProcedures)): ?>
        <tr><td colspan="4" class="muted"><?= te('calendar.event.empty_procedures') ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
