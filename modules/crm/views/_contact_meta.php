<?php
declare(strict_types=1);
/** @var array<string,mixed> $c contact link row (tel, mail, channels[]) */
$chTypes = class_exists('ClientChannelsRepository') ? ClientChannelsRepository::typeLabels() : [];
?>
<div class="muted crm-contact-meta">
  <?php if ((string)($c['tel'] ?? '') !== ''): ?><a href="tel:+<?= e(preg_replace('/\D+/', '', (string)$c['tel']) ?? '') ?>"><?= e((string)$c['tel']) ?></a><?php endif; ?>
  <?php if ((string)($c['mail'] ?? '') !== ''): ?><a href="mailto:<?= e((string)$c['mail']) ?>"><?= e((string)$c['mail']) ?></a><?php endif; ?>
  <?php foreach ((array)($c['channels'] ?? []) as $ch): $label = $chTypes[(string)$ch['type']] ?? (string)$ch['type']; ?>
    <span class="crm-channel" title="<?= e($label . ((string)$ch['label'] !== '' ? ' · ' . (string)$ch['label'] : '')) ?>">
      <span class="crm-channel-type"><?= e($label) ?>:</span>
      <?php if ((string)$ch['href'] !== ''): ?><a href="<?= e((string)$ch['href']) ?>" target="_blank" rel="noopener"><?= e((string)$ch['value']) ?></a><?php else: ?><?= e((string)$ch['value']) ?><?php endif; ?>
    </span>
  <?php endforeach; ?>
</div>
