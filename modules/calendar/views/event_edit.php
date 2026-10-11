<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
/** @var string $returnUrl */
/** @var array<int,string> $staffNames */
/** @var array<int,string> $productNames */
/** @var array<int,float> $productPrices */
/** @var string $clientLabel */
/** @var bool $canModify */
/** @var bool $canDelete */
/** @var bool $canMakeProcedures */
/** @var list<array<string,mixed>> $linkedProcedures */
/** @var bool $staffLocked */

/** @var string $bonusExtraHtml */
$contentIds = $row['content_ids'] ?? [];
if (!is_array($contentIds)) {
    $contentIds = EventsRepository::parseContentIds((string)($row['content'] ?? ''));
}
$contentIds = array_map('intval', $contentIds);
$priceMap = $row['content_price_map'] ?? [];
if (!is_array($priceMap)) {
    $priceMap = [];
}

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
?>
<section class="page-head">
  <h1><?= $isNew ? te('calendar.event.heading_new') : te('calendar.event.title', ['id' => (int)($row['id'] ?? 0)]) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form" id="event-edit-form">
  <input type="hidden" name="ret" value="<?= e($returnUrl) ?>">
  <input type="hidden" name="action" value="save">

  <fieldset>
    <legend><?= te('calendar.event.sec_time') ?></legend>
    <label><?= te('calendar.event.col_dtstart') ?>
      <input type="datetime-local" name="dtstart" value="<?= e($dtstart) ?>" required<?= $readonly ? ' disabled' : '' ?>>
    </label>
    <label><?= te('calendar.event.col_dtend') ?>
      <input type="datetime-local" name="dtend" value="<?= e($dtend) ?>" required<?= $readonly ? ' disabled' : '' ?>>
    </label>
    <label><?= te('calendar.event.col_operator') ?>
      <select name="id_operator" required<?= $readonly || $staffLocked ? ' disabled' : '' ?>>
        <option value="">—</option>
        <?php foreach ($staffNames as $sid => $slabel): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['id_operator'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($staffLocked): ?>
        <input type="hidden" name="id_operator" value="<?= (int)($row['id_operator'] ?? 0) ?>">
      <?php endif; ?>
    </label>
    <label class="check">
      <input type="checkbox" name="is_block" value="1"<?= !empty($row['is_block']) ? ' checked' : '' ?><?= $readonly ? ' disabled' : '' ?>>
      <?= te('calendar.event.col_is_block') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="fix_move" value="1"<?= !empty($row['fix_move']) ? ' checked' : '' ?><?= $readonly ? ' disabled' : '' ?>>
      <?= te('calendar.event.col_fix_move') ?>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('calendar.event.sec_client') ?></legend>
    <label><?= te('calendar.event.col_id_user') ?>
      <input type="number" min="0" name="id_user" value="<?= (int)($row['id_user'] ?? 0) > 0 ? (int)$row['id_user'] : '' ?>"<?= $readonly ? ' disabled' : '' ?>>
      <?php if ($clientLabel !== ''): ?>
        <span class="form-hint"><?= e($clientLabel) ?> · <a href="/clients/<?= (int)$row['id_user'] ?>"><?= te('calendar.event.open_client') ?></a></span>
      <?php endif; ?>
    </label>
    <label><?= te('calendar.event.col_name') ?>
      <input name="name" maxlength="255" value="<?= e((string)($row['name'] ?? '')) ?>"<?= $readonly ? ' disabled' : '' ?>>
    </label>
    <label><?= te('calendar.event.col_phone') ?>
      <input name="phone" maxlength="11" value="<?= e((string)($row['phone'] ?? '')) ?>"<?= $readonly ? ' disabled' : '' ?>>
    </label>
    <label><?= te('calendar.event.col_descr') ?>
      <textarea name="descr" rows="3"<?= $readonly ? ' disabled' : '' ?>><?= e((string)($row['descr'] ?? '')) ?></textarea>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('calendar.event.sec_content') ?></legend>
    <p class="form-hint"><?= te('calendar.event.content_hint') ?></p>
    <div class="calendar-content-list">
      <?php foreach ($productNames as $pid => $plabel): ?>
        <?php
        $checked = in_array((int)$pid, $contentIds, true);
        $priceVal = $priceMap[(int)$pid] ?? $productPrices[(int)$pid] ?? 0;
        ?>
        <label class="calendar-content-item">
          <input type="checkbox" name="content[]" value="<?= (int)$pid ?>"<?= $checked ? ' checked' : '' ?><?= $readonly ? ' disabled' : '' ?>>
          <span class="calendar-content-name"><?= e($plabel) ?></span>
          <input type="number" min="0" step="1" name="content_price[<?= (int)$pid ?>]" value="<?= e((string)(int)round((float)$priceVal)) ?>"<?= $readonly ? ' disabled' : '' ?> aria-label="<?= te('calendar.event.col_price') ?>">
        </label>
      <?php endforeach; ?>
      <?php if (!$productNames): ?>
        <p class="muted"><?= te('calendar.event.no_products') ?></p>
      <?php endif; ?>
    </div>
    <label><?= te('calendar.event.col_real_summ') ?>
      <input type="number" min="0" step="1" name="real_summ" value="<?= e((string)(int)round((float)($row['real_summ'] ?? 0))) ?>"<?= $readonly ? ' disabled' : '' ?>>
    </label>
  </fieldset>

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
    <?php if ($canDelete): ?>
      <button class="btn-danger" type="submit" name="action" value="delete"
              onclick="return confirm(<?= tj('calendar.event.confirm_delete') ?>)"><?= te('core.common.delete') ?></button>
    <?php endif; ?>
  </div>
</form>

<?php if (!$isNew): ?>
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
