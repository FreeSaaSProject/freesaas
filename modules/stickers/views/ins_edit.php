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
    <label class="stickers-good-field"><?= te('stickers.col.good') ?>
      <input type="search" name="good_q" value="<?= e($goodQ) ?>" autocomplete="off"
             placeholder="<?= te('stickers.ins.good_search_ph') ?>" required
             aria-autocomplete="list" aria-controls="stickers-good-suggest">
      <input type="hidden" name="id_good" value="<?= (int)($row['id_good'] ?? 0) > 0 ? (int)$row['id_good'] : '' ?>">
      <ul id="stickers-good-suggest" class="stickers-good-suggest" hidden></ul>
    </label>
    <label><?= te('stickers.col.qty') ?>
      <input type="number" min="1" name="qty" value="<?= e((string)($row['qty'] ?? 1)) ?>"<?= !$isNew ? ' readonly' : '' ?> required>
    </label>
    <label><?= te('stickers.col.rate') ?>
      <input name="qty_qtyu_rate" value="<?= e((string)($row['qty_qtyu_rate'] ?? '1')) ?>" required>
    </label>
    <label><?= te('stickers.col.price') ?>
      <input name="price" inputmode="decimal" value="<?= e(isset($row['price']) ? (string)$row['price'] : '') ?>" placeholder="0">
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
<style>
.sticker-act-fixed{margin:0 0 0.75rem}
.stickers-good-field{position:relative}
.stickers-good-suggest{
  position:absolute;z-index:20;left:0;right:0;top:100%;
  max-height:14rem;overflow:auto;margin:0.15rem 0 0;padding:0.25rem 0;
  list-style:none;background:var(--bg,#fff);border:1px solid var(--line,#ccc);border-radius:0.35rem;
  box-shadow:0 0.35rem 0.85rem rgba(0,0,0,.08);
}
.stickers-good-suggest button{
  display:block;width:100%;text-align:left;border:0;background:transparent;
  padding:0.45rem 0.7rem;font:inherit;cursor:pointer;
}
.stickers-good-suggest button:hover,
.stickers-good-suggest button.is-active{background:var(--bg-muted,rgba(0,0,0,.06))}
</style>
<script type="application/json" id="stickers-good-data"><?= json_encode(
    array_map(static function ($id, $name): array {
        return ['id' => (int)$id, 'name' => (string)$name];
    }, array_keys($products), array_values($products)),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
) ?></script>
<script>
(function () {
  var good = document.querySelector('input[name="good_q"]');
  var hid = document.querySelector('input[name="id_good"]');
  var box = document.getElementById('stickers-good-suggest');
  var raw = document.getElementById('stickers-good-data');
  var price = document.querySelector('input[name="price"]');
  if (price) {
    price.addEventListener('focus', function () {
      if (price.value === '0') price.select();
    });
  }
  if (!good || !hid || !box || !raw) return;
  var products = [];
  try { products = JSON.parse(raw.textContent || '[]') || []; } catch (e) { products = []; }
  var active = -1;
  function fold(s) {
    try { return String(s || '').toLocaleLowerCase('ru'); }
    catch (e) { return String(s || '').toLowerCase(); }
  }
  function exactHit(v) {
    var f = fold(v);
    for (var i = 0; i < products.length; i++) {
      if (fold(products[i].name) === f) return products[i];
    }
    return null;
  }
  function matches(q) {
    var f = fold(q);
    if (f === '') return [];
    var out = [];
    for (var i = 0; i < products.length; i++) {
      if (fold(products[i].name).indexOf(f) !== -1) {
        out.push(products[i]);
        if (out.length >= 40) break;
      }
    }
    return out;
  }
  function pick(item) {
    good.value = item.name;
    hid.value = String(item.id);
    hide();
  }
  function hide() {
    box.hidden = true;
    box.innerHTML = '';
    active = -1;
  }
  function render(items) {
    box.innerHTML = '';
    active = -1;
    if (!items.length) { box.hidden = true; return; }
    items.forEach(function (item, idx) {
      var li = document.createElement('li');
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = item.name;
      btn.addEventListener('mousedown', function (e) { e.preventDefault(); pick(item); });
      li.appendChild(btn);
      box.appendChild(li);
      if (idx === 0) { active = 0; btn.classList.add('is-active'); }
    });
    box.hidden = false;
  }
  function syncHidden() {
    var hit = exactHit(good.value.trim());
    hid.value = hit ? String(hit.id) : '';
  }
  function moveActive(delta) {
    var btns = box.querySelectorAll('button');
    if (!btns.length) return;
    if (active >= 0 && btns[active]) btns[active].classList.remove('is-active');
    active = (active + delta + btns.length) % btns.length;
    btns[active].classList.add('is-active');
    btns[active].scrollIntoView({ block: 'nearest' });
  }
  good.addEventListener('input', function () {
    syncHidden();
    render(matches(good.value.trim()));
  });
  good.addEventListener('keydown', function (e) {
    if (box.hidden) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); moveActive(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); moveActive(-1); }
    else if (e.key === 'Enter' && active >= 0) {
      var btns = box.querySelectorAll('button');
      if (btns[active]) {
        e.preventDefault();
        btns[active].dispatchEvent(new Event('mousedown'));
      }
    } else if (e.key === 'Escape') {
      hide();
    }
  });
  good.addEventListener('blur', function () { setTimeout(hide, 120); });
  syncHidden();
})();
</script>
