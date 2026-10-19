<?php
declare(strict_types=1);
/** @var string $title */
/** @var array<string,mixed>|null $row null for samples */
/** @var string $html trusted: DocsRender output (sanitized template + escaped values) or frozen snapshot */
/** @var bool $printed */
/** @var string $error */
/** @var string $orientation */
/** @var string $format */
/** @var bool $autoPrint */
$sample = !empty($sample);
$backUrl = $sample ? (string)($backUrl ?? '/documents/defs') : '/documents/' . (int)($row['id'] ?? 0) . '/edit';
$page = ($format === 'A5' ? 'A5' : 'A4') . ' ' . ($orientation === 'landscape' ? 'landscape' : 'portrait');
?><!DOCTYPE html>
<html lang="<?= e(I18n::locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<style>
@page{size:<?= e($page) ?>;margin:14mm}
body{font-family:"PT Serif","Times New Roman",serif;font-size:11.5pt;color:#000;background:#eef0f2;margin:0}
.docs-print-toolbar{font-family:system-ui,sans-serif;font-size:14px;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;padding:.75rem 1rem;background:#fff;border-bottom:1px solid #d5d9de;position:sticky;top:0}
.docs-print-toolbar .muted{color:#667}
.docs-print-toolbar .warn{color:#a40}
.docs-print-toolbar button,.docs-print-toolbar a{font:inherit;padding:.4rem .8rem;border:1px solid #b9c0c8;border-radius:6px;background:#fff;color:#123;text-decoration:none;cursor:pointer}
.docs-print-toolbar .primary{background:#1f4f8a;border-color:#1f4f8a;color:#fff}
.docs-print-sheet{background:#fff;max-width:190mm;margin:1rem auto;padding:14mm;box-shadow:0 1px 4px rgba(0,0,0,.12)}
.docs-print-sheet h2{font-size:15pt;margin:.2em 0 .6em}
.docs-print-sheet h3{font-size:12pt;margin:1em 0 .3em}
.docs-print-fields,.docs-print-items{width:100%;border-collapse:collapse;margin:.3em 0 .8em}
.docs-print-fields th{width:34%;text-align:left;vertical-align:top;font-weight:600;padding:.25em .5em .25em 0}
.docs-print-fields td{vertical-align:top;padding:.25em 0;border-bottom:1px dotted #999}
.docs-print-items th,.docs-print-items td{border:1px solid #777;padding:.2em .4em}
.docs-print-qr{margin-top:1em;display:flex;align-items:center;gap:.6em}
.docs-print-qr small{font-size:8pt;color:#444}
.docs-print-copy+.docs-print-copy{page-break-before:always;break-before:page}
.docs-page-break{page-break-before:always;break-before:page;height:0}
@media screen{.docs-page-break{border-top:1px dashed #bbb;margin:1.5em 0}}
.docs-print-sheet table[border] th,.docs-print-sheet table[border] td{border:1px solid #000;vertical-align:top}
.docs-print-sheet table[border]{border-collapse:collapse;margin:.3em 0 .6em}
.docs-print-sheet th{font-weight:400;text-align:center}
.docs-f025-procedures{margin:.3em 0;padding-left:1.4em}
.docs-dossier-visit{page-break-inside:avoid;break-inside:avoid;border-top:1px solid #999;margin-top:1em}
.docs-print-sheet.is-sample{position:relative}
.docs-print-sheet.is-sample::before{content:attr(data-watermark);position:absolute;top:30%;left:0;right:0;text-align:center;font:700 72pt system-ui,sans-serif;color:rgba(200,30,30,.12);transform:rotate(-30deg);pointer-events:none;z-index:1}
@media print{
  body{background:#fff}
  .docs-print-toolbar{display:none}
  .docs-print-sheet{box-shadow:none;margin:0;padding:0;max-width:none}
}
</style>
</head>
<body>
<div class="docs-print-toolbar">
  <a href="<?= e($backUrl) ?>">&larr; <?= te($sample ? 'docs.sample.back' : 'docs.print.back') ?></a>
  <?php if ($sample): ?>
    <button type="button" class="primary" onclick="window.print()"><?= te('docs.print.do') ?></button>
    <span class="muted"><?= te('docs.sample.hint') ?></span>
  <?php elseif ($printed): ?>
    <button type="button" class="primary" onclick="window.print()"><?= te('docs.print.do') ?></button>
    <span class="muted"><?= te('docs.print.frozen', ['date' => I18n::formatDate((int)$row['dt_print'], 'datetime')]) ?></span>
  <?php else: ?>
    <form method="post" style="display:inline">
      <button type="submit" class="primary"><?= te('docs.print.freeze') ?></button>
    </form>
    <span class="muted"><?= te('docs.print.preview_hint') ?></span>
  <?php endif; ?>
  <?php if ($error !== ''): ?><span class="warn"><?= e($error) ?></span><?php endif; ?>
</div>
<div class="docs-print-sheet<?= $sample ? ' is-sample' : '' ?>"<?= $sample ? ' data-watermark="' . te('docs.sample.watermark') . '"' : '' ?>>
<?= $html ?>
</div>
<?php if ($autoPrint): ?>
<script>window.addEventListener('load',function(){setTimeout(function(){window.print()},200)});</script>
<?php endif; ?>
</body>
</html>
