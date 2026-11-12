<?php
declare(strict_types=1);
/**
 * @var bool $ready
 * @var list<array<string,mixed>> $hosts
 * @var array<string,array{module:string,label:string,max_inflight:int}> $services
 * @var array<string,mixed>|null $once
 */
$hosts = is_array($hosts ?? null) ? $hosts : [];
$services = is_array($services ?? null) ? $services : [];
$once = is_array($once ?? null) ? $once : null;
$fmtTime = static function (int $ts): string {
    return $ts > 0 ? date('d.m H:i:s', $ts) : '—';
};
?>
<section class="page-head">
  <p class="lede"><?= te('core.edge_hosts.lede') ?></p>
  <p class="muted"><?= te('core.edge_hosts.hint') ?></p>
</section>

<?php if ($once !== null && ($once['kind'] ?? '') === 'secret'): ?>
  <div class="flash flash-warn">
    <p><strong><?= te('core.edge_hosts.secret_once', ['name' => (string)($once['name'] ?? '')]) ?></strong></p>
    <p><code style="user-select:all;word-break:break-all"><?= e((string)($once['secret'] ?? '')) ?></code></p>
    <p class="muted small"><?= te(($once['field'] ?? '') === 'secret_next' ? 'core.edge_hosts.secret_next_hint' : 'core.edge_hosts.secret_hint') ?></p>
  </div>
<?php elseif ($once !== null && isset($once['text'])): ?>
  <div class="flash <?= ($once['kind'] ?? '') === 'ok' ? 'flash-ok' : 'flash-warn' ?>"><?= e((string)$once['text']) ?></div>
<?php endif; ?>

<?php if (!$ready): ?>
  <p class="muted"><?= te('core.edge_hosts.tables_missing') ?></p>
<?php else: ?>
<form method="post" action="/edge-hosts">
  <?php if ($hosts !== []): ?>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th><?= te('core.edge_hosts.col_name') ?></th>
          <th><?= te('core.edge_hosts.col_url') ?></th>
          <th><?= te('core.edge_hosts.col_key') ?></th>
          <th><?= te('core.edge_hosts.col_pin') ?></th>
          <th><?= te('core.edge_hosts.col_services') ?></th>
          <th><?= te('core.edge_hosts.col_enabled') ?></th>
          <th><?= te('core.edge_hosts.col_state') ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($hosts as $h): ?>
        <?php
          $hid = (int)$h['ID'];
          $prefix = 'hosts[' . $hid . ']';
          $plugins = EdgeHosts::plugins($h);
          $backoff = (int)($h['BACKOFF_UNTIL'] ?? 0);
        ?>
        <tr>
          <td><input type="text" name="<?= e($prefix) ?>[name]" value="<?= e((string)$h['NAME']) ?>" maxlength="100" size="12"></td>
          <td><input type="text" name="<?= e($prefix) ?>[base_url]" value="<?= e((string)$h['BASE_URL']) ?>" placeholder="https://203.0.113.10:18443" size="26"></td>
          <td><input type="text" name="<?= e($prefix) ?>[key_id]" value="<?= e((string)$h['KEY_ID']) ?>" maxlength="64" size="8"></td>
          <td><input type="text" name="<?= e($prefix) ?>[pin]" value="<?= e((string)$h['PIN_SHA256']) ?>" placeholder="base64 sha256" size="18"></td>
          <td class="small">
            <?php foreach ($services as $svc => $def): ?>
              <?php $p = $plugins[$svc] ?? ['enabled' => false, 'max_inflight' => $def['max_inflight']]; ?>
              <label class="checkbox">
                <input type="checkbox" name="<?= e($prefix) ?>[services][<?= e($svc) ?>][enabled]" value="1"<?= !empty($p['enabled']) ? ' checked' : '' ?>>
                <?= te($def['label']) ?>
              </label>
              <label class="muted"><?= te('core.edge_hosts.col_inflight') ?>
                <input type="number" min="1" max="200" name="<?= e($prefix) ?>[services][<?= e($svc) ?>][max_inflight]" value="<?= (int)$p['max_inflight'] ?>" style="width:4.5em">
              </label><br>
            <?php endforeach; ?>
          </td>
          <td><input type="checkbox" name="<?= e($prefix) ?>[enabled]" value="1"<?= (int)$h['ENABLED'] === 1 ? ' checked' : '' ?>></td>
          <td class="small">
            <?= te('core.edge_hosts.state_seen', ['at' => $fmtTime((int)$h['LAST_SEEN']), 'api' => (int)($h['API_VERSION'] ?? 0)]) ?><br>
            <?php foreach ($services as $svc => $def): ?>
              <?php $last = EdgeHosts::lastStatus($h, $svc); ?>
              <?php if ($last !== []): ?>
                <?= e($svc) ?>: <?= te('core.edge_hosts.state_service', [
                    'at' => $fmtTime((int)($last['at'] ?? 0)),
                    'free' => (int)($last['free_slots'] ?? 0),
                    'version' => (string)($last['version'] ?? '?'),
                ]) ?><br>
              <?php endif; ?>
            <?php endforeach; ?>
            <?php if ((string)$h['LAST_ERROR'] !== ''): ?>
              <strong><?= e((string)$h['LAST_ERROR']) ?></strong><br>
            <?php endif; ?>
            <?php if ($backoff > time()): ?>
              <?= te('core.edge_hosts.state_backoff', ['at' => $fmtTime($backoff)]) ?><br>
            <?php endif; ?>
            <span class="muted"><?= te('core.edge_hosts.state_secret', ['mask' => EdgeHosts::mask((string)$h['SECRET'])]) ?>
              <?= (string)$h['SECRET_NEXT'] !== '' ? te('core.edge_hosts.state_secret_next') : '' ?></span>
          </td>
          <td class="actions">
            <button class="btn-secondary btn-sm" type="submit" name="edge_action" value="test:<?= $hid ?>"><?= te('core.edge_hosts.btn_test') ?></button>
            <button class="btn-ghost btn-sm" type="submit" name="edge_action" value="rotate:<?= $hid ?>"
                    onclick="return confirm(<?= tj('core.edge_hosts.confirm_rotate') ?>)"><?= te('core.edge_hosts.btn_rotate') ?></button>
            <?php if ((string)$h['SECRET_NEXT'] !== ''): ?>
              <button class="btn-ghost btn-sm" type="submit" name="edge_action" value="promote:<?= $hid ?>"
                      onclick="return confirm(<?= tj('core.edge_hosts.confirm_promote') ?>)"><?= te('core.edge_hosts.btn_promote') ?></button>
            <?php endif; ?>
            <button class="btn-danger btn-sm" type="submit" name="edge_action" value="delete:<?= $hid ?>"
                    onclick="return confirm(<?= tj('core.edge_hosts.confirm_delete') ?>)"><?= te('core.common.delete') ?></button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p><button class="btn-primary" type="submit" name="edge_action" value="save:0"><?= te('core.common.save') ?></button></p>
  <?php else: ?>
    <p class="muted"><?= te('core.edge_hosts.empty') ?></p>
  <?php endif; ?>

  <fieldset class="form-fieldset">
    <legend><?= te('core.edge_hosts.add') ?></legend>
    <div class="form-row-inline">
      <label><?= te('core.edge_hosts.col_name') ?>
        <input type="text" name="new[name]" maxlength="100" placeholder="macstudio">
      </label>
      <label><?= te('core.edge_hosts.col_url') ?>
        <input type="text" name="new[base_url]" placeholder="https://203.0.113.10:18443">
      </label>
      <label><?= te('core.edge_hosts.col_key') ?>
        <input type="text" name="new[key_id]" value="owner" maxlength="64" size="8">
      </label>
      <label><?= te('core.edge_hosts.col_pin') ?>
        <input type="text" name="new[pin]" placeholder="base64 sha256">
      </label>
      <?php foreach ($services as $svc => $def): ?>
        <label class="checkbox"><input type="checkbox" name="new[services][<?= e($svc) ?>]" value="1"> <?= te($def['label']) ?></label>
      <?php endforeach; ?>
      <button class="btn-secondary" type="submit" name="edge_action" value="add:0"><?= te('core.edge_hosts.btn_add') ?></button>
    </div>
    <p class="muted small"><?= te('core.edge_hosts.add_hint') ?></p>
  </fieldset>
</form>
<?php endif; ?>
