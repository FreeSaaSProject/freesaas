<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array $schemaReport */
/** @var array $maintReport */
/** @var list<array{module:string,id:string}> $pending */
/** @var int $pendingCount */
/** @var bool $schemaNeeded */
/** @var bool $hasUsers */
/** @var int $userCount */
/** @var bool $canDb */
/** @var string $mode */
/** @var bool $isExpert */
/** @var list<string> $ownedTables */
/** @var list<string> $allTables */
/** @var list<string> $foreignTables */
/** @var list<array{file:string,bytes:int,mtime:int,stamp?:string,label?:string}> $dumps */
/** @var list<array{table:string,owned:bool,rows:int,data_bytes:int,index_bytes:int,total_bytes:int,engine:string}> $inventory */
/** @var bool $agentTokenOk */
/** @var list<array<string,mixed>> $diag */
/** @var int $diagNeedCount */
/** @var bool $diagRan */
/** @var int $diagAt */
$pending = $pending ?? [];
$pendingCount = (int)($pendingCount ?? count($pending));
$schemaNeeded = !empty($schemaNeeded);
$error = (string)($error ?? '');
$canDb = !empty($canDb);
$isExpert = !empty($isExpert);
$ownedTables = $ownedTables ?? [];
$allTables = $allTables ?? [];
$foreignTables = $foreignTables ?? [];
$dumps = $dumps ?? [];
$inventory = $inventory ?? [];
$agentTokenOk = !empty($agentTokenOk);
$maintReport = $maintReport ?? [];
$diag = $diag ?? [];
$diagNeedCount = (int)($diagNeedCount ?? 0);
$diagRan = !empty($diagRan);
$diagAt = (int)($diagAt ?? 0);
$ownedSet = array_fill_keys($ownedTables, true);
$hasUsers = !empty($hasUsers);
$userCount = (int)($userCount ?? 0);
$diagByTable = [];
foreach ($diag as $drow) {
    $diagByTable[(string)($drow['table'] ?? '')] = $drow;
}

/** Inline «?» help next to a heading/label (native details, no JS). */
$helpTip = static function (string ...$paragraphKeys): void {
    if ($paragraphKeys === []) {
        return;
    }
    ?>
  <details class="db-help">
    <summary title="<?= te('core.db.help_aria') ?>" aria-label="<?= te('core.db.help_aria') ?>">?</summary>
    <div class="db-help-body">
      <?php foreach ($paragraphKeys as $key): ?>
        <p><?= te($key) ?></p>
      <?php endforeach; ?>
    </div>
  </details>
    <?php
};

$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return (string)$bytes . ' B';
};

$selectBar = static function (string $scope, bool $withNeed = false): void {
    ?>
  <div class="db-select-bar actions">
    <button type="button" class="btn-secondary" data-db-sel="all" data-db-sel-scope="<?= e($scope) ?>"><?= te('core.db.sel.all') ?></button>
    <button type="button" class="btn-secondary" data-db-sel="owned" data-db-sel-scope="<?= e($scope) ?>"><?= te('core.db.sel.owned') ?></button>
    <button type="button" class="btn-secondary" data-db-sel="foreign" data-db-sel-scope="<?= e($scope) ?>"><?= te('core.db.sel.foreign') ?></button>
    <?php if ($withNeed): ?>
      <button type="button" class="btn-secondary" data-db-sel="need" data-db-sel-scope="<?= e($scope) ?>"><?= te('core.db.sel.need') ?></button>
    <?php endif; ?>
    <button type="button" class="btn-secondary" data-db-sel="none" data-db-sel-scope="<?= e($scope) ?>"><?= te('core.db.sel.none') ?></button>
  </div>
    <?php
};
?>
<section class="page-head">
  <p class="lede"><?= te('core.db.lede') ?></p>
</section>

<?php if ($canDb && !$isExpert): ?>
  <div class="actions" style="margin-bottom:1rem">
    <form method="post" style="display:inline"
          onsubmit="return confirm(<?= tj('core.db.mode.confirm_expert') ?>);">
      <input type="hidden" name="action" value="mode">
      <input type="hidden" name="mode" value="expert">
      <button class="btn-secondary" type="submit"><?= te('core.db.mode.to_expert') ?></button>
    </form>
    <span class="muted"><?= te('core.db.mode.normal_badge') ?></span>
  </div>
<?php endif; ?>

<?php if ($isExpert && $canDb): ?>
  <div class="flash flash-warn">
    <?= te('core.db.mode.expert_warn') ?>
    <form method="post" style="display:inline;margin-left:.5rem">
      <input type="hidden" name="action" value="mode">
      <input type="hidden" name="mode" value="normal">
      <button class="btn-secondary" type="submit"><?= te('core.db.mode.to_normal') ?></button>
    </form>
  </div>
<?php endif; ?>

<?php if (!$hasUsers): ?>
  <div class="flash flash-warn"><?= te('core.db.warn_no_users', ['n' => (string)$userCount]) ?></div>
<?php endif; ?>

<?php if ($message !== ''): ?>
  <div class="flash flash-ok"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="flash flash-warn"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($pendingCount > 0 || $schemaNeeded): ?>
<section class="calendar-section">
  <h2 class="subhead"><?= te('core.db.schema_heading') ?></h2>
  <p class="muted"><?= te('core.db.schema_help') ?></p>
  <?php if ($pending): ?>
    <ul class="report">
      <?php foreach ($pending as $row): ?>
        <li class="pending"><?= e($row['module'] . '/' . $row['id']) ?> — <?= te('core.modules.mig_pending') ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <div class="actions">
    <form method="post" id="db-schema-form" data-schema-longjob>
      <input type="hidden" name="action" value="schema">
      <button class="btn-primary" type="submit">
        <?= te('core.db.btn_schema') ?>
        <?php if ($pendingCount > 0): ?> (<?= (int)$pendingCount ?>)<?php endif; ?>
      </button>
    </form>
  </div>
  <?= View::capture('_longjob_panel', [
    'longjobId' => 'core-schema',
    'longjobTitle' => t('core.db.btn_schema'),
    'longjobDualBars' => false,
    'longjobShowBytes' => false,
  ], 'core') ?>
  <script>
  (function () {
    function boot() {
      var form = document.getElementById('db-schema-form');
      var overlay = document.getElementById('core-schema-overlay');
      if (!form || !overlay) return;
      if (!window.LongJobPanel) { setTimeout(boot, 30); return; }
      if (form.getAttribute('data-bound') === '1') return;
      form.setAttribute('data-bound', '1');
      var panel = LongJobPanel.mount({
        root: overlay,
        startUrl: '/db/schema/longjob',
        tickUrl: '/db/schema/longjob',
        cancelUrl: '/db/schema/longjob',
        dualBars: false,
        showBytes: false,
        reloadOnOk: true,
        csrf: <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>,
        i18n: {
          title: <?= json_encode(t('core.db.btn_schema'), JSON_UNESCAPED_UNICODE) ?>,
          starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
          badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
          doneTitle: <?= json_encode(t('core.longjob.done_title'), JSON_UNESCAPED_UNICODE) ?>,
          errorTitle: <?= json_encode(t('core.longjob.error_title'), JSON_UNESCAPED_UNICODE) ?>,
          cancelledTitle: <?= json_encode(t('core.longjob.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>,
          ok: <?= json_encode(t('core.longjob.ok'), JSON_UNESCAPED_UNICODE) ?>
        }
      });
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        panel.runFormData(new FormData(), {});
      });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
  })();
  </script>
</section>
<?php endif; ?>

<?php if ($canDb): ?>
<section class="calendar-section">
  <h2 class="subhead">
    <?= te('core.db.backups_heading') ?>
    <?php $helpTip(
        $isExpert ? 'core.db.backups_help_expert' : 'core.db.backups_help',
        'core.db.dump_name_hint'
    ); ?>
  </h2>

  <form method="post" id="db-dump-form" class="db-job-form" data-db-job="dump">
    <input type="hidden" name="action" value="db_dump">
    <input type="hidden" name="op" value="dump">
    <?php if ($isExpert && $allTables): ?>
      <?php $selectBar('dump'); ?>
      <div class="db-table-list" data-db-scope="dump">
        <?php foreach ($allTables as $t): ?>
          <label class="check">
            <input type="checkbox" name="tables[]" value="<?= e($t) ?>"
                   data-db-table="1" data-owned="<?= isset($ownedSet[$t]) ? '1' : '0' ?>"
                   <?= isset($ownedSet[$t]) ? ' checked' : '' ?>>
            <code><?= e($t) ?></code>
            <?php if (isset($ownedSet[$t])): ?>
              <span class="muted"><?= te('core.db.badge_owned') ?></span>
            <?php else: ?>
              <span class="muted"><?= te('core.db.badge_foreign') ?></span>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="actions">
      <button class="btn-primary" type="submit"><?= te('core.db.btn_dump') ?></button>
    </div>
  </form>

  <h3 class="subhead"><?= te('core.db.upload_heading') ?></h3>
  <form method="post" enctype="multipart/form-data" class="db-upload-form" id="db-upload-form">
    <input type="hidden" name="action" value="db_upload">
    <label class="field">
      <span>
        <?= te('core.db.upload_label') ?>
        <?php $helpTip('core.db.upload_help'); ?>
      </span>
      <input type="file" name="dump" required accept=".sql,.sql.gz,application/gzip,application/sql,text/plain">
    </label>
    <div class="actions">
      <button class="btn-secondary" type="submit"><?= te('core.db.btn_upload') ?></button>
    </div>
  </form>

  <form method="post" id="db-fetch-form" class="db-job-form db-fetch-form" data-db-job="fetch">
    <input type="hidden" name="action" value="db_fetch">
    <input type="hidden" name="op" value="fetch">
    <label class="field">
      <span>
        <?= te('core.db.fetch_url_label') ?>
        <?php $helpTip('core.db.fetch_url_help'); ?>
      </span>
      <input type="url" name="url" required placeholder="https://prod/agent/db/dump/file?file=NAME.sql.gz&amp;token=…"
             autocomplete="off" spellcheck="false">
    </label>
    <?php if (!$agentTokenOk): ?>
      <p class="muted"><?= te('core.db.fetch_token_missing') ?>
        <a href="/settings"><?= te('core.menu.settings') ?></a>
      </p>
    <?php endif; ?>
    <div class="actions">
      <button class="btn-secondary" type="submit"><?= te('core.db.btn_fetch') ?></button>
    </div>
  </form>

  <h3 class="subhead"><?= te('core.db.dumps_available') ?></h3>
  <?php if ($dumps): ?>
    <table class="data">
      <thead>
        <tr>
          <th><?= te('core.db.col.stamp') ?></th>
          <th><?= te('core.db.col.label') ?></th>
          <th><?= te('core.db.col.file') ?></th>
          <th><?= te('core.db.col.size') ?></th>
          <th><?= te('core.db.col.actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dumps as $d): ?>
          <?php
            $fetchUrl = $agentTokenOk ? DbTools::agentDumpFetchUrl((string)$d['file']) : null;
            $dumpLabel = trim((string)($d['label'] ?? ''));
          ?>
          <tr>
            <td><?= e(($d['stamp'] ?? '') !== '' ? (string)$d['stamp'] : date('Y-m-d H:i:s', (int)$d['mtime'])) ?></td>
            <td class="muted"><?= $dumpLabel !== '' ? e($dumpLabel) : '—' ?></td>
            <td><code><?= e($d['file']) ?></code></td>
            <td><?= e($formatBytes((int)$d['bytes'])) ?></td>
            <td class="actions">
              <a class="btn-secondary" href="/db?action=download&amp;file=<?= e($d['file']) ?>"><?= te('core.db.btn_download') ?></a>
              <?php if ($fetchUrl !== null): ?>
                <button type="button" class="btn-secondary" data-copy-url="<?= e($fetchUrl) ?>"
                        title="<?= te('core.db.btn_copy_fetch_title') ?>"><?= te('core.db.btn_copy_fetch') ?></button>
              <?php endif; ?>
              <form method="post" style="display:inline" class="db-job-form" data-db-job="restore"
                    data-db-confirm="<?= e(t('core.db.confirm_restore')) ?>">
                <input type="hidden" name="action" value="db_restore">
                <input type="hidden" name="op" value="restore">
                <input type="hidden" name="file" value="<?= e($d['file']) ?>">
                <button class="btn-primary" type="submit"><?= te('core.db.btn_restore') ?></button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm(<?= tj('core.db.confirm_delete_dump') ?>);">
                <input type="hidden" name="action" value="db_delete_dump">
                <input type="hidden" name="file" value="<?= e($d['file']) ?>">
                <button class="btn-secondary" type="submit"><?= te('core.common.delete') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted"><?= te('core.db.dumps_empty') ?></p>
  <?php endif; ?>
</section>

<section class="calendar-section">
  <details class="db-fold">
  <summary class="subhead"><?= te('core.db.maint_heading') ?></summary>
  <p class="muted"><?= te($isExpert ? 'core.db.maint_help_expert' : 'core.db.maint_help') ?></p>

  <div class="actions" style="margin-bottom:.75rem">
    <form method="post" class="db-job-form" data-db-job="diagnose" style="display:inline">
      <input type="hidden" name="action" value="db_diagnose">
      <input type="hidden" name="op" value="diagnose">
      <button class="btn-primary" type="submit" data-db-op="diagnose"><?= te('core.db.btn_diagnose') ?></button>
    </form>
  </div>

  <?php if (!$diagRan): ?>
    <p class="muted"><?= te('core.db.diag.not_run') ?></p>
  <?php elseif ($diagNeedCount > 0): ?>
    <div class="flash flash-warn"><?= te('core.db.diag.summary', ['n' => (string)$diagNeedCount]) ?></div>
    <?php if ($diagAt > 0): ?>
      <p class="muted"><?= te('core.db.diag.ran_at', ['when' => date('Y-m-d H:i:s', $diagAt)]) ?></p>
    <?php endif; ?>
    <ul class="report db-diag-list">
      <?php foreach ($diag as $row): ?>
        <?php if (empty($row['needs_repair']) && empty($row['needs_optimize'])) {
            continue;
        } ?>
        <li class="<?= !empty($row['needs_repair']) ? 'error' : 'pending' ?>">
          <code><?= e((string)$row['table']) ?></code>
          <?php foreach (($row['reasons'] ?? []) as $reason): ?>
            <span class="db-diag-reason"><?= te('core.db.diag.' . (string)$reason) ?></span>
          <?php endforeach; ?>
          <?php if (!empty($row['needs_repair']) && !empty($row['check_text'])): ?>
            <span class="muted">— <?= e((string)$row['check_text']) ?></span>
          <?php endif; ?>
          <?php if (!empty($row['needs_optimize']) && (int)($row['data_free'] ?? 0) > 0): ?>
            <span class="muted">(<?= te('core.db.diag.free_bytes', ['n' => $formatBytes((int)$row['data_free'])]) ?>)</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="muted"><?= te('core.db.diag.innodb_note') ?></p>
  <?php else: ?>
    <p class="muted"><?= te('core.db.diag.ok') ?></p>
    <?php if ($diagAt > 0): ?>
      <p class="muted"><?= te('core.db.diag.ran_at', ['when' => date('Y-m-d H:i:s', $diagAt)]) ?></p>
    <?php endif; ?>
    <p class="muted"><?= te('core.db.diag.innodb_note') ?></p>
  <?php endif; ?>

  <?php if ($isExpert && $allTables): ?>
    <form method="post" class="db-maint-form db-job-form" data-db-job="maint">
      <input type="hidden" name="op" value="check">
      <?php $selectBar('maint', true); ?>
      <div class="db-table-list" data-db-scope="maint">
        <?php foreach ($allTables as $t): ?>
          <?php
            $drow = $diagByTable[$t] ?? null;
            $need = $drow && (!empty($drow['needs_repair']) || !empty($drow['needs_optimize']));
            $cls = $need ? ' db-table-need' : '';
          ?>
          <label class="check<?= $cls ?>">
            <input type="checkbox" name="tables[]" value="<?= e($t) ?>"
                   data-db-table="1"
                   data-owned="<?= isset($ownedSet[$t]) ? '1' : '0' ?>"
                   data-need="<?= $need ? '1' : '0' ?>"
                   <?= $need ? ' checked' : '' ?>>
            <code><?= e($t) ?></code>
            <?php if (isset($ownedSet[$t])): ?>
              <span class="muted"><?= te('core.db.badge_owned') ?></span>
            <?php else: ?>
              <span class="muted"><?= te('core.db.badge_foreign') ?></span>
            <?php endif; ?>
            <?php if ($need && $drow): ?>
              <?php foreach (($drow['reasons'] ?? []) as $reason): ?>
                <span class="db-diag-tag"><?= te('core.db.diag.tag.' . (string)$reason) ?></span>
              <?php endforeach; ?>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="actions">
        <button class="btn-secondary" type="submit" name="action" value="db_check" data-db-op="check"><?= te('core.db.btn_check_sel') ?></button>
        <button class="btn-secondary" type="submit" name="action" value="db_repair" data-db-op="repair"
                onclick="return confirm(<?= tj('core.db.confirm_repair') ?>);"><?= te('core.db.btn_repair_sel') ?></button>
        <button class="btn-secondary" type="submit" name="action" value="db_optimize" data-db-op="optimize"><?= te('core.db.btn_optimize_sel') ?></button>
      </div>
    </form>
  <?php else: ?>
    <div class="actions">
      <form method="post" class="db-job-form" data-db-job="maint">
        <input type="hidden" name="action" value="db_check">
        <input type="hidden" name="op" value="check">
        <button class="btn-secondary" type="submit" data-db-op="check"><?= te('core.db.btn_check') ?></button>
      </form>
      <form method="post" class="db-job-form" data-db-job="maint" onsubmit="return confirm(<?= tj('core.db.confirm_repair') ?>);">
        <input type="hidden" name="action" value="db_repair">
        <input type="hidden" name="op" value="repair">
        <button class="btn-secondary" type="submit" data-db-op="repair"><?= te('core.db.btn_repair') ?></button>
      </form>
      <form method="post" class="db-job-form" data-db-job="maint">
        <input type="hidden" name="action" value="db_optimize">
        <input type="hidden" name="op" value="optimize">
        <button class="btn-secondary" type="submit" data-db-op="optimize"><?= te('core.db.btn_optimize') ?></button>
      </form>
    </div>
  <?php endif; ?>
  </details>
</section>

<?php if ($isExpert): ?>
<section class="calendar-section">
  <details class="db-fold">
  <summary class="subhead"><?= te('core.db.drop_heading') ?></summary>
  <p class="muted"><?= te('core.db.drop_help') ?></p>
  <?php if ($allTables === []): ?>
    <p class="muted"><?= te('core.db.tables_empty') ?></p>
  <?php else: ?>
    <form method="post" onsubmit="return confirm(<?= tj('core.db.confirm_drop') ?>);">
      <input type="hidden" name="action" value="db_drop">
      <?php $selectBar('drop'); ?>
      <div class="db-table-list" data-db-scope="drop">
        <?php foreach ($allTables as $t): ?>
          <label class="check">
            <input type="checkbox" name="tables[]" value="<?= e($t) ?>"
                   data-db-table="1" data-owned="<?= isset($ownedSet[$t]) ? '1' : '0' ?>">
            <code><?= e($t) ?></code>
            <?php if (isset($ownedSet[$t])): ?>
              <span class="muted"><?= te('core.db.badge_owned') ?></span>
            <?php else: ?>
              <span class="muted"><?= te('core.db.badge_foreign') ?></span>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
      <label class="check">
        <input type="checkbox" name="confirm_drop_owned" value="1">
        <?= te('core.db.confirm_drop_owned_label') ?>
      </label>
      <div class="actions">
        <button class="btn-primary" type="submit"><?= te('core.db.btn_drop') ?></button>
      </div>
    </form>
  <?php endif; ?>
  </details>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if ($schemaReport): ?>
<section class="calendar-section">
  <details class="db-fold">
  <summary class="subhead"><?= te('core.db.schema_report') ?></summary>
  <ul class="report">
    <?php foreach ($schemaReport as $r): ?>
      <li class="<?= e($r['status']) ?>"><?= e($r['module'].'/'.$r['id'].': '.$r['status']) ?><?= !empty($r['message']) ? ' — '.e($r['message']) : '' ?></li>
    <?php endforeach; ?>
  </ul>
  </details>
</section>
<?php endif; ?>

<?php if ($maintReport): ?>
<section class="calendar-section">
  <details class="db-fold">
  <summary class="subhead"><?= te('core.db.maint_report') ?></summary>
  <ul class="report">
    <?php foreach ($maintReport as $r): ?>
      <li class="<?= e((string)($r['status'] ?? 'info')) ?>">
        <?= e(($r['table'] ?? '') . ': ' . ($r['Msg_type'] ?? '') . ' — ' . ($r['Msg_text'] ?? '')) ?>
      </li>
    <?php endforeach; ?>
  </ul>
  </details>
</section>
<?php endif; ?>

<?php if ($canDb): ?>
<section class="calendar-section">
  <details class="db-fold">
  <summary class="subhead">
    <?= te('core.db.inventory_heading') ?>
    <span class="muted db-fold-meta"><?= te('core.db.owned_stat', ['n' => (string)count($ownedTables)]) ?></span>
  </summary>
  <p class="muted"><?= te('core.db.inventory_help') ?></p>
  <p class="muted"><?= te('core.db.inventory_users_stat', [
      'ok' => $hasUsers ? '✓' : '✗',
      'n' => (string)$userCount,
  ]) ?></p>
  <?php if ($inventory === []): ?>
    <p class="muted"><?= te('core.db.inventory_empty') ?></p>
  <?php else: ?>
    <?php
      $invRows = 0;
      $invBytes = 0;
      $invOwned = 0;
      foreach ($inventory as $inv) {
          $invRows += (int)$inv['rows'];
          $invBytes += (int)$inv['total_bytes'];
          if (!empty($inv['owned'])) {
              $invOwned++;
          }
      }
    ?>
    <p class="muted"><?= te('core.db.inventory_totals', [
        'tables' => (string)count($inventory),
        'owned' => (string)$invOwned,
        'foreign' => (string)(count($inventory) - $invOwned),
        'rows' => number_format($invRows, 0, '.', ' '),
        'size' => $formatBytes($invBytes),
    ]) ?></p>
    <table class="data">
      <thead>
        <tr>
          <th><?= te('core.db.col.table') ?></th>
          <th><?= te('core.db.col.scope') ?></th>
          <th><?= te('core.db.col.rows') ?></th>
          <th><?= te('core.db.col.size') ?></th>
          <th><?= te('core.db.col.engine') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($inventory as $inv): ?>
          <tr>
            <td><code><?= e((string)$inv['table']) ?></code></td>
            <td class="muted"><?= !empty($inv['owned']) ? te('core.db.badge_owned') : te('core.db.badge_foreign') ?></td>
            <td><?= e(number_format((int)$inv['rows'], 0, '.', ' ')) ?></td>
            <td title="<?= e($formatBytes((int)$inv['data_bytes']) . ' + ' . $formatBytes((int)$inv['index_bytes'])) ?>">
              <?= e($formatBytes((int)$inv['total_bytes'])) ?>
            </td>
            <td class="muted"><?= e((string)$inv['engine']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </details>
</section>
<?php endif; ?>

<?php if ($canDb): ?>
<?= View::capture('_longjob_panel', [
  'longjobId' => 'db-job',
  'longjobTitle' => t('core.db.job.title'),
  'longjobDualBars' => true,
  'longjobShowBytes' => true,
  'longjobDetailLabel' => t('core.db.job.bar_table'),
], 'core') ?>
<script>
(function () {
  function apply(scope, mode) {
    var root = document.querySelector('[data-db-scope="' + scope + '"]');
    if (!root) return;
    root.querySelectorAll('input[type="checkbox"][data-db-table]').forEach(function (cb) {
      if (mode === 'all') cb.checked = true;
      else if (mode === 'none') cb.checked = false;
      else if (mode === 'owned') cb.checked = cb.getAttribute('data-owned') === '1';
      else if (mode === 'foreign') cb.checked = cb.getAttribute('data-owned') !== '1';
      else if (mode === 'need') cb.checked = cb.getAttribute('data-need') === '1';
    });
  }
  document.querySelectorAll('[data-db-sel]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      apply(btn.getAttribute('data-db-sel-scope'), btn.getAttribute('data-db-sel'));
    });
  });

  var i18nCopy = {
    copied: <?= json_encode(t('core.db.copied_fetch'), JSON_UNESCAPED_UNICODE) ?>,
    copyPrompt: <?= json_encode(t('core.db.copy_fetch_prompt'), JSON_UNESCAPED_UNICODE) ?>
  };
  document.querySelectorAll('[data-copy-url]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-copy-url') || '';
      if (!url) return;
      var label = btn.textContent;
      function done() {
        btn.textContent = i18nCopy.copied;
        setTimeout(function () { btn.textContent = label; }, 1600);
      }
      function fallback() { window.prompt(i18nCopy.copyPrompt, url); }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(done).catch(fallback);
      } else {
        fallback();
      }
    });
  });

  // longjob.js is loaded in layout footer AFTER this inline script — wait for it.
  function bindLongJob() {
    var overlay = document.getElementById('db-job-overlay');
    if (!overlay) return;
    if (!window.LongJobPanel) {
      setTimeout(bindLongJob, 30);
      return;
    }
    LongJobPanel.bindForms({
      root: overlay,
      formSelector: 'form.db-job-form',
      startUrl: '/db',
      tickUrl: '/db',
      cancelUrl: '/db',
      startAction: 'db_job_start',
      tickAction: 'db_job_tick',
      cancelAction: 'db_job_cancel',
      dualBars: true,
      showBytes: true,
      title: <?= json_encode(t('core.db.job.title'), JSON_UNESCAPED_UNICODE) ?>,
      i18n: {
        title: <?= json_encode(t('core.db.job.title'), JSON_UNESCAPED_UNICODE) ?>,
        starting: <?= json_encode(t('core.db.job.starting'), JSON_UNESCAPED_UNICODE) ?>,
        badJson: <?= json_encode(t('core.db.job.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
        eta: <?= json_encode(t('core.db.job.eta'), JSON_UNESCAPED_UNICODE) ?>,
        barTable: <?= json_encode(t('core.db.job.bar_table'), JSON_UNESCAPED_UNICODE) ?>,
        barDetail: <?= json_encode(t('core.db.job.bar_table'), JSON_UNESCAPED_UNICODE) ?>,
        doneTitle: <?= json_encode(t('core.db.job.done_title'), JSON_UNESCAPED_UNICODE) ?>,
        errorTitle: <?= json_encode(t('core.db.job.error_title'), JSON_UNESCAPED_UNICODE) ?>,
        cancelledTitle: <?= json_encode(t('core.db.job.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>
      },
      mapFormExtras: function (form, ev) {
        var kind = form.getAttribute('data-db-job') || '';
        var op = form.querySelector('input[name="op"]');
        var opVal = op ? op.value : kind;
        var submitter = ev.submitter || document.activeElement;
        if (submitter && submitter.getAttribute) {
          var dop = submitter.getAttribute('data-db-op');
          if (dop) opVal = dop;
        }
        if (kind === 'dump') opVal = 'dump';
        if (kind === 'restore') opVal = 'restore';
        if (kind === 'diagnose') opVal = 'diagnose';
        if (kind === 'fetch') opVal = 'fetch';
        if (kind === 'data') opVal = 'data';
        if (['dump', 'restore', 'check', 'repair', 'optimize', 'diagnose', 'fetch', 'data'].indexOf(opVal) === -1) {
          return null;
        }
        return { op: opVal };
      }
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindLongJob);
  } else {
    bindLongJob();
  }
})();
</script>
<?php endif; ?>
