<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var list<array<string,mixed>> $acts */
/** @var array<string,mixed>|null $act */
/** @var array<int,string> $stocks */
/** @var array<int,string> $products */
/** @var string $goodQ */
/** @var bool $manualActivation */
/** @var string $backUrl */
/** @var bool $canWriteoff */
/** @var string $message */
/** @var string $error */
$act = is_array($act ?? null) ? $act : null;
$stocks = is_array($stocks ?? null) ? $stocks : [];
?>
<section class="page-head"><h1><?= e((string)$title) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <fieldset>
    <?php if ($act !== null): ?>
      <div class="sticker-act-fixed">
        <span class="muted"><?= te('stickers.col.act') ?>:</span>
        <a href="/stickers/receipts/<?= (int)$act['id'] ?>/edit">П-<?= (int)$act['id'] ?></a>
        <?php if (trim((string)($act['descr'] ?? '')) !== ''): ?> — <?= e((string)$act['descr']) ?><?php endif; ?>
        <span class="muted">· <?= e($stocks[(int)$act['id_stock']] ?? ('#' . (int)$act['id_stock'])) ?></span>
        <input type="hidden" name="id_act" value="<?= (int)$act['id'] ?>">
      </div>
    <?php else: ?>
    <label><?= te('stickers.col.act') ?>
      <select name="id_act" required>
        <option value="">—</option>
        <?php foreach ($acts as $a): ?>
          <option value="<?= (int)$a['id'] ?>"<?= (int)($row['id_act'] ?? 0) === (int)$a['id'] ? ' selected' : '' ?>>
            П-<?= (int)$a['id'] ?> — <?= e((string)$a['descr']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php endif; ?>
    <label><?= te('stickers.col.good') ?>
      <input type="search" name="good_q" value="<?= e($goodQ) ?>" list="stickers-good-pick" autocomplete="off"
             placeholder="<?= te('stickers.ins.good_search_ph') ?>" required>
      <input type="hidden" name="id_good" value="<?= (int)($row['id_good'] ?? 0) > 0 ? (int)$row['id_good'] : '' ?>">
      <datalist id="stickers-good-pick">
        <?php foreach ($products as $pid => $pname): ?>
          <option value="<?= e($pname) ?>" data-id="<?= (int)$pid ?>"></option>
        <?php endforeach; ?>
      </datalist>
    </label>
    <label><?= te('stickers.col.qty') ?>
      <input type="number" min="1" name="qty" value="<?= e((string)($row['qty'] ?? 1)) ?>"<?= !$isNew ? ' readonly' : '' ?> required>
    </label>
    <label><?= te('stickers.col.rate') ?>
      <input name="qty_qtyu_rate" value="<?= e((string)($row['qty_qtyu_rate'] ?? '1')) ?>" required>
    </label>
    <label><?= te('stickers.col.price') ?>
      <input name="price" value="<?= e((string)($row['price'] ?? '0')) ?>">
    </label>
    <label><?= te('stickers.col.descr') ?>
      <input name="descr" maxlength="255" value="<?= e((string)($row['descr'] ?? '')) ?>">
    </label>
    <?php if ($manualActivation): ?>
      <label class="check"><input type="checkbox" name="activate" value="1"<?= !empty($row['dt_print']) ? ' checked' : '' ?>> <?= te('stickers.ins.activate') ?></label>
    <?php elseif (empty($row['dt_print'])): ?>
      <p class="muted"><?= te('stickers.ins.activate_scan_hint') ?></p>
    <?php endif; ?>
    <?php if (!$isNew): ?>
      <p class="muted"><?= te('stickers.col.pin') ?>: <code><?= e((string)($row['pin'] ?? '')) ?></code>
        · HASH: <code><?= e((string)($row['hash'] ?? '')) ?></code>
        · <?= te('stickers.col.left') ?>: <strong><?= e((string)StickerInsRepository::remaining($row)) ?></strong></p>
      <?php $logHtml = StickerInsRepository::formatLogHtml((string)($row['log'] ?? '')); ?>
      <?php if ($logHtml !== ''): ?>
        <div class="sticker-log">
          <strong><?= te('stickers.col.log') ?></strong>
          <div class="sticker-log-body muted"><?= $logHtml ?></div>
        </div>
        <style>.sticker-log{margin:0.5rem 0}.sticker-log-body{margin:0.25rem 0 0;font-size:0.9rem;line-height:1.55}.sticker-log-body .user-badge{vertical-align:baseline}</style>
      <?php endif; ?>
      <?php
        $hash = (string)($row['hash'] ?? '');
        $qrUrl = $hash !== '' ? StickerQr::absoluteScanUrl($hash, !empty($row['dt_revision']) ? ['do' => 2] : []) : '';
      ?>
      <?php if ($qrUrl !== '' && (Auth::capability('SUPERADMIN_ACCESS') || !empty($row['dt_revision']))): ?>
        <div class="sticker-qr-preview">
          <p><?= te('stickers.ins.qr_preview') ?>:
            <a href="<?= e($qrUrl) ?>" target="_blank" rel="noopener"><?= e($qrUrl) ?></a>
          </p>
          <div style="background:#fff;display:inline-block;padding:4px"><?= StickerQr::svgHtml($qrUrl, 120) ?></div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= e((string)$backUrl) ?>"><?= te('core.common.cancel') ?></a>
    <?php if ($canWriteoff): ?>
      <a class="btn-secondary" href="/stickers/writeoff?id_sticker=<?= (int)$row['id'] ?>"><?= te('stickers.writeoff.submit') ?></a>
    <?php endif; ?>
  </div>
</form>
<style>.sticker-act-fixed{margin:0 0 0.75rem}</style>
<script>
(function () {
  var good = document.querySelector('input[name="good_q"]');
  var hid = document.querySelector('input[name="id_good"]');
  var list = document.getElementById('stickers-good-pick');
  if (!good || !hid || !list) return;
  good.addEventListener('input', function () {
    var v = good.value.trim();
    var hit = null;
    for (var i = 0; i < list.options.length; i++) {
      if (list.options[i].value === v) { hit = list.options[i]; break; }
    }
    hid.value = hit && hit.getAttribute('data-id') ? hit.getAttribute('data-id') : '';
  });
})();
</script>
