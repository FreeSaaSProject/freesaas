<?php
declare(strict_types=1);
/**
 * Injected into calendar event edit via EventBus calendar.event.sections.
 *
 * @var array<string,mixed> $row
 * @var bool $isNew
 * @var bool $canModify
 * @var int $eventId
 * @var int $clientId
 * @var list<array<string,mixed>> $activeCerts
 * @var bool $canApply
 * @var string $returnUrl
 * @var string $applyFlash
 * @var string $applyError
 */
?>
<fieldset class="certificates-event-block">
  <legend><?= te('certificates.event.sec') ?></legend>

  <?php if ($applyFlash !== ''): ?>
    <div class="flash flash-ok"><?= e($applyFlash) ?></div>
  <?php endif; ?>
  <?php if ($applyError !== ''): ?>
    <div class="flash flash-warn"><?= e($applyError) ?></div>
  <?php endif; ?>

  <?php if ($clientId < 1): ?>
    <p class="muted"><?= te('certificates.event.need_client') ?></p>
  <?php else: ?>
    <?php if ($activeCerts !== []): ?>
      <p class="muted"><?= te('certificates.event.active') ?></p>
      <ul class="certificates-event-list">
        <?php foreach ($activeCerts as $c): ?>
          <li>
            <code><?= e((string)$c['code']) ?></code>
            · <?= e(CertificatesRepository::kindLabel((int)$c['kind'])) ?>
            · <?= e(number_format((float)$c['balance'], 0, '.', ' ')) ?>
            <a href="/certificates/<?= (int)$c['id'] ?>/edit">#<?= (int)$c['id'] ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($canApply && !$isNew): ?>
      <form method="post" action="/certificates/events/<?= (int)$eventId ?>/apply" class="certificates-event-apply">
        <input type="hidden" name="ret" value="<?= e($returnUrl) ?>">
        <label><?= te('certificates.event.code') ?>
          <input name="cert_code" list="certificates-active-codes" required>
          <datalist id="certificates-active-codes">
            <?php foreach ($activeCerts as $c): ?>
              <option value="<?= e((string)$c['code']) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </label>
        <button class="btn-secondary" type="submit"><?= te('certificates.event.apply') ?></button>
        <span class="form-hint"><?= te('certificates.event.apply_hint') ?></span>
      </form>
    <?php endif; ?>

    <p><a href="/certificates/new?event=<?= (int)$eventId ?>&buyer=<?= (int)$clientId ?>"><?= te('certificates.list.new') ?></a>
      · <a href="/certificates?id_holder=<?= (int)$clientId ?>"><?= te('certificates.client.link') ?></a></p>
  <?php endif; ?>
</fieldset>
