<?php
declare(strict_types=1);
/** Shared language switcher (topbar / auth / rail) — select scales past 2 locales. */
$curLocale = I18n::locale();
$returnPath = request_path();
$qs = $_SERVER['QUERY_STRING'] ?? '';
if ($qs !== '') {
    $returnPath .= '?' . $qs;
}
$langSwitchClass = isset($langSwitchClass) && is_string($langSwitchClass) && $langSwitchClass !== ''
    ? $langSwitchClass
    : 'lang-switch';
$supportedLocales = I18n::supported();
?>
<form method="get" action="/locale" class="<?= e($langSwitchClass) ?>" data-lang-switch>
  <input type="hidden" name="return" value="<?= e($returnPath) ?>">
  <label class="lang-switch-label">
    <span class="visually-hidden"><?= te('core.layout.language') ?></span>
    <select name="lang"
            class="lang-switch-select"
            aria-label="<?= te('core.layout.language') ?>"
            onchange="this.form.submit()">
      <?php foreach ($supportedLocales as $loc): ?>
        <option value="<?= e($loc) ?>"<?= $loc === $curLocale ? ' selected' : '' ?>><?= e(I18n::localeLabel($loc)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <noscript>
    <button type="submit" class="lang-switch-go"><?= te('core.layout.language') ?></button>
  </noscript>
</form>
<?php
unset($langSwitchClass, $supportedLocales);
?>
