<?php
declare(strict_types=1);
/** @var array<string,mixed> $report */
/** @var bool $can_settings */
$report = is_array($report ?? null) ? $report : [];
$issues = is_array($report['issues'] ?? null) ? $report['issues'] : [];
$setup = is_array($report['setup'] ?? null) ? $report['setup'] : [];
$can_settings = !empty($can_settings);
$settingsUrl = (string)($report['settings_url'] ?? '/stickers/settings');
$cookieDomain = (string)($report['session_cookie_domain'] ?? '');
$suggest = (string)($report['session_cookie_domain_suggested'] ?? '');
?>
<section class="page-head"><h1><?= te('stickers.status.title') ?></h1></section>
<p class="muted"><?= te('stickers.status.intro') ?></p>
<p>
  <strong><?= te('stickers.status.status') ?>:</strong> <?= e((string)($report['status'] ?? '')) ?>
  <?php if ($can_settings): ?>
    · <a href="<?= e($settingsUrl) ?>"><?= te('stickers.menu.settings') ?></a>
  <?php endif; ?>
</p>

<section class="card" style="margin:1.5rem 0;padding:1rem;max-width:44rem">
  <h2><?= te('stickers.status.hosts_title') ?></h2>
  <p><?= te('stickers.status.admin_host') ?>: <code><?= e((string)($report['admin_host'] ?? '')) ?></code></p>
  <p><?= te('stickers.status.public_host') ?>: <code><?= e((string)($report['public_host'] ?? '')) ?></code></p>
  <p><?= te('stickers.status.cookie_domain') ?>:
    <code><?= e($cookieDomain !== '' ? $cookieDomain : '—') ?></code>
    <?php if ($suggest !== '' && $cookieDomain === ''): ?>
      <span class="muted">(<?= te('stickers.status.cookie_suggest', ['suggest' => $suggest]) ?>)</span>
    <?php endif; ?>
  </p>
  <p><?= te('stickers.status.sample_url') ?>: <code><?= e((string)($report['sample_qr_url'] ?? '')) ?></code></p>
</section>

<?php if ($setup !== []): ?>
<section class="card" style="margin:1.5rem 0;padding:1rem;max-width:44rem">
  <h2><?= te('stickers.status.setup_title') ?></h2>
  <p class="muted"><?= te('stickers.status.setup_intro') ?></p>
  <ol class="stickers-qr-setup">
    <?php foreach ($setup as $step):
      if (!is_array($step)) {
          continue;
      }
      $ok = array_key_exists('ok', $step) ? $step['ok'] : null;
      if ($ok === true) {
          $mark = te('stickers.status.setup.mark_ok');
      } elseif ($ok === false) {
          $mark = te('stickers.status.setup.mark_need');
      } else {
          $mark = te('stickers.status.setup.mark_info');
      }
      $href = trim((string)($step['href'] ?? ''));
      ?>
      <li>
        <strong>[<?= e($mark) ?>]</strong>
        <?= e((string)($step['title'] ?? '')) ?>
        <?php if (!empty($step['detail'])): ?>
          <div class="muted" style="margin:.25rem 0 .75rem"><?= e((string)$step['detail']) ?></div>
        <?php endif; ?>
        <?php if ($href !== '' && $can_settings): ?>
          <p><a class="btn-primary" href="<?= e($href) ?>"><?= te('stickers.status.setup.open_settings') ?></a></p>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>

<section class="card" style="margin:1.5rem 0;padding:1rem;max-width:44rem">
  <h2><?= te('stickers.status.public_print_title') ?></h2>
  <p class="muted"><?= te('stickers.status.public_print_hint') ?></p>
  <p>
    <?= te('stickers.status.public_print_state') ?>:
    <strong><?= !empty($report['public_print_enabled']) ? te('stickers.status.public_print_enabled') : te('stickers.status.public_print_disabled') ?></strong>
  </p>
  <?php if (!empty($report['public_print_enabled']) && !empty($report['public_print_url'])): ?>
    <p><?= te('stickers.status.public_print_link') ?>:
      <a href="<?= e((string)$report['public_print_url']) ?>" target="_blank" rel="noopener"><?= e((string)$report['public_print_url']) ?></a>
    </p>
  <?php endif; ?>
  <?php if ($can_settings): ?>
    <p><a href="<?= e($settingsUrl) ?>"><?= te('stickers.status.public_print_edit') ?></a></p>
  <?php else: ?>
    <p class="muted"><?= te('stickers.status.public_print_superadmin') ?></p>
  <?php endif; ?>
</section>

<?php if ($issues !== []): ?>
  <h2><?= te('stickers.status.issues') ?></h2>
  <ul>
    <?php foreach ($issues as $i):
      if (!is_array($i)) {
          continue;
      }
      ?>
      <li>
        <strong>[<?= e((string)($i['level'] ?? '')) ?>]</strong>
        <?= e((string)($i['message'] ?? '')) ?>
        <?php if (!empty($i['fix'])): ?>
          <div class="muted" style="margin:.25rem 0 .75rem"><?= e((string)$i['fix']) ?></div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
