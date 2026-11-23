<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<string,mixed> $acl */
/** @var string $message */
/** @var string $error */
/** @var array<int,string> $typeNames */
/** @var array<int,string> $productNames */
/** @var array<int,string> $staffNames */
/** @var string $clientLabel */
/** @var string $returnUrl */
/** @var bool $showChainMove */
/** @var int $chainLen */
/** @var bool $chainMoveChecked */
/** @var string $chainBaseDtstart */
/** @var string $chainBaseOperator */

$returnUrl = $returnUrl ?? '/procedures';
$showChainMove = !empty($showChainMove);
$chainLen = (int)($chainLen ?? 0);
$chainMoveChecked = !empty($chainMoveChecked);
$chainBaseDtstart = (string)($chainBaseDtstart ?? '');
$chainBaseOperator = (string)($chainBaseOperator ?? '0');
$acl = is_array($acl ?? null) ? $acl : ['editable' => []];
$can = static function (string $field) use ($acl): bool {
    return ProceduresEditAcl::canEditField($acl, $field);
};
$ro = static function (string $field) use ($can): string {
    return $can($field) ? '' : ' readonly disabled';
};

$dtstart = ProceduresRepository::tsToLocal((int)($row['dtstart'] ?? 0));
$dtend = ProceduresRepository::tsToLocal((int)($row['dtend'] ?? 0));
$dtendPlan = ProceduresRepository::tsToLocal((int)($row['dtend_plan'] ?? 0));
// POST merge may leave datetime-local strings in row
if (isset($row['dtstart']) && is_string($row['dtstart']) && strpos((string)$row['dtstart'], 'T') !== false) {
    $dtstart = (string)$row['dtstart'];
}
if (isset($row['dtend']) && is_string($row['dtend']) && strpos((string)$row['dtend'], 'T') !== false) {
    $dtend = (string)$row['dtend'];
}
if (isset($row['dtend_plan']) && is_string($row['dtend_plan']) && strpos((string)$row['dtend_plan'], 'T') !== false) {
    $dtendPlan = (string)$row['dtend_plan'];
}
?>
<?php
$parentEventIdHead = (int)($row['id_event_parent'] ?? 0);
$canLinkVisit = $parentEventIdHead > 0
    && class_exists('ModuleManager', false)
    && ModuleManager::isEnabled('calendar');
?>
<section class="page-head">
  <h1><?= $isNew ? te('procedures.edit.heading_new') : te('procedures.edit.title', ['id' => (int)($row['id'] ?? 0)]) ?></h1>
  <?php if ($canLinkVisit): ?>
    <div class="page-actions">
      <a class="btn-secondary" href="/calendar/events/<?= $parentEventIdHead ?>/edit"><?= te('procedures.edit.to_visit', ['id' => $parentEventIdHead]) ?></a>
    </div>
  <?php endif; ?>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($acl['lock_dtend_timing'])): ?>
  <p class="muted"><?= te('procedures.edit.dtend_auto_hint') ?></p>
<?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form">
  <input type="hidden" name="ret" value="<?= e($returnUrl) ?>">
  <fieldset>
    <legend><?= te('procedures.edit.sec_main') ?></legend>
    <?php
    $dayPart = strlen($dtstart) >= 10 ? substr($dtstart, 0, 10) : '';
    $tStart = strlen($dtstart) >= 16 ? substr($dtstart, 11, 5) : '';
    $tEnd = strlen($dtend) >= 16 ? substr($dtend, 11, 5) : '';
    $durSec = 0;
    if ($dtstart !== '' && $dtend !== '') {
        $a = strtotime(str_replace('T', ' ', $dtstart));
        $b = strtotime(str_replace('T', ' ', $dtend));
        if ($a && $b && $b > $a) {
            $durSec = $b - $a;
        }
    }
    $durMin = (int)round($durSec / 60);
    $lockEnd = !empty($acl['lock_dtend_timing']) && !$can('dtend');
    $canStart = $can('dtstart');
    ?>
    <div class="form-row-inline" id="proc-time-row"
         data-lock-end="<?= $lockEnd ? '1' : '0' ?>">
      <label><?= te('procedures.edit.col_day') ?>
        <input type="date" id="proc-day" value="<?= e($dayPart) ?>"<?= $canStart ? ' required' : ' disabled' ?>>
      </label>
      <label><?= te('procedures.edit.col_tstart') ?>
        <input type="time" id="proc-tstart" value="<?= e($tStart) ?>"<?= $canStart ? ' required' : ' disabled' ?>>
      </label>
      <?php if ($lockEnd): ?>
        <p class="muted" style="margin:0;padding-bottom:0.45rem">
          <?= te('procedures.edit.col_tend') ?>:
          <?= e($tEnd !== '' ? $tEnd : $dtend) ?>
          <?php if ($durMin > 0): ?> (<?= (int)$durMin ?> <?= te('procedures.edit.duration_min') ?>)<?php endif; ?>
        </p>
      <?php else: ?>
        <label><?= te('procedures.edit.col_tend') ?>
          <input type="time" id="proc-tend" value="<?= e($tEnd) ?>"<?= $can('dtend') ? '' : ' disabled' ?>>
        </label>
      <?php endif; ?>
    </div>
    <input type="hidden" name="dtstart" id="proc-dtstart" value="<?= e($dtstart) ?>">
    <input type="hidden" name="dtend" id="proc-dtend" value="<?= e($dtend) ?>">
    <?php if ($can('dtend_plan')): ?>
    <label><?= te('procedures.view.dtend_plan') ?>
      <input type="datetime-local" name="dtend_plan" value="<?= e($dtendPlan) ?>">
    </label>
    <?php else: ?>
      <input type="hidden" name="dtend_plan" value="<?= e($dtendPlan) ?>">
    <?php endif; ?>
    <script>
    (function () {
      var row = document.getElementById('proc-time-row');
      if (!row) return;
      var day = document.getElementById('proc-day');
      var tStart = document.getElementById('proc-tstart');
      var tEnd = document.getElementById('proc-tend');
      var hStart = document.getElementById('proc-dtstart');
      var hEnd = document.getElementById('proc-dtend');
      var lockEnd = row.getAttribute('data-lock-end') === '1';
      var baseDur = 0;
      function parseLocal(v) {
        var m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
        if (!m) return 0;
        return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], 0, 0).getTime();
      }
      function toLocal(ts) {
        var d = new Date(ts);
        if (isNaN(d.getTime())) return '';
        var pad = function (n) { return n < 10 ? '0' + n : String(n); };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
          + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
      }
      if (hStart && hEnd) {
        var a = parseLocal(hStart.value);
        var b = parseLocal(hEnd.value);
        if (a > 0 && b > a) baseDur = b - a;
      }
      function push() {
        if (!day || !tStart || !hStart) return;
        var d = String(day.value || '');
        var ts = String(tStart.value || '');
        if (d.length === 10 && ts.length >= 4) {
          hStart.value = d + 'T' + ts.slice(0, 5);
        }
        if (lockEnd && hEnd && baseDur > 0) {
          var start = parseLocal(hStart.value);
          if (start > 0) hEnd.value = toLocal(start + baseDur);
        } else if (tEnd && hEnd) {
          var te = String(tEnd.value || '');
          if (d.length === 10 && te.length >= 4) {
            hEnd.value = d + 'T' + te.slice(0, 5);
          }
        }
        if (hStart) {
          hStart.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
      ['change', 'input'].forEach(function (ev) {
        if (day) day.addEventListener(ev, push);
        if (tStart) tStart.addEventListener(ev, push);
        if (tEnd) tEnd.addEventListener(ev, push);
      });
      push();
    })();
    </script>
    <label><?= te('procedures.col.type') ?>
      <select name="type"<?= $can('type') ? ' required' : ' disabled' ?>>
        <option value=""><?= te('procedures.list.all_types') ?></option>
        <?php foreach ($typeNames as $tid => $tlabel): ?>
          <option value="<?= (int)$tid ?>"<?= (int)($row['type'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= e($tlabel) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$can('type')): ?><input type="hidden" name="type" value="<?= (int)($row['type'] ?? 0) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.col.product') ?>
      <select name="id_good"<?= $can('id_good') ? ' required' : ' disabled' ?>>
        <option value="">—</option>
        <?php foreach ($productNames as $gid => $glabel): ?>
          <option value="<?= (int)$gid ?>"<?= (int)($row['id_good'] ?? 0) === (int)$gid ? ' selected' : '' ?>><?= e($glabel) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$can('id_good')): ?><input type="hidden" name="id_good" value="<?= (int)($row['id_good'] ?? 0) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.col.summ') ?>
      <input type="number" min="0" name="summ" value="<?= e((string)($row['summ'] ?? '0')) ?>"<?= $ro('summ') ?>>
      <?php if (!$can('summ')): ?><input type="hidden" name="summ" value="<?= e((string)($row['summ'] ?? '0')) ?>"><?php endif; ?>
    </label>
    <?php if ($can('summ_default')): ?>
    <label><?= te('procedures.view.summ_default') ?>
      <input type="number" min="0" name="summ_default" value="<?= e((string)($row['summ_default'] ?? '0')) ?>">
    </label>
    <?php else: ?>
      <input type="hidden" name="summ_default" value="<?= e((string)($row['summ_default'] ?? '0')) ?>">
    <?php endif; ?>
    <label><?= te('procedures.col.notice') ?>
      <?php if ($can('notice')): ?>
        <textarea name="notice" rows="4" maxlength="2000"><?= e((string)($row['notice'] ?? '')) ?></textarea>
      <?php else: ?>
        <textarea rows="4" readonly disabled><?= e((string)($row['notice'] ?? '')) ?></textarea>
        <input type="hidden" name="notice" value="<?= e((string)($row['notice'] ?? '')) ?>">
      <?php endif; ?>
    </label>
    <label class="check">
      <input type="checkbox" name="enabled" value="1"<?= !isset($row['enabled']) || !empty($row['enabled']) ? ' checked' : '' ?><?= $can('enabled') ? '' : ' disabled' ?>>
      <?= te('procedures.col.enabled') ?>
    </label>
    <?php if (!$can('enabled')): ?>
      <input type="hidden" name="enabled" value="<?= !isset($row['enabled']) || !empty($row['enabled']) ? '1' : '0' ?>">
    <?php endif; ?>
    <label class="check">
      <input type="checkbox" name="restored" value="1"<?= !empty($row['restored']) ? ' checked' : '' ?><?= $can('restored') ? '' : ' disabled' ?>>
      <?= te('procedures.col.restored') ?>
    </label>
    <?php if (!$can('restored')): ?>
      <input type="hidden" name="restored" value="<?= !empty($row['restored']) ? '1' : '0' ?>">
    <?php endif; ?>
  </fieldset>

  <?php
  $parentEventId = (int)($row['id_event_parent'] ?? 0);
  $showTerminal = ModuleManager::isEnabled('terminals');
  $showOnec = $showTerminal || ModuleManager::isEnabled('likespa');
  ?>
  <fieldset>
    <legend><?= te('procedures.edit.sec_people') ?></legend>
    <label><?= te('procedures.col.client') ?>
      <?php if ($can('id_user')): ?>
        <input type="number" min="1" name="id_user" value="<?= e((string)((int)($row['id_user'] ?? 0) ?: '')) ?>" required>
      <?php else: ?>
        <input type="hidden" name="id_user" value="<?= (int)($row['id_user'] ?? 0) ?>">
      <?php endif; ?>
      <?php if ($clientLabel !== ''): ?>
        <span class="muted"><?= e($clientLabel) ?> · <a href="/clients/<?= (int)$row['id_user'] ?>">#<?= (int)$row['id_user'] ?></a></span>
      <?php elseif ($can('id_user')): ?>
        <span class="muted"><?= te('procedures.edit.client_hint') ?></span>
      <?php elseif ((int)($row['id_user'] ?? 0) > 0): ?>
        <span class="muted">#<?= (int)$row['id_user'] ?></span>
      <?php endif; ?>
    </label>
    <label><?= te('procedures.col.admin') ?>
      <select name="id_admin"<?= $can('id_admin') ? '' : ' disabled' ?>>
        <option value="0">—</option>
        <?php foreach ($staffNames as $sid => $slabel): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['id_admin'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$can('id_admin')): ?><input type="hidden" name="id_admin" value="<?= (int)($row['id_admin'] ?? 0) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.col.operator') ?>
      <select name="id_operator"<?= $can('id_operator') ? '' : ' disabled' ?>>
        <option value="0">—</option>
        <?php foreach ($staffNames as $sid => $slabel): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['id_operator'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$can('id_operator')): ?><input type="hidden" name="id_operator" value="<?= (int)($row['id_operator'] ?? 0) ?>"><?php endif; ?>
    </label>
  </fieldset>

  <?php
  $showTable = class_exists('TablesRepository', false) && TablesRepository::isActive();
  $showMeta = $showTable || $showTerminal || $showOnec;
  $curTable = $showTable
      ? ProceduresRepository::normalizeTableId((int)($row['id_table'] ?? 1))
      : 1;
  ?>
  <input type="hidden" name="token" value="<?= e((string)($row['token'] ?? '')) ?>">
  <input type="hidden" name="id_event_parent" value="<?= (int)$parentEventId ?>">
  <?php if (!$showTable): ?>
    <input type="hidden" name="id_table" value="1">
  <?php endif; ?>
  <?php if (!$showTerminal): ?>
    <input type="hidden" name="id_terminal" value="<?= e((string)($row['id_terminal'] ?? '0')) ?>">
  <?php endif; ?>
  <?php if (!$showOnec): ?>
    <input type="hidden" name="onec_id" value="<?= e((string)($row['onec_id'] ?? '')) ?>">
    <input type="hidden" name="onec_bracelet" value="<?= e((string)($row['onec_bracelet'] ?? '')) ?>">
  <?php endif; ?>
  <?php if ($showMeta): ?>
  <fieldset>
    <legend><?= te('procedures.edit.sec_meta') ?></legend>
    <?php if ($showTable): ?>
    <label><?= te('procedures.view.id_table') ?>
      <select name="id_table"<?= $can('id_table') ? '' : ' disabled' ?>>
        <?php foreach (TablesRepository::names(true) as $tid => $tlabel): ?>
          <option value="<?= (int)$tid ?>"<?= $curTable === (int)$tid ? ' selected' : '' ?>><?= e($tlabel) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$can('id_table')): ?><input type="hidden" name="id_table" value="<?= (int)$curTable ?>"><?php endif; ?>
    </label>
    <?php endif; ?>
    <?php if ($showTerminal): ?>
    <label><?= te('procedures.view.id_terminal') ?>
      <input type="number" min="0" name="id_terminal" value="<?= e((string)($row['id_terminal'] ?? '0')) ?>"<?= $ro('id_terminal') ?>>
      <?php if (!$can('id_terminal')): ?><input type="hidden" name="id_terminal" value="<?= e((string)($row['id_terminal'] ?? '0')) ?>"><?php endif; ?>
    </label>
    <?php endif; ?>
    <?php if ($showOnec): ?>
    <label><?= te('procedures.view.onec_id') ?>
      <input name="onec_id" maxlength="40" value="<?= e((string)($row['onec_id'] ?? '')) ?>"<?= $ro('onec_id') ?>>
      <?php if (!$can('onec_id')): ?><input type="hidden" name="onec_id" value="<?= e((string)($row['onec_id'] ?? '')) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.view.onec_bracelet') ?>
      <input name="onec_bracelet" maxlength="32" value="<?= e((string)($row['onec_bracelet'] ?? '')) ?>"<?= $ro('onec_bracelet') ?>>
      <?php if (!$can('onec_bracelet')): ?><input type="hidden" name="onec_bracelet" value="<?= e((string)($row['onec_bracelet'] ?? '')) ?>"><?php endif; ?>
    </label>
    <?php endif; ?>
  </fieldset>
  <?php endif; ?>

  <?php if ($showChainMove): ?>
  <div id="proc-chain-move" class="proc-chain-move" hidden
       data-base-dtstart="<?= e($chainBaseDtstart) ?>"
       data-base-operator="<?= e($chainBaseOperator) ?>">
    <label class="check">
      <input type="checkbox" name="chain_move" value="1"<?= $chainMoveChecked ? ' checked' : '' ?>>
      <?= te('procedures.edit.chain_move') ?>
    </label>
    <p class="muted"><?= te('procedures.edit.chain_move_hint', ['count' => $chainLen]) ?></p>
  </div>
  <?php endif; ?>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= e($returnUrl) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if ($showChainMove): ?>
<script>
(function () {
  var box = document.getElementById('proc-chain-move');
  if (!box) return;
  var form = box.closest('form');
  if (!form) return;
  var startEl = form.querySelector('[name="dtstart"]');
  var opEl = form.querySelector('select[name="id_operator"], input[name="id_operator"]');
  var cb = box.querySelector('input[name="chain_move"]');
  var baseStart = box.getAttribute('data-base-dtstart') || '';
  var baseOp = box.getAttribute('data-base-operator') || '0';
  function sync() {
    var startVal = startEl ? String(startEl.value || '') : baseStart;
    var opVal = opEl ? String(opEl.value || '0') : baseOp;
    var changed = startVal !== baseStart || opVal !== baseOp;
    box.hidden = !changed;
    if (cb) {
      cb.disabled = !changed;
      if (!changed) cb.checked = false;
    }
  }
  if (startEl) {
    startEl.addEventListener('change', sync);
    startEl.addEventListener('input', sync);
  }
  if (opEl) {
    opEl.addEventListener('change', sync);
  }
  sync();
})();
</script>
<?php endif; ?>
<?php if (!$isNew): ?>
<?= EntityDelete::form('procedures', (int)($row['id'] ?? 0)) ?>
<?php endif; ?>
<?php if (!empty($extraHtml)): ?>
<?= $extraHtml ?>
<?php endif; ?>

