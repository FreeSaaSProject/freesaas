<?php
declare(strict_types=1);
/** @var array<string,mixed> $payload */
/** @var string $phone */
$phone = $phone ?? '';
$pr = is_array($payload['password_reset'] ?? null) ? $payload['password_reset'] : [];
$hints = is_array($pr['hints'] ?? null) ? $pr['hints'] : [];
$recent = is_array($pr['recent_resets'] ?? null) ? $pr['recent_resets'] : [];
$client = is_array($pr['client'] ?? null) ? $pr['client'] : null;
?>
<h1><?= te('client_portal.status.title') ?></h1>
<p class="page-actions">
  <a href="/client-portal/settings"><?= te('client_portal.menu.settings') ?></a>
  <?php if (ModuleManager::isEnabled('bots') && Auth::can('bots.view')): ?>
    · <a href="/bots/settings"><?= te('client_portal.status.link_bots_settings') ?></a>
    · <a href="/bots/sends?audience=client"><?= te('client_portal.status.link_bots_sends') ?></a>
  <?php endif; ?>
</p>

<section class="card" style="margin-bottom:1.5rem">
  <h2><?= te('client_portal.status.reset_title') ?></h2>
  <p class="muted"><?= te('client_portal.status.reset_help') ?></p>
  <ul>
    <li><?= te('client_portal.status.table') ?>: <?= !empty($pr['table_ready']) ? 'OK' : '—' ?></li>
    <li>Telegram token: <?= !empty($pr['client_telegram']['has_token']) ? 'OK' : '—' ?>
      <?php if ((string)($pr['client_telegram']['username'] ?? '') !== ''): ?>
        · @<?= e((string)$pr['client_telegram']['username']) ?>
        · <a href="<?= e((string)$pr['client_telegram']['deep_link']) ?>" target="_blank" rel="noopener">t.me</a>
      <?php endif; ?>
    </li>
    <li>Max token: <?= !empty($pr['client_max']['has_token']) ? 'OK' : '—' ?>
      <?php if ((string)($pr['client_max']['bot_url'] ?? '') !== ''): ?>
        · <a href="<?= e((string)$pr['client_max']['bot_url']) ?>" target="_blank" rel="noopener">URL</a>
      <?php endif; ?>
    </li>
  </ul>
  <?php if ($hints !== []): ?>
    <div class="flash flash-warn">
      <?php foreach ($hints as $h): ?>
        <?php
          if (is_string($h)) {
              $msg = $h;
              $href = '';
              $linkLabel = '';
          } else {
              $msg = (string)($h['message'] ?? '');
              $href = (string)($h['href'] ?? '');
              $linkLabel = (string)($h['link_label'] ?? '');
          }
        ?>
        <div>
          <?= e($msg) ?>
          <?php if ($href !== ''): ?>
            — <a href="<?= e($href) ?>"><?= e($linkLabel !== '' ? $linkLabel : $href) ?></a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="get" class="form-inline" style="margin:1rem 0">
    <label><?= te('client_portal.login.phone') ?>
      <input type="tel" name="phone" value="<?= e($phone) ?>" class="js-phone-ru" placeholder="+7 (___) ___-__-__">
    </label>
    <button type="submit" class="btn"><?= te('client_portal.status.check_phone') ?></button>
  </form>
  <?php if ($client !== null): ?>
    <pre class="code-block"><?= e(json_encode($client, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
  <?php endif; ?>

  <h3><?= te('client_portal.status.recent') ?></h3>
  <?php if ($recent === []): ?>
    <p class="muted"><?= te('client_portal.status.recent_empty') ?></p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>client_id</th>
          <th>channel</th>
          <th>created</th>
          <th>expire</th>
          <th>used</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent as $row): ?>
          <tr>
            <td><?= (int)$row['id'] ?></td>
            <td><a href="/clients/<?= (int)$row['client_id'] ?>"><?= (int)$row['client_id'] ?></a></td>
            <td><?= e((string)$row['channel']) ?></td>
            <td><?= e(date('Y-m-d H:i', (int)$row['dt_create'])) ?></td>
            <td><?= e(date('Y-m-d H:i', (int)$row['dt_expire'])) ?><?= !empty($row['expired']) ? ' ✗' : '' ?></td>
            <td><?= !empty($row['used']) ? date('Y-m-d H:i', (int)$row['dt_used']) : '—' ?></td>
            <td><?= e((string)$row['ip']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <p class="muted">
    <?= te('client_portal.status.sends_hint') ?>
    <a href="/bots/sends?audience=client"><?= te('client_portal.status.link_bots_sends') ?></a>
  </p>
</section>

<details>
  <summary>JSON</summary>
  <pre class="code-block"><?= e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
</details>
