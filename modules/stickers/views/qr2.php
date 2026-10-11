<?php
declare(strict_types=1);
/** @var array<string,mixed> $result */
/** @var string $hash */
/** @var bool $confirm_post */
$r = is_array($result ?? null) ? $result : [];
$token = (string)($r['token'] ?? '');
$phase = (string)($r['phase'] ?? '');
$messages = is_array($r['messages'] ?? null) ? $r['messages'] : [];
$forms = is_array($r['forms'] ?? null) ? $r['forms'] : [];
$actions = is_array($r['actions'] ?? null) ? $r['actions'] : [];
?>
<section class="qr-scan">
  <h1><?= te('stickers.qr.title') ?></h1>
  <?php if (!empty($r['product_name'])): ?>
    <p class="qr-scan__product"><?= e((string)$r['product_name']) ?></p>
    <p class="qr-scan__meta muted">
      <?= te('stickers.col.stock') ?>: <?= e((string)($r['stock_name'] ?? '')) ?>
      · #<?= (int)($r['sticker_id'] ?? 0) ?>
      · <?= te('stickers.col.left') ?>: <strong><?= e((string)($r['remaining'] ?? '')) ?></strong>
    </p>
  <?php endif; ?>
  <p class="muted"><?= te('stickers.qr.phase') ?>: <?= e($phase) ?></p>

  <ul class="qr-scan__msgs">
    <?php foreach ($messages as $m): ?>
      <?php
        $level = (string)($m['level'] ?? 'info');
        $cls = $level === 'err' ? 'flash-warn' : ($level === 'ok' ? 'flash-ok' : 'flash');
      ?>
      <li class="flash <?= e($cls) ?>"><?= e((string)($m['text'] ?? '')) ?></li>
    <?php endforeach; ?>
  </ul>

  <?php if (!empty($r['done'])): ?>
    <p class="qr-scan__done"><a class="btn-primary" href="javascript:window.close();"><?= te('stickers.qr.done_close') ?></a></p>
  <?php endif; ?>

  <?php foreach ($forms as $form): ?>
    <?php
      $type = (string)($form['type'] ?? '');
      $name = (string)($form['name'] ?? '');
    ?>
    <form method="post" action="/qr2.php" class="qr-scan__form form">
      <input type="hidden" name="q" value="<?= e($hash) ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <?php if ($type === 'proc_event'): ?>
        <fieldset>
          <legend><?= e((string)($form['label'] ?? '')) ?></legend>
          <label class="check">
            <input type="radio" name="proc_event" value="-1_-1" required>
            <?= te('stickers.qr.free_act') ?>
          </label>
          <?php foreach ((array)($form['events'] ?? []) as $ev): ?>
            <label class="check">
              <input type="radio" name="proc_event" value="<?= e((string)($ev['value'] ?? '')) ?>">
              #<?= (int)($ev['id'] ?? 0) ?>
              <?= e((string)($ev['operator_name'] ?? '')) ?>
              · <?= e((string)($ev['client_name'] ?? '')) ?>
              · <?= e((string)($ev['product_name'] ?? '')) ?>
              (<?= e(date('H:i', (int)($ev['dtstart'] ?? 0))) ?>)
            </label>
          <?php endforeach; ?>
          <?php if ((array)($form['events'] ?? []) === []): ?>
            <p class="muted"><?= te('stickers.qr.no_events') ?></p>
          <?php endif; ?>
        </fieldset>
        <button class="btn-primary" type="submit"><?= te('stickers.qr.action.save_proc') ?></button>
      <?php elseif ($type === 'stock'): ?>
        <label><?= e((string)($form['label'] ?? '')) ?>
          <select name="<?= e($name) ?>" required>
            <option value=""><?= te('stickers.qr.choose_stock') ?></option>
            <?php foreach ((array)($form['options'] ?? []) as $sid => $sname): ?>
              <option value="<?= (int)$sid ?>"<?= (int)($form['value'] ?? 0) === (int)$sid ? ' selected' : '' ?>>
                <?= e((string)$sname) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn-primary" type="submit" name="action" value="movement"><?= te('stickers.qr.action.movement') ?></button>
      <?php elseif ($type === 'rash'): ?>
        <p><?= te('stickers.qr.form.amount_return') ?>: <strong><?= e((string)($form['amount'] ?? '')) ?></strong></p>
        <input type="hidden" name="amount" value="<?= e((string)($form['amount'] ?? '')) ?>">
        <input type="hidden" name="doCancel" value="1">
        <input type="hidden" name="cancel" value="1">
        <ul class="qr-scan__rash">
          <?php foreach ((array)($form['items'] ?? []) as $it): ?>
            <?php if ((float)($it['qtyu'] ?? 0) < (float)($form['amount'] ?? 0)) {
                continue;
            } ?>
            <li>
              <button class="btn-secondary" type="submit" name="idRash" value="<?= (int)($it['id'] ?? 0) ?>">
                #<?= (int)($it['id'] ?? 0) ?>
                <?= e(date('d.m.Y H:i', (int)($it['dt_create'] ?? 0))) ?>
                · <?= e((string)($it['qtyu'] ?? '')) ?>
              </button>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <label><?= e((string)($form['label'] ?? $name)) ?>
          <input type="<?= $type === 'amount' ? 'number' : 'text' ?>"
                 name="<?= e($name) ?>"
                 value="<?= e((string)($form['value'] ?? '')) ?>"
                 step="any"
                 required
                 inputmode="decimal">
        </label>
        <?php if (!empty($form['confirm_needed'])): ?>
          <label class="check">
            <input type="hidden" name="confirm" value="0">
            <input type="checkbox" name="confirm" value="1">
            <?= te('stickers.qr.confirm_value') ?>
          </label>
        <?php endif; ?>
        <button class="btn-primary" type="submit" name="doDismiss" value="1"><?= te('core.common.save') ?></button>
      <?php endif; ?>
    </form>
  <?php endforeach; ?>

  <?php foreach ($actions as $act): ?>
    <?php
      $fields = is_array($act['fields'] ?? null) ? $act['fields'] : [];
      $action = (string)($act['action'] ?? '');
    ?>
    <form method="post" action="/qr2.php" class="qr-scan__action form">
      <input type="hidden" name="q" value="<?= e($hash) ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <input type="hidden" name="action" value="<?= e($action) ?>">
      <?php foreach ($fields as $fk => $fv): ?>
        <input type="hidden" name="<?= e((string)$fk) ?>" value="<?= e((string)$fv) ?>">
      <?php endforeach; ?>
      <?php if ($action === 'activate' || $action === 'reprint' || $action === 'revision_pass'): ?>
        <button class="btn-primary" type="submit"><?= e((string)($act['label'] ?? $action)) ?></button>
      <?php else: ?>
        <button class="btn-secondary" type="submit"><?= e((string)($act['label'] ?? $action)) ?></button>
      <?php endif; ?>
    </form>
  <?php endforeach; ?>

  <?php if (!empty($confirm_post) && $forms === [] && $actions === []): ?>
    <form method="post" action="/qr2.php" class="form">
      <input type="hidden" name="q" value="<?= e($hash) ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <?php foreach ($_GET as $gk => $gv): ?>
        <?php if ($gk === 'q' || $gk === 'token' || !is_scalar($gv)) {
            continue;
        } ?>
        <input type="hidden" name="<?= e((string)$gk) ?>" value="<?= e((string)$gv) ?>">
      <?php endforeach; ?>
      <button class="btn-primary" type="submit"><?= te('stickers.qr.confirm_post') ?></button>
    </form>
  <?php endif; ?>
</section>
<style>
.qr-scan{max-width:42rem;margin:1rem auto;padding:1rem;font-size:1.25rem}
.qr-scan__product{font-size:1.6rem;font-weight:700;margin:0.5rem 0}
.qr-scan__form,.qr-scan__action{margin:1rem 0;padding:1rem 0;border-top:1px solid var(--line)}
.qr-scan input[type=text],.qr-scan input[type=number],.qr-scan select{font-size:1.4rem;width:100%;padding:0.5rem}
.qr-scan button{font-size:1.3rem;margin:0.35rem 0;min-height:2.75rem}
.qr-scan__msgs{list-style:none;padding:0;margin:1rem 0}
.qr-scan__msgs li{margin:0.35rem 0}
</style>
