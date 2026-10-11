<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $stickers */
/** @var list<list<array<string,mixed>>> $pages */
/** @var bool $public_mode */
/** @var bool $auto_print */
$stickers = is_array($stickers ?? null) ? $stickers : [];
$pages = is_array($pages ?? null) ? $pages : StickerPrint::pages($stickers);
$public_mode = !empty($public_mode);
$auto_print = !empty($auto_print);
$cols = StickerPrint::PAGE_COLS;
$qrPx = StickerPrint::QR_SIZE;
$total = count($stickers);
?>
<?php if (!$public_mode): ?>
<section class="page-head no-print">
  <h1><?= te('stickers.print.title') ?></h1>
  <p class="muted"><?= te('stickers.print.intro', ['n' => $total, 'pages' => count($pages)]) ?></p>
  <div class="page-actions">
    <button type="button" class="btn-primary" onclick="window.print()"><?= te('stickers.print.do') ?></button>
    <a class="btn-secondary" href="/stickers/ins"><?= te('core.common.cancel') ?></a>
  </div>
</section>
<?php else: ?>
<section class="page-head no-print sticker-print-public-head">
  <p class="muted"><?= te('stickers.print.public_intro', ['n' => $total]) ?></p>
  <button type="button" class="btn-primary" onclick="window.print()"><?= te('stickers.print.do') ?></button>
</section>
<?php endif; ?>

<?php if ($stickers === []): ?>
  <p><?= te('stickers.print.empty') ?></p>
<?php else: ?>
  <?php foreach ($pages as $pageIndex => $pageStickers): ?>
    <?php
      $n = count($pageStickers);
      $i = 0;
      $isLast = ($pageIndex === count($pages) - 1);
    ?>
    <table class="sticker-print-sheet<?= $isLast ? '' : ' sticker-print-sheet--break' ?>" width="100%" cellpadding="0" cellspacing="0">
      <?php while ($i < $n): ?>
        <tr>
          <?php for ($c = 0; $c < $cols; $c++, $i++): ?>
            <?php if ($i >= $n): ?>
              <td class="sticker-print-text sticker-print-text--pad" aria-hidden="true">
                000. <?= te('stickers.col.pin') ?>: <b>000</b><br><b><?= te('stickers.print.pad_name') ?></b> <u>0</u>
              </td>
              <td class="sticker-print-qr sticker-print-qr--pad" aria-hidden="true"><div class="sticker-print-qr-pad"></div></td>
              <?php continue; ?>
            <?php endif; ?>
            <?php $s = $pageStickers[$i]; ?>
            <td class="sticker-print-text">
              <?= (int)$s['id'] ?>. <?= te('stickers.col.pin') ?>: <b><?= e((string)$s['pin']) ?></b>
              <?= e(date('d.m.y H:i', (int)$s['dt_create'])) ?><br>
              <b><?= e((string)$s['product_name']) ?></b>
              <u><?= e((string)$s['left']) ?></u>
            </td>
            <td class="sticker-print-qr"><?php
              // Trusted StickerQr output — safe_html()/html_sanitize strip <svg> by design.
              echo (string)($s['svg_html'] ?? $s['svg'] ?? '');
            ?></td>
          <?php endfor; ?>
        </tr>
      <?php endwhile; ?>
    </table>
  <?php endforeach; ?>
<?php endif; ?>

<style>
/* Public print uses layout_auth (auth-shell centers login cards) — undo for A4 sheet. */
body.auth-shell{display:block;align-items:stretch;justify-content:stretch;min-height:0;padding:0}
.sticker-print-public-head{max-width:40rem;margin:1rem auto;text-align:center}
.sticker-print-sheet{border-collapse:collapse;width:100%;margin:0;color:#000;background:#fff}
.sticker-print-sheet--break{page-break-after:always;break-after:page}
.sticker-print-text{font-size:3.5pt;padding:2px;text-align:right;vertical-align:middle;width:13%;color:#000}
.sticker-print-text--pad{color:#fff}
.sticker-print-qr{padding-top:11px;padding-right:2px;vertical-align:middle;width:7%}
.sticker-print-qr svg{width:<?= (int)$qrPx ?>px;height:<?= (int)$qrPx ?>px;display:block}
.sticker-print-qr-pad{border:1px solid #fff;width:<?= (int)$qrPx ?>px;height:<?= (int)$qrPx ?>px}
@media print{
  .no-print,.rail,.topbar,nav,header,footer,.breadcrumbs,.page-actions,.sticker-print-public-head,.auth-theme-bar{display:none!important}
  body,.auth-shell{background:#fff;color:#000;margin:0;padding:0;display:block}
  .sticker-print-sheet--break{page-break-after:always;break-after:page}
}
</style>
<?php if ($auto_print && $stickers !== []): ?>
<script>window.addEventListener('load',function(){setTimeout(function(){window.print()},200)});</script>
<?php endif; ?>
