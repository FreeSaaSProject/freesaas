<?php
declare(strict_types=1);
/** Shared language switcher (topbar / auth / rail). */
$curLocale = I18n::locale();
$returnPath = request_path();
$qs = $_SERVER['QUERY_STRING'] ?? '';
if ($qs !== '') {
    $returnPath .= '?' . $qs;
}
$langSwitchClass = isset($langSwitchClass) && is_string($langSwitchClass) && $langSwitchClass !== ''
    ? $langSwitchClass
    : 'lang-switch';
?>
<div class="<?= e($langSwitchClass) ?>" data-lang-switch role="group" aria-label="<?= te('core.layout.language') ?>">
  <?php foreach (I18n::supported() as $loc): ?>
    <?php
      $active = $loc === $curLocale;
      $href = '/locale?lang=' . rawurlencode($loc) . '&return=' . rawurlencode($returnPath);
    ?>
    <a href="<?= e($href) ?>"
       class="lang-switch-btn<?= $active ? ' is-active' : '' ?>"
       hreflang="<?= e($loc) ?>"
       <?= $active ? 'aria-current="true"' : '' ?>><?= e(strtoupper($loc)) ?></a>
  <?php endforeach; ?>
</div>
<?php
unset($langSwitchClass);
?>
