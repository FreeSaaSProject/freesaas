<?php
/**
 * @var list<array{label:string, href?:?string, current?:bool}> $items
 * @var string $ariaLabel
 */
$returnUrl = class_exists('NavContext', false) ? NavContext::returnUrl() : '';
?>
<div class="breadcrumbs-row">
  <nav class="breadcrumbs" aria-label="<?= e($ariaLabel) ?>">
    <?php foreach ($items as $i => $item): ?>
      <?php if ($i > 0): ?><span class="bc-sep">/</span><?php endif; ?>
      <?php
        $label = (string)($item['label'] ?? '');
        $href = isset($item['href']) ? (string)$item['href'] : '';
        if ($href !== '' && class_exists('NavContext', false)) {
            $href = NavContext::up($href);
        }
        $current = !empty($item['current']);
      ?>
      <?php if ($href !== ''): ?>
        <a href="<?= e($href) ?>"<?= $current ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php else: ?>
        <span class="bc-current"<?= $current ? ' aria-current="page"' : '' ?>><?= e($label) ?></span>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <?php if ($returnUrl !== ''): ?>
    <a class="bc-return" href="<?= e($returnUrl) ?>">[<?= te('core.nav.return') ?>]</a>
  <?php endif; ?>
</div>
