<?php
/**
 * Injected into calendar event edit via EventBus calendar.event.sections.
 *
 * @var array<string,mixed> $row
 * @var bool $isNew
 * @var bool $canModify
 * @var int $eventId
 * @var int $clientId
 * @var float $balance
 * @var bool $alreadyApplied
 * @var bool $canApply
 * @var array<string,mixed>|null $quote
 * @var string $returnUrl
 * @var string $applyFlash
 * @var string $applyError
 */
$readonly = !$canModify;
$bonusUse = !empty($row['bonus_use']);
$discount = (float)($row['bonus_discount'] ?? 0);
?>
<fieldset class="bonuses-event-block">
  <legend><?= te('bonuses.event.sec') ?></legend>

  <?php if ($applyFlash !== ''): ?>
    <div class="flash flash-ok"><?= e($applyFlash) ?></div>
  <?php endif; ?>
  <?php if ($applyError !== ''): ?>
    <div class="flash flash-warn"><?= e($applyError) ?></div>
  <?php endif; ?>

  <?php if ($clientId < 1): ?>
    <p class="muted"><?= te('bonuses.event.need_client') ?></p>
  <?php else: ?>
    <div class="bonuses-event-meta">
      <span><?= te('bonuses.event.balance', ['n' => number_format($balance, 0, '.', ' ')]) ?></span>
      <a href="/bonuses/clients/<?= (int)$clientId ?>"><?= te('bonuses.client.history') ?></a>
    </div>

    <?php if ($alreadyApplied): ?>
      <p class="flash flash-ok"><?= te('bonuses.event.applied') ?></p>
    <?php endif; ?>

    <label class="check">
      <input type="checkbox" name="bonus_use" value="1"<?= $bonusUse ? ' checked' : '' ?><?= $readonly || $alreadyApplied ? ' disabled' : '' ?>>
      <?= te('bonuses.event.use') ?>
    </label>
    <?php if ($readonly || $alreadyApplied): ?>
      <input type="hidden" name="bonus_use" value="<?= $bonusUse ? '1' : '0' ?>">
    <?php endif; ?>

    <label><?= te('bonuses.event.discount') ?>
      <input type="number" min="0" step="1" name="bonus_discount"
             value="<?= e((string)(int)round($discount > 0 ? $discount : ($bonusUse ? $balance : 0))) ?>"
             <?= $readonly || $alreadyApplied ? ' disabled' : '' ?>>
      <span class="form-hint"><?= te('bonuses.event.discount_hint') ?></span>
    </label>
    <?php if ($readonly || $alreadyApplied): ?>
      <input type="hidden" name="bonus_discount" value="<?= e((string)$discount) ?>">
    <?php endif; ?>

    <?php if (is_array($quote) && !empty($quote['ok'])): ?>
      <div class="bonuses-event-meta">
        <span><?= te('bonuses.event.quote_spend', ['n' => number_format((float)$quote['spend'], 0, '.', ' ')]) ?></span>
        <span><?= te('bonuses.event.quote_accrue', ['n' => number_format((float)$quote['accrue'], 0, '.', ' ')]) ?></span>
        <span><?= te('bonuses.event.quote_pay', ['n' => number_format((float)$quote['to_pay'], 0, '.', ' ')]) ?></span>
      </div>
    <?php endif; ?>

    <?php if ($canApply): ?>
      <div class="bonuses-event-actions">
        <button class="btn-secondary" type="submit"
                formaction="/bonuses/events/<?= (int)$eventId ?>/apply"
                formmethod="post"><?= te('bonuses.event.apply') ?></button>
        <span class="form-hint"><?= te('bonuses.event.apply_hint') ?></span>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</fieldset>
