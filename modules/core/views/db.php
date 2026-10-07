<?php
/** @var string $message */
/** @var string $error */
/** @var array $schemaReport */
/** @var array $dataReport */
/** @var array $maintReport */
/** @var list<array{module:string,id:string}> $pending */
/** @var int $pendingCount */
/** @var bool $schemaNeeded */
/** @var bool $hasUsers */
/** @var bool $hasSystem */
/** @var int $userCount */
/** @var bool $canDb */
/** @var string $mode */
/** @var bool $isExpert */
/** @var list<string> $ownedTables */
/** @var list<string> $allTables */
/** @var list<string> $foreignTables */
/** @var list<array{file:string,bytes:int,mtime:int,stamp?:string,label?:string}> $dumps */
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
$maintReport = $maintReport ?? [];
$diag = $diag ?? [];
$diagNeedCount = (int)($diagNeedCount ?? 0);
$diagRan = !empty($diagRan);
$diagAt = (int)($diagAt ?? 0);
$ownedSet = array_fill_keys($ownedTables, true);
$diagByTable = [];
foreach ($diag as $drow) {
    $diagByTable[(string)($drow['table'] ?? '')] = $drow;
}

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

<?php if ($canDb): ?>
  <div class="actions" style="margin-bottom:1rem">
    <?php if ($isExpert): ?>
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="mode">
        <input type="hidden" name="mode" value="normal">
        <button class="btn-secondary" type="submit"><?= te('core.db.mode.to_normal') ?></button>
      </form>
      <span class="muted"><?= te('core.db.mode.expert_badge') ?></span>
    <?php else: ?>
      <form method="post" style="display:inline"
            onsubmit="return confirm(<?= tj('core.db.mode.confirm_expert') ?>);">
        <input type="hidden" name="action" value="mode">
        <input type="hidden" name="mode" value="expert">
        <button class="btn-secondary" type="submit"><?= te('core.db.mode.to_expert') ?></button>
      </form>
      <span class="muted"><?= te('core.db.mode.normal_badge') ?></span>
    <?php endif; ?>
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

<div class="status-row">
  <div class="stat<?= $hasUsers ? ' ok' : '' ?>">base_users <?= $hasUsers ? '✓' : '✗' ?> (<?= (int)$userCount ?>)</div>
  <div class="stat<?= $hasSystem ? ' ok' : '' ?>">base_system <?= $hasSystem ? '✓' : '✗' ?></div>
  <div class="stat<?= $pendingCount === 0 ? ' ok' : '' ?>">
    <?= te('core.db.pending_stat', ['n' => (string)$pendingCount]) ?>
  </div>
  <div class="stat">
    <?= te('core.db.owned_stat', ['n' => (string)count($ownedTables)]) ?>
  </div>
</div>

<?php if ($message !== ''): ?>
  <div class="flash flash-ok"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="flash flash-warn"><?= e($error) ?></div>
<?php endif; ?>

<section class="calendar-section">
  <h2 class="subhead"><?= te('core.db.schema_heading') ?></h2>
  <p class="muted"><?= te('core.db.schema_help') ?></p>

  <?php if ($pending): ?>
    <ul class="report">
      <?php foreach ($pending as $row): ?>
        <li class="pending"><?= e($row['module'] . '/' . $row['id']) ?> — <?= te('core.modules.mig_pending') ?></li>
      <?php endforeach; ?>
    </ul>
  <?php elseif (!$schemaNeeded): ?>
    <p class="muted"><?= te('core.db.schema_up_to_date') ?></p>
  <?php endif; ?>

  <div class="actions">
    <form method="post">
      <input type="hidden" name="action" value="schema">
      <button class="btn-primary" type="submit"<?= $pendingCount < 1 && !$schemaNeeded ? ' disabled' : '' ?>>
        <?= te('core.db.btn_schema') ?>
        <?php if ($pendingCount > 0): ?> (<?= (int)$pendingCount ?>)<?php endif; ?>
      </button>
    </form>
    <?php if (!$schemaNeeded && $pendingCount < 1): ?>
      <a class="btn-secondary" href="/"><?= te('core.menu.home') ?></a>
    <?php endif; ?>
  </div>
</section>

<section class="calendar-section">
  <h2 class="subhead"><?= te('core.db.data_heading') ?></h2>
  <p class="muted"><?= te('core.db.data_help') ?></p>
  <div class="actions">
    <form method="post" onsubmit="return confirm(<?= tj('core.db.confirm_data') ?>);">
      <input type="hidden" name="action" value="data">
      <button class="btn-secondary" type="submit"<?= $schemaNeeded ? ' disabled' : '' ?>>
        <?= te('core.db.btn_data') ?>
      </button>
    </form>
  </div>
</section>

<?php if ($canDb): ?>
<section class="calendar-section">
  <h2 class="subhead"><?= te('core.db.backups_heading') ?></h2>
  <p class="muted"><?= te($isExpert ? 'core.db.backups_help_expert' : 'core.db.backups_help') ?></p>
  <p class="muted"><?= te('core.db.dump_name_hint') ?></p>

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

  <h3 class="subhead"><?= te('core.db.dumps_available') ?></h3>
  <p class="muted"><?= te('core.db.dumps_available_help') ?></p>

  <form method="post" id="db-fetch-form" class="db-job-form db-fetch-form" data-db-job="fetch">
    <input type="hidden" name="action" value="db_fetch">
    <input type="hidden" name="op" value="fetch">
    <label class="field">
      <span><?= te('core.db.fetch_url_label') ?></span>
      <input type="url" name="url" required placeholder="https://example.com/db?action=download&amp;file=….sql.gz"
             autocomplete="off" spellcheck="false">
    </label>
    <p class="muted"><?= te('core.db.fetch_url_help') ?></p>
    <div class="actions">
      <button class="btn-secondary" type="submit"><?= te('core.db.btn_fetch') ?></button>
    </div>
  </form>

  <?php if ($dumps): ?>
    <table class="data">
      <thead>
        <tr>
          <th><?= te('core.db.col.stamp') ?></th>
          <th><?= te('core.db.col.file') ?></th>
          <th><?= te('core.db.col.size') ?></th>
          <th><?= te('core.db.col.actions') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dumps as $d): ?>
          <tr>
            <td><?= e(($d['stamp'] ?? '') !== '' ? (string)$d['stamp'] : date('Y-m-d H:i:s', (int)$d['mtime'])) ?></td>
            <td><code><?= e($d['file']) ?></code></td>
            <td><?= e($formatBytes((int)$d['bytes'])) ?></td>
            <td class="actions">
              <a class="btn-secondary" href="/db?action=download&amp;file=<?= e($d['file']) ?>"><?= te('core.db.btn_download') ?></a>
              <form method="post" style="display:inline" class="db-job-form" data-db-job="restore"
                    onsubmit="return confirm(<?= tj('core.db.confirm_restore') ?>);">
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
  <h2 class="subhead"><?= te('core.db.maint_heading') ?></h2>
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
</section>

<?php if ($isExpert): ?>
<section class="calendar-section">
  <h2 class="subhead"><?= te('core.db.drop_heading') ?></h2>
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
</section>
<?php endif; ?>
<?php endif; ?>

<?php if ($schemaReport): ?>
  <h2 class="subhead"><?= te('core.db.schema_report') ?></h2>
  <ul class="report">
    <?php foreach ($schemaReport as $r): ?>
      <li class="<?= e($r['status']) ?>"><?= e($r['module'].'/'.$r['id'].': '.$r['status']) ?><?= !empty($r['message']) ? ' — '.e($r['message']) : '' ?></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($dataReport): ?>
  <h2 class="subhead"><?= te('core.db.data_report') ?></h2>
  <ul class="report">
    <?php foreach ($dataReport as $r): ?>
      <li class="<?= e($r['status']) ?>">
        <?= e($r['module'].'/'.$r['id'].': '.$r['status']) ?>
        <?php if (!empty($r['stats']['copied'])): ?>
          — <?= te('core.db.copied', ['n' => (int)$r['stats']['copied']]) ?>
        <?php endif; ?>
        <?php if (!empty($r['message'])): ?> — <?= e($r['message']) ?><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($maintReport): ?>
  <h2 class="subhead"><?= te('core.db.maint_report') ?></h2>
  <ul class="report">
    <?php foreach ($maintReport as $r): ?>
      <li class="<?= e((string)($r['status'] ?? 'info')) ?>">
        <?= e(($r['table'] ?? '') . ': ' . ($r['Msg_type'] ?? '') . ' — ' . ($r['Msg_text'] ?? '')) ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($canDb): ?>
<div id="db-job-overlay" class="db-job-overlay" hidden>
  <div class="db-job-panel" role="dialog" aria-modal="true" aria-labelledby="db-job-title">
    <h3 id="db-job-title" class="subhead"><?= te('core.db.job.title') ?></h3>
    <p id="db-job-msg" class="db-job-msg muted"></p>

    <div class="db-job-bars">
      <div class="db-job-bar-block">
        <div class="db-job-bar-label">
          <span id="db-job-table-label"><?= te('core.db.job.bar_table') ?></span>
          <span id="db-job-pct-table">0%</span>
        </div>
        <div class="db-job-bar" id="db-job-bar-table" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
          <div id="db-job-fill-table" class="db-job-fill db-job-fill-table"></div>
        </div>
      </div>
      <div class="db-job-bar-block">
        <div class="db-job-bar-label">
          <span><?= te('core.db.job.bar_overall') ?></span>
          <span id="db-job-pct-overall">0%</span>
        </div>
        <div class="db-job-bar" id="db-job-bar-overall" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
          <div id="db-job-fill-overall" class="db-job-fill"></div>
        </div>
      </div>
    </div>

    <div class="db-job-metric" id="db-job-meta">
      <span id="db-job-elapsed">0:00</span>
      <span id="db-job-eta" class="muted"></span>
      <span id="db-job-bytes" class="muted">0 MB</span>
    </div>

    <div class="db-job-actions actions">
      <button type="button" class="btn-secondary" id="db-job-abort"><?= te('core.db.job.abort') ?></button>
      <button type="button" class="btn-primary" id="db-job-ok" hidden disabled><?= te('core.db.job.ok') ?></button>
    </div>
  </div>
</div>
<script>
(function () {
  function apply(scope, mode) {
    var root = document.querySelector('[data-db-scope="' + scope + '"]');
    if (!root) return;
    root.querySelectorAll('input[type="checkbox"][data-db-table]').forEach(function (cb) {
      if (mode === 'all') cb.checked = true;
      else if (mode === 'none') cb.checked = false;
      else if (mode === 'owned') cb.checked = cb.getAttribute('data-owned') === '1';
      else if (mode === 'need') cb.checked = cb.getAttribute('data-need') === '1';
    });
  }
  document.querySelectorAll('[data-db-sel]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      apply(btn.getAttribute('data-db-sel-scope'), btn.getAttribute('data-db-sel'));
    });
  });

  var overlay = document.getElementById('db-job-overlay');
  var msgEl = document.getElementById('db-job-msg');
  var fillOverall = document.getElementById('db-job-fill-overall');
  var fillTable = document.getElementById('db-job-fill-table');
  var barOverall = document.getElementById('db-job-bar-overall');
  var barTable = document.getElementById('db-job-bar-table');
  var pctOverall = document.getElementById('db-job-pct-overall');
  var pctTable = document.getElementById('db-job-pct-table');
  var tableLabel = document.getElementById('db-job-table-label');
  var elapsedEl = document.getElementById('db-job-elapsed');
  var etaEl = document.getElementById('db-job-eta');
  var bytesEl = document.getElementById('db-job-bytes');
  var abortBtn = document.getElementById('db-job-abort');
  var okBtn = document.getElementById('db-job-ok');
  var titleEl = document.getElementById('db-job-title');

  var activeJobId = null;
  var aborting = false;
  var finished = false;
  var timerIv = null;
  var startedAt = 0;

  var i18n = {
    starting: <?= json_encode(t('core.db.job.starting'), JSON_UNESCAPED_UNICODE) ?>,
    badJson: <?= json_encode(t('core.db.job.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
    eta: <?= json_encode(t('core.db.job.eta'), JSON_UNESCAPED_UNICODE) ?>,
    barTable: <?= json_encode(t('core.db.job.bar_table'), JSON_UNESCAPED_UNICODE) ?>,
    doneTitle: <?= json_encode(t('core.db.job.done_title'), JSON_UNESCAPED_UNICODE) ?>,
    errorTitle: <?= json_encode(t('core.db.job.error_title'), JSON_UNESCAPED_UNICODE) ?>,
    cancelledTitle: <?= json_encode(t('core.db.job.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>
  };

  function fmtDur(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }
  function fmtBytes(n) {
    n = Math.max(0, n || 0);
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(2) + ' MB';
  }
  function setBar(fill, bar, pctEl, pct) {
    pct = Math.max(0, Math.min(100, pct || 0));
    if (fill) fill.style.width = pct + '%';
    if (pctEl) pctEl.textContent = Math.round(pct) + '%';
    if (bar) bar.setAttribute('aria-valuenow', String(Math.round(pct)));
  }
  function updateClock() {
    if (!startedAt || finished) return;
    var elapsed = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
    if (elapsedEl) elapsedEl.textContent = fmtDur(elapsed);
  }
  function setActionButtons(running) {
    if (abortBtn) {
      abortBtn.hidden = !running;
      abortBtn.disabled = !running;
      abortBtn.setAttribute('aria-hidden', running ? 'false' : 'true');
    }
    if (okBtn) {
      okBtn.hidden = running;
      okBtn.disabled = running;
      okBtn.setAttribute('aria-hidden', running ? 'true' : 'false');
    }
  }
  function showJob() {
    if (!overlay) return;
    overlay.hidden = false;
    document.body.classList.add('db-job-busy');
    finished = false;
    aborting = false;
    startedAt = Date.now();
    if (titleEl) titleEl.textContent = <?= json_encode(t('core.db.job.title'), JSON_UNESCAPED_UNICODE) ?>;
    setActionButtons(true);
    if (msgEl) msgEl.textContent = i18n.starting;
    setBar(fillOverall, barOverall, pctOverall, 0);
    setBar(fillTable, barTable, pctTable, 0);
    if (bytesEl) bytesEl.textContent = '0 MB';
    if (etaEl) etaEl.textContent = '';
    if (elapsedEl) elapsedEl.textContent = '0:00';
    if (timerIv) clearInterval(timerIv);
    timerIv = setInterval(updateClock, 250);
  }
  function finishJob(status, message) {
    finished = true;
    activeJobId = null;
    if (timerIv) { clearInterval(timerIv); timerIv = null; }
    // Freeze elapsed at final client value (do not jump to server clock).
    if (startedAt && elapsedEl) {
      elapsedEl.textContent = fmtDur(Math.max(0, Math.floor((Date.now() - startedAt) / 1000)));
    }
    setActionButtons(false);
    if (msgEl && message) msgEl.textContent = message;
    if (titleEl) {
      if (status === 'done') titleEl.textContent = i18n.doneTitle;
      else if (status === 'cancelled') titleEl.textContent = i18n.cancelledTitle;
      else titleEl.textContent = i18n.errorTitle;
    }
  }
  function hideJob() {
    if (!overlay) return;
    overlay.hidden = true;
    document.body.classList.remove('db-job-busy');
    if (timerIv) { clearInterval(timerIv); timerIv = null; }
    activeJobId = null;
    setActionButtons(true);
  }
  function applyJob(j) {
    if (!j) return;
    if (msgEl) msgEl.textContent = j.message || '';
    var overall = j.progress_overall != null ? j.progress_overall : (j.progress || 0);
    var table = j.progress_table != null ? j.progress_table : 0;
    setBar(fillOverall, barOverall, pctOverall, overall);
    setBar(fillTable, barTable, pctTable, table);
    if (tableLabel) {
      tableLabel.textContent = j.table
        ? (i18n.barTable + ': ' + j.table + (j.tables_total ? (' (' + ((j.table_idx || 0) + 1) + '/' + j.tables_total + ')') : ''))
        : i18n.barTable;
    }
    if (bytesEl) bytesEl.textContent = fmtBytes(j.bytes || 0);
    if (etaEl) {
      if (j.eta_seconds != null && j.status === 'running' && !finished) {
        etaEl.textContent = i18n.eta.replace('{eta}', fmtDur(j.eta_seconds));
      } else {
        etaEl.textContent = '';
      }
    }
    // Elapsed is client-only — never overwrite from server (causes flicker).
  }

  function postForm(url, formData) {
    return fetch(url, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (r) {
      return r.text().then(function (text) {
        var j = null;
        try {
          j = text ? JSON.parse(text) : null;
        } catch (parseErr) {
          var snippet = (text || '').replace(/\s+/g, ' ').slice(0, 180);
          throw new Error(i18n.badJson + ' HTTP ' + r.status + (snippet ? (': ' + snippet) : ''));
        }
        if (!r.ok || (j && j.ok === false)) {
          throw new Error((j && (j.error || j.message)) || ('HTTP ' + r.status));
        }
        return j || {};
      });
    });
  }

  if (okBtn) {
    okBtn.addEventListener('click', function () {
      hideJob();
      window.location.reload();
    });
  }
  if (abortBtn) {
    abortBtn.addEventListener('click', function () {
      if (!activeJobId || finished || aborting) return;
      aborting = true;
      abortBtn.disabled = true;
      var tfd = new FormData();
      tfd.set('action', 'db_job_cancel');
      tfd.set('job_id', activeJobId);
      tfd.set('format', 'json');
      postForm('/db', tfd).then(function (res) {
        applyJob(res.job || {});
        finishJob('cancelled', (res.job && res.job.message) || '');
      }).catch(function (err) {
        finishJob('error', err && err.message ? err.message : String(err));
      });
    });
  }

  function runJob(form, op) {
    var fd = new FormData(form);
    fd.set('action', 'db_job_start');
    fd.set('op', op);
    fd.set('format', 'json');
    showJob();
    return postForm('/db', fd).then(function (start) {
      var job = start.job || {};
      var id = job.id;
      if (!id) throw new Error('No job id');
      activeJobId = id;
      applyJob(job);

      var failStreak = 0;
      function tick() {
        if (finished || aborting) return;
        var tfd = new FormData();
        tfd.set('action', 'db_job_tick');
        tfd.set('job_id', id);
        tfd.set('format', 'json');
        return postForm('/db', tfd).then(function (res) {
          failStreak = 0;
          if (finished || aborting) return;
          var j = res.job || {};
          applyJob(j);
          if (j.status === 'done') {
            setBar(fillOverall, barOverall, pctOverall, 100);
            setBar(fillTable, barTable, pctTable, 100);
            finishJob('done', j.message || '');
            return;
          }
          if (j.status === 'cancelled') {
            finishJob('cancelled', j.message || '');
            return;
          }
          if (j.status === 'error') {
            finishJob('error', j.error || j.message || 'Job failed');
            return;
          }
          return tick();
        }).catch(function (err) {
          if (finished || aborting) return;
          failStreak++;
          if (failStreak <= 3) {
            if (msgEl) msgEl.textContent = (err && err.message ? err.message : String(err)) + ' — retry ' + failStreak + '/3';
            return new Promise(function (resolve) {
              setTimeout(resolve, 800 * failStreak);
            }).then(tick);
          }
          throw err;
        });
      }
      return tick();
    }).catch(function (err) {
      finishJob('error', err && err.message ? err.message : String(err));
    });
  }

  document.querySelectorAll('form.db-job-form').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      var kind = form.getAttribute('data-db-job');
      if (!kind) return;
      var submitter = ev.submitter || document.activeElement;
      var op = form.querySelector('input[name="op"]');
      var opVal = op ? op.value : kind;
      if (submitter && submitter.getAttribute) {
        var dop = submitter.getAttribute('data-db-op');
        if (dop) opVal = dop;
      }
      if (kind === 'dump') opVal = 'dump';
      if (kind === 'restore') opVal = 'restore';
      if (kind === 'diagnose') opVal = 'diagnose';
      if (kind === 'fetch') opVal = 'fetch';
      if (['dump', 'restore', 'check', 'repair', 'optimize', 'diagnose', 'fetch'].indexOf(opVal) === -1) return;
      ev.preventDefault();
      ev.stopPropagation();
      runJob(form, opVal);
    }, true);
  });
})();
</script>
<?php endif; ?>
