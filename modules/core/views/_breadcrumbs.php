<?php
/**
 * @var list<array{label:string, href?:?string, current?:bool}> $items
 * @var string $ariaLabel
 */
?>
<nav class="breadcrumbs" aria-label="<?= e($ariaLabel) ?>">
  <?php foreach ($items as $i => $item): ?>
    <?php if ($i > 0): ?><span class="bc-sep">/</span><?php endif; ?>
    <?php
      $label = (string)($item['label'] ?? '');
      $href = isset($item['href']) ? (string)$item['href'] : '';
      $current = !empty($item['current']);
    ?>
    <?php if ($href !== ''): ?>
      <a href="<?= e($href) ?>"<?= $current ? ' aria-current="page" class="bc-current-link"' : '' ?>><?= e($label) ?></a>
    <?php else: ?>
      <span class="bc-current"<?= $current ? ' aria-current="page"' : '' ?>><?= e($label) ?></span>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>
