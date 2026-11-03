<?php
declare(strict_types=1);
/**
 * Chrome-free print layout: no rail, notices, topbar, footer.
 * Used by catalog price sheets (and similar full-bleed print pages).
 *
 * @var string $content
 * @var string $title
 * @var string|null $print_page_size  e.g. "A4 landscape" / "A4 portrait"
 */
$brand = (string)Config::get('app', 'name', 'FreeSaaS');
$instance = class_exists('SystemRepository', false) ? SystemRepository::companyName() : '';
$titleSuffix = $instance !== '' ? $instance : $brand;
$htmlLang = I18n::locale();
$pageSize = trim((string)($print_page_size ?? 'A4 landscape'));
if ($pageSize === '') {
    $pageSize = 'A4 landscape';
}
?>
<!DOCTYPE html>
<html lang="<?= e($htmlLang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e((string)($title ?? '')) ?> · <?= e($titleSuffix) ?></title>
  <style>
    /* Browsers often clip near edges; keep a clear printable margin. */
    @page { size: <?= e($pageSize) ?>; margin: 10mm; }
    html, body {
      margin: 0;
      padding: 0;
      background: #fff;
      color: #000;
      width: 100%;
      max-width: none;
    }
    body.price-print-shell {
      display: block;
      min-height: 100vh;
      box-sizing: border-box;
    }
    @media print {
      body.price-print-shell {
        padding: 0;
      }
    }
  </style>
  <?= Assets::cssTags() ?>
</head>
<body class="price-print-shell">
<?= $content ?>
<?= Assets::jsTags() ?>
</body>
</html>
