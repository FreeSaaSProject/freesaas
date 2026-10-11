<?php
declare(strict_types=1);
/** @var array<string,mixed> $report */
$ok = !empty($report['ok']);
$codes = is_array($report['error_codes'] ?? null) ? $report['error_codes'] : [];
?>
<section class="page-head">
  <p class="muted"><?= e(t('core.doctor.intro')) ?></p>
</section>
<p>
  <strong><?= e(t('core.doctor.status')) ?>:</strong>
  <code><?= e((string)($report['status'] ?? '')) ?></code>
  <?= $ok ? te('core.common.yes') : te('core.common.no') ?>
</p>
<?php if ($codes !== []): ?>
<p><strong><?= e(t('core.doctor.codes')) ?>:</strong> <?= e(implode(', ', $codes)) ?></p>
<?php endif; ?>
<?php if (!empty($report['hints']) && is_array($report['hints'])): ?>
<ul>
  <?php foreach ($report['hints'] as $h): ?>
    <li><code><?= e((string)$h) ?></code></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
<pre class="code-block"><?= e(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
