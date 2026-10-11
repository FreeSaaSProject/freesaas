<?php
declare(strict_types=1);
/** @var array<string,mixed> $state */
$state = is_array($state ?? null) ? $state : [];
$mode = (string)($state['mode'] ?? '');
$clientId = (string)($state['client_id'] ?? '');
$braceletDisp = (string)($state['bracelet_disp'] ?? '');
$menu = is_array($state['menu'] ?? null) ? $state['menu'] : [];
$forms = is_array($state['forms'] ?? null) ? $state['forms'] : [];
$tables = is_array($state['tables'] ?? null) ? $state['tables'] : [];
$flashes = is_array($state['flashes'] ?? null) ? $state['flashes'] : [];
$raw = is_array($state['raw'] ?? null) ? $state['raw'] : null;
$configError = $state['config_error'] ?? null;
?>
<section class="card">
  <h1><?= te('terminals.onec.title') ?></h1>
  <p class="muted"><?= te('terminals.onec.intro') ?></p>

  <?php if ($configError !== null && $configError !== ''): ?>
    <p class="flash err"><?= e((string)$configError) ?></p>
    <p><a href="/terminals/settings"><?= te('terminals.menu.settings') ?></a>
      · <a href="/terminals/t/status"><?= te('terminals.t.status_title') ?></a></p>
  <?php else: ?>

    <?php if ($clientId !== ''): ?>
      <p>
        <i><?= te('terminals.onec.client_set') ?>: <?= e($clientId) ?>
          (<a href="/terminals/1c/client"><?= te('terminals.onec.view_client') ?></a>)</i>
        <?php if ($braceletDisp !== ''): ?>
          <i><?= te('terminals.onec.bracelet') ?>: <?= e($braceletDisp) ?></i>
        <?php endif; ?>
      </p>
      <form method="post" action="/terminals/1c" style="display:inline">
        <input type="hidden" name="clear_client" value="1">
        <button type="submit" class="btn secondary"><?= te('terminals.onec.clear_client') ?></button>
      </form>
    <?php else: ?>
      <p class="muted"><?= te('terminals.onec.need_client_hint') ?></p>
    <?php endif; ?>

    <p><a href="/terminals/1c"><?= te('terminals.onec.back_menu') ?></a></p>

    <?php foreach ($flashes as $f): ?>
      <?php
        $kind = is_array($f) ? (string)($f[0] ?? 'ok') : 'ok';
        $msg = is_array($f) ? (string)($f[1] ?? '') : (string)$f;
      ?>
      <p class="flash <?= e($kind) ?>"><?= e($msg) ?></p>
    <?php endforeach; ?>

    <?php if ($mode === ''): ?>
      <h2><?= te('terminals.onec.commands') ?></h2>
      <ul>
        <?php foreach ($menu as $item): ?>
          <?php if (!is_array($item)) {
              continue;
          } ?>
          <li>
            <a href="/terminals/1c/<?= e((string)$item['mode']) ?>"><?= e((string)$item['label']) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php foreach ($forms as $form): ?>
      <?php if (!is_array($form)) {
          continue;
      } ?>
      <?php if (($form['kind'] ?? '') === 'summ'): ?>
        <hr>
        <h3><?= te('terminals.onec.summ_title') ?></h3>
        <p class="muted"><?= te('terminals.onec.summ_hint') ?></p>
        <form method="post" action="/terminals/1c/price" class="stack">
          <input type="hidden" name="mode" value="price">
          <label><?= te('terminals.onec.field.summ') ?>
            <input type="number" name="summ_check" value="<?= (int)($form['value'] ?? 0) ?>">
          </label>
          <button type="submit" class="btn"><?= te('terminals.onec.summ_submit') ?></button>
        </form>
      <?php elseif (($form['kind'] ?? '') === 'get'): ?>
        <hr>
        <form method="get" action="/terminals/1c/<?= e((string)($form['mode'] ?? '')) ?>" class="stack">
          <?php foreach ((array)($form['fields'] ?? []) as $field): ?>
            <?php if (!is_array($field)) {
                continue;
            } ?>
            <label><?= e((string)($field['label'] ?? '')) ?>
              <input name="<?= e((string)($field['name'] ?? '')) ?>" value="<?= e((string)($field['value'] ?? '')) ?>">
            </label>
          <?php endforeach; ?>
          <button type="submit" class="btn"><?= te('terminals.onec.submit') ?></button>
        </form>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php foreach ($tables as $table): ?>
      <?php if (!is_array($table)) {
          continue;
      } ?>
      <hr>
      <h3><?= e((string)($table['title'] ?? '')) ?></h3>
      <?php if (($table['type'] ?? '') === 'price'): ?>
        <table class="data">
          <thead>
            <tr>
              <?php foreach ((array)($table['headers'] ?? []) as $h): ?>
                <th><?= e((string)$h) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ((array)($table['rows'] ?? []) as $row): ?>
              <?php if (!is_array($row)) {
                  continue;
              } ?>
              <tr>
                <td><?= e((string)$row[0]) ?></td>
                <td><?= e((string)$row[1]) ?></td>
                <td>
                  <?= e((string)$row[2]) ?>
                  <?php if ((string)($row[4] ?? '') !== ''): ?>
                    <br><b class="flash err"><?= e((string)$row[4]) ?></b>
                  <?php endif; ?>
                  <?php if (!empty($row[6])): ?>
                    <br><span class="muted"><?= te('terminals.onec.nonstandard_summ', ['summ' => (string)$row[5]]) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= e((string)$row[3]) ?></td>
                <td>
                  <form method="post" action="/terminals/1c/sale" style="display:inline">
                    <input type="hidden" name="purchase_id" value="<?= e((string)$row[0]) ?>">
                    <input type="hidden" name="price" value="<?= e((string)$row[5]) ?>">
                    <button type="submit" class="btn secondary"><?= te('terminals.onec.reg_sale') ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php elseif (($table['type'] ?? '') === 'debt'): ?>
        <ul>
          <?php foreach ((array)($table['rows'] ?? []) as $row): ?>
            <?php if (!is_array($row)) {
                continue;
            } ?>
            <li>
              [<?= e((string)$row[0]) ?>] / <?= e((string)$row[1]) ?> / <?= e((string)$row[2]) ?>:
              <?= e((string)$row[3]) ?>
              <?php if ((string)$row[0] !== ''): ?>
                <form method="post" action="/terminals/1c/unsale" style="display:inline">
                  <input type="hidden" name="document_id" value="<?= e((string)$row[0]) ?>">
                  <button type="submit" class="btn secondary" onclick="return confirm(<?= tj('terminals.onec.confirm_unsale') ?>)"><?= te('terminals.onec.unsale') ?></button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php elseif (($table['type'] ?? '') === 'tickets'): ?>
        <ul>
          <?php foreach ((array)($table['rows'] ?? []) as $row): ?>
            <?php if (!is_array($row)) {
                continue;
            } ?>
            <li>
              <?= e((string)$row[0]) ?>
              <form method="post" action="/terminals/1c/cardtoticket" style="display:inline">
                <input type="hidden" name="client" value="<?= e((string)$row[1]) ?>">
                <input type="hidden" name="package" value="<?= e((string)$row[2]) ?>">
                <input type="hidden" name="card" value="<?= e((string)$row[3]) ?>">
                <button type="submit" class="btn secondary"><?= te('terminals.onec.link_card', ['card' => (string)$row[3]]) ?></button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (($table['rows'] ?? []) === []): ?>
          <p class="muted"><?= te('terminals.onec.tickets_empty') ?></p>
        <?php endif; ?>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($raw !== null): ?>
      <hr>
      <p><a href="/terminals/1c"><?= te('terminals.onec.back_menu') ?></a></p>
      <h2><?= te('terminals.onec.raw_request') ?></h2>
      <pre class="codeblock"><?= e(json_encode([
          'method' => $raw['method'] ?? '',
          'url' => $raw['url'] ?? '',
          'headers_meta' => $raw['headers_meta'] ?? [],
          'body_sent' => $raw['body_sent'] ?? null,
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
      <h2><?= te('terminals.onec.raw_response') ?>
        [<?= e((string)($raw['http'] ?? '')) ?>]
        <?= !empty($raw['ok']) ? '' : ' — ' . e((string)($raw['error'] ?? '')) ?>
      </h2>
      <pre class="codeblock"><?php
        if (isset($raw['decoded']) && $raw['decoded'] !== null) {
            echo e(json_encode($raw['decoded'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            echo e((string)($raw['raw'] ?? ''));
        }
      ?></pre>
      <p><a href="/terminals/1c"><?= te('terminals.onec.back_menu') ?></a></p>
    <?php endif; ?>

  <?php endif; ?>
</section>
