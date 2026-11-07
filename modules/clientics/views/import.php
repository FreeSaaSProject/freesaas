<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,mixed>|null $report */
/** @var array<string,mixed>|null $last */
/** @var array<string,mixed>|null $last_appointments */
/** @var string $from */
/** @var string $to */
/** @var bool $api_ready */
/** @var bool $pull_enabled */
/** @var bool $push_enabled */
/** @var string $appointment_sot */
/** @var bool $waiting_list_available */
/** @var bool $medical_cards_available */
?>
<section class="page-head">
  <h1><?= te('clientics.import.title') ?></h1>
  <p class="muted"><?= te('clientics.import.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if (!$api_ready): ?>
  <div class="flash flash-warn">
    <?= te('clientics.status.missing') ?>
    — <a href="/clientics/settings"><?= te('clientics.menu.settings') ?></a>
  </div>
<?php endif; ?>

<section>
  <h2><?= te('clientics.import.section_range') ?></h2>
  <form method="post" class="form-grid">
    <label><?= te('clientics.import.from') ?>
      <input type="date" name="from" value="<?= e($from) ?>" required>
    </label>
    <label><?= te('clientics.import.to') ?>
      <input type="date" name="to" value="<?= e($to) ?>" required>
    </label>
    <label class="check"><input type="checkbox" name="dry_run" value="1" checked> <?= te('clientics.import.dry_run') ?></label>
    <div class="page-actions">
      <button class="btn-primary" type="submit" name="action" value="clients"<?= (!$pull_enabled || !$api_ready) ? ' disabled' : '' ?>><?= te('clientics.import.clients') ?></button>
      <button class="btn-secondary" type="submit" name="action" value="appointments"<?= (!$pull_enabled || !$api_ready) ? ' disabled' : '' ?>><?= te('clientics.import.appointments') ?></button>
    </div>
  </form>
</section>

<section>
  <h2><?= te('clientics.import.section_refs') ?></h2>
  <form method="post" class="form-grid">
    <label class="check"><input type="checkbox" name="dry_run" value="1" checked> <?= te('clientics.import.dry_run') ?></label>
    <div class="page-actions">
      <button class="btn-secondary" type="submit" name="action" value="users"<?= (!$pull_enabled || !$api_ready) ? ' disabled' : '' ?>><?= te('clientics.import.users') ?></button>
      <button class="btn-secondary" type="submit" name="action" value="services"<?= (!$pull_enabled || !$api_ready) ? ' disabled' : '' ?>><?= te('clientics.import.services') ?></button>
      <button class="btn-secondary" type="submit" name="action" value="inventory"<?= !$api_ready ? ' disabled' : '' ?>><?= te('clientics.import.inventory') ?></button>
      <?php if (!empty($waiting_list_available)): ?>
        <button class="btn-secondary" type="submit" name="action" value="waiting_list"<?= (!$pull_enabled || !$api_ready) ? ' disabled' : '' ?>><?= te('clientics.import.waiting_list') ?></button>
      <?php endif; ?>
      <?php if (!empty($push_enabled)): ?>
        <button class="btn-secondary" type="submit" name="action" value="outbox"<?= !$api_ready ? ' disabled' : '' ?>><?= te('clientics.import.outbox') ?></button>
      <?php endif; ?>
    </div>
  </form>
  <?php if (empty($medical_cards_available)): ?>
    <p class="muted"><?= te('clientics.import.medcards_skip') ?></p>
  <?php endif; ?>
</section>

<?php if (is_array($report)): ?>
<section>
  <h2><?= te('clientics.import.report') ?></h2>
  <pre class="code-block"><?= e(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
</section>
<?php endif; ?>

<section>
  <h2><?= te('clientics.import.last') ?></h2>
  <?php if (!is_array($last)): ?>
    <p class="muted"><?= te('clientics.status.none') ?></p>
  <?php else: ?>
    <pre class="code-block"><?= e(json_encode($last, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
  <?php endif; ?>
</section>

<section>
  <h2><?= te('clientics.import.last_appointments') ?></h2>
  <?php if (!is_array($last_appointments)): ?>
    <p class="muted"><?= te('clientics.status.none') ?></p>
  <?php else: ?>
    <pre class="code-block"><?= e(json_encode($last_appointments, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
  <?php endif; ?>
</section>
