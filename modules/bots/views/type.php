<?php
declare(strict_types=1);
/** @var string $audience */
/** @var list<array<string,mixed>> $channels */
/** @var list<array<string,mixed>> $menu */
/** @var array{active:int,pending:int,module_off:int,missing:int,total:int} $counts */
/** @var list<string> $preview */
/** @var list<array<string,mixed>> $plugins */
/** @var int $pending */
?>
<section class="page-head">
  <p class="lede"><?= te('bots.type.lede.' . $audience) ?></p>
  <p class="muted"><?= te('bots.health.queue', ['n' => $pending]) ?></p>
  <p class="muted">
    <?= te('bots.catalog.summary', [
        'active' => (int)$counts['active'],
        'pending' => (int)$counts['pending'],
        'off' => (int)$counts['module_off'],
        'missing' => (int)$counts['missing'],
    ]) ?>
  </p>
  <p>
    <a href="/bots/sends?audience=<?= e($audience) ?>"><?= te('bots.menu.sends') ?></a>
    ·
    <a href="/bots/settings"><?= te('bots.menu.settings') ?></a>
  </p>
</section>

<section class="widget">
  <h2><?= te('bots.type.sec.menu') ?></h2>
  <p class="muted"><?= te('bots.catalog.hint') ?></p>
  <?php if ($menu === []): ?>
    <p class="muted"><?= te('bots.plugins.empty') ?></p>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th><?= te('bots.type.col.label') ?></th>
        <th><?= te('bots.plugins.col.id') ?></th>
        <th><?= te('bots.type.col.module') ?></th>
        <th><?= te('bots.catalog.col.status') ?></th>
        <th><?= te('bots.catalog.col.kind') ?></th>
        <th><?= te('bots.plugins.col.handlers') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($menu as $row): ?>
      <?php
        $st = (string)($row['status'] ?? '');
        $tone = $st === 'active' ? 'ok' : ($st === 'pending' ? 'warn' : 'muted');
      ?>
      <tr>
        <td>
          <?= e((string)$row['label']) ?>
          <?php if (!empty($row['extra'])): ?>
            <span class="muted">· <?= te('bots.catalog.extra') ?></span>
          <?php endif; ?>
        </td>
        <td><code><?= e((string)$row['id']) ?></code></td>
        <td><?= e((string)(($row['module'] ?? '') !== '' ? $row['module'] : '—')) ?></td>
        <td><span class="tone-<?= e($tone) ?>"><?= e((string)$row['status_label']) ?></span></td>
        <td><?= e(t('bots.catalog.kind.' . (string)($row['kind'] ?? 'cabinet'))) ?></td>
        <td><?= e($row['handlers'] !== [] ? implode(', ', $row['handlers']) : '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<section class="widget">
  <h2><?= te('bots.type.sec.preview') ?></h2>
  <p class="muted"><?= te('bots.catalog.preview_hint') ?></p>
  <?php if ($preview === []): ?>
    <p class="muted"><?= te('bots.catalog.preview_empty') ?></p>
  <?php else: ?>
  <ol class="bots-cabinet-preview">
    <?php foreach ($preview as $label): ?>
      <li><?= e($label) ?></li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
</section>

<section class="widget">
  <h2><?= te('bots.type.sec.channels') ?></h2>
  <?php if ($channels === []): ?>
    <p class="muted"><?= te('bots.type.channels_empty') ?></p>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th><?= te('bots.settings.platform.telegram') ?> / <?= te('bots.settings.platform.max') ?></th>
        <th><?= te('bots.settings.token') ?></th>
        <th><?= te('bots.settings.secret') ?></th>
        <th><?= te('bots.settings.webhook') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($channels as $ch): ?>
      <tr>
        <td><?= e(t('bots.settings.platform.' . $ch['platform'])) ?></td>
        <td><?= !empty($ch['has_token']) ? e((string)$ch['token_masked']) : '—' ?></td>
        <td><?= !empty($ch['has_secret']) ? '••••' : '—' ?></td>
        <td><code><?= e((string)$ch['webhook_path']) ?></code></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
