<?php
declare(strict_types=1);
/** @var string $content */
/** @var string $title */
?><!DOCTYPE html>
<html lang="<?= e(I18n::locale()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'Site') ?></title>
</head>
<body>
<?= $content ?>
</body>
</html>
