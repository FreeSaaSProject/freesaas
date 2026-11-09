<?php
declare(strict_types=1);
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
$idType = (int)($row['id_type'] ?? 0);
$isLimited = (int)($row['is_limited'] ?? 0);
$isPayer = (int)($row['is_payer'] ?? 0);
$isOasis = (int)($row['is_oasis'] ?? 0);
?>
<section class="page-head">
  <h1><?= te('terminals.fullstats.edit.title', ['type' => (string)($row['ticket_type'] ?? '')]) ?></h1>
  <p class="muted"><?= te('terminals.fullstats.edit.hint') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <p><strong><?= te('terminals.fullstats.col.title') ?>:</strong> <?= e((string)($row['title_full'] ?? '')) ?></p>
  <label><?= te('terminals.fullstats.col.id_type') ?>
    <select name="id_type"><?= TicketTypeEnums::optionsHtml(TicketTypeEnums::idTypeKeys(), $idType) ?></select>
  </label>
  <label><?= te('terminals.fullstats.col.is_limited') ?>
    <select name="is_limited"><?= TicketTypeEnums::optionsHtml(TicketTypeEnums::isLimitedKeys(), $isLimited) ?></select>
  </label>
  <label><?= te('terminals.fullstats.col.is_payer') ?>
    <select name="is_payer"><?= TicketTypeEnums::optionsHtml(TicketTypeEnums::isPayerKeys(), $isPayer) ?></select>
  </label>
  <label><?= te('terminals.fullstats.col.is_oasis') ?>
    <select name="is_oasis"><?= TicketTypeEnums::optionsHtml(TicketTypeEnums::isOasisKeys(), $isOasis) ?></select>
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/terminals/fullstats"><?= te('core.common.cancel') ?></a>
  </div>
</form>
