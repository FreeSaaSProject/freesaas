<?php
/** @var string $content */
/** @var string $title */
$brand = (string)Config::get('app', 'name', 'FreeSaaS');
$htmlLang = I18n::locale();
$jsI18n = I18n::jsDict(['crm.reply_hint', 'crm.link_prompt']);
?>
<!DOCTYPE html>
<html lang="<?= e($htmlLang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? t('core.login.submit')) ?> · <?= e($brand) ?></title>
  <script>
  (function () {
    try {
      var k = 'freesaas.theme';
      var m = localStorage.getItem(k) || 'system';
      var dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      var t = m === 'system' ? (dark ? 'dark' : 'light') : m;
      document.documentElement.setAttribute('data-theme', t);
      document.documentElement.setAttribute('data-theme-mode', m);
    } catch (e) {
      document.documentElement.setAttribute('data-theme', 'dark');
      document.documentElement.setAttribute('data-theme-mode', 'system');
    }
  })();
  window.FREESAAS_I18N = <?= json_encode($jsI18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,700&family=Syne:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/crm.css">
</head>
<body class="auth-shell">
  <div class="auth-theme-bar">
    <?php require __DIR__ . '/_lang_switch.php'; ?>
    <div class="theme-switch" data-theme-switch role="group" aria-label="<?= te('core.layout.theme') ?>">
      <button type="button" data-theme-set="light" title="<?= te('core.layout.theme_light') ?>" aria-label="<?= te('core.layout.theme_light_aria') ?>">☀</button>
      <button type="button" data-theme-set="dark" title="<?= te('core.layout.theme_dark') ?>" aria-label="<?= te('core.layout.theme_dark_aria') ?>">☾</button>
      <button type="button" data-theme-set="system" title="<?= te('core.layout.theme_system') ?>" aria-label="<?= te('core.layout.theme_system_aria') ?>">◐</button>
    </div>
  </div>
  <?= $content ?>
  <script src="/assets/js/crm.js"></script>
</body>
</html>
