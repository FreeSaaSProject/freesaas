<?php
declare(strict_types=1);
/** @var array<string,mixed> $payload */
$md = (string)($payload['markdown'] ?? '');
$menu = $payload['menu'] ?? [];
?>
<p class="muted"><?= e((string)($payload['summary'] ?? '')) ?></p>
<?php if (is_array($menu) && $menu !== []): ?>
  <p><strong><?= e(t('core.help.menu')) ?>:</strong>
    <?php
    $bits = [];
    foreach ($menu as $m) {
        if (!is_array($m)) {
            continue;
        }
        $bits[] = e((string)($m['title'] ?? '')) . ' (' . e((string)($m['path'] ?? '')) . ')';
    }
    echo implode(' · ', $bits);
    ?>
  </p>
<?php endif; ?>
<?php if ($md !== ''): ?>
  <pre class="help-markdown"><?= e($md) ?></pre>
<?php else: ?>
  <p class="muted"><?= e(t('core.help.no_markdown')) ?></p>
<?php endif; ?>
<p><a href="/help"><?= e(t('core.help.back')) ?></a></p>
