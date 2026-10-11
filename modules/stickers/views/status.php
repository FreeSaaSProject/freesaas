<?php
declare(strict_types=1);
/** @var array<string,mixed> $report */
/** @var bool $can_toggle */
/** @var bool $public_print_enabled */
/** @var string $public_print_url */
$report = is_array($report ?? null) ? $report : [];
$issues = is_array($report['issues'] ?? null) ? $report['issues'] : [];
$can_toggle = !empty($can_toggle);
$public_print_enabled = !empty($public_print_enabled);
$public_print_url = (string)($public_print_url ?? '');
?>
<section class="page-head"><h1><?= te('stickers.status.title') ?></h1></section>
<p class="muted"><?= te('stickers.status.intro') ?></p>
<p><strong><?= te('stickers.status.status') ?>:</strong> <?= e((string)($report['status'] ?? '')) ?></p>
<p><?= te('stickers.status.public_host') ?>: <code><?= e((string)($report['public_host'] ?? '')) ?></code></p>
<p><?= te('stickers.status.sample_url') ?>: <code><?= e((string)($report['sample_qr_url'] ?? '')) ?></code></p>

<section class="card" style="margin:1.5rem 0;padding:1rem;max-width:40rem">
  <h2><?= te('stickers.status.public_print_title') ?></h2>
  <p class="muted"><?= te('stickers.status.public_print_hint') ?></p>
  <p>
    <?= te('stickers.status.public_print_state') ?>:
    <strong><?= $public_print_enabled ? te('stickers.status.public_print_enabled') : te('stickers.status.public_print_disabled') ?></strong>
  </p>
  <?php if ($public_print_enabled && $public_print_url !== ''): ?>
    <p><?= te('stickers.status.public_print_link') ?>: <a href="<?= e($public_print_url) ?>" target="_blank" rel="noopener"><?= e($public_print_url) ?></a></p>
  <?php endif; ?>
  <?php if ($can_toggle): ?>
    <form method="post" action="/stickers/status" class="form">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="public_print">
      <label class="check">
        <input type="checkbox" name="public_print_enabled" value="1"<?= $public_print_enabled ? ' checked' : '' ?>>
        <?= te('stickers.status.public_print_enable') ?>
      </label>
      <div class="form-actions">
        <button type="submit" class="btn-primary"><?= te('stickers.status.save') ?></button>
      </div>
    </form>
  <?php else: ?>
    <p class="muted"><?= te('stickers.status.public_print_superadmin') ?></p>
  <?php endif; ?>
</section>

<?php if ($issues !== []): ?>
  <h2><?= te('stickers.status.issues') ?></h2>
  <ul>
    <?php foreach ($issues as $i): ?>
      <li>[<?= e((string)($i['level'] ?? '')) ?>] <?= e((string)($i['message'] ?? '')) ?></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
