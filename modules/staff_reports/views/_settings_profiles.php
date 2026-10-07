<?php
/**
 * @var list<array{ID:int,NAME:string}> $statuses
 * @var int $editPosition
 * @var array{id:int,position_id:int,enabled:bool,config:array}|null $profile
 */
$statuses = is_array($statuses ?? null) ? $statuses : [];
$editPosition = (int)($editPosition ?? 0);
$profile = is_array($profile ?? null) ? $profile : null;
$cfg = is_array($profile['config'] ?? null) ? $profile['config'] : StaffReportConfig::defaults();
$analysis = is_array($cfg['analysis'] ?? null) ? $cfg['analysis'] : [];
$th = is_array($cfg['color_thresholds'] ?? null) ? $cfg['color_thresholds'] : [];
$excluded = implode(',', StaffReportConfig::intList($cfg['excluded_procedure_type_ids'] ?? []));
$criteria = implode(',', StaffReportConfig::intList($cfg['criteria_type_ids'] ?? []));
?>
<fieldset class="form-fieldset sr-settings">
  <legend><?= te('staff_reports.settings.title') ?></legend>
  <p class="muted"><?= te('staff_reports.settings.hint') ?></p>
  <input type="hidden" name="staff_reports_settings_save" value="1">

  <div class="form-row">
    <label><?= te('staff_reports.settings.position') ?>
      <select name="sr_position" onchange="var u=new URL(window.location.href);u.searchParams.set('sr_pos',this.value);window.location.href=u.toString();">
        <option value="0">—</option>
        <?php foreach ($statuses as $st): ?>
          <option value="<?= (int)$st['ID'] ?>"<?= $editPosition === (int)$st['ID'] ? ' selected' : '' ?>><?= e((string)$st['NAME']) ?> (#<?= (int)$st['ID'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <?php if ($editPosition > 0): ?>
    <label class="form-check">
      <input type="checkbox" name="sr_enabled" value="1"<?= !empty($profile['enabled']) ? ' checked' : '' ?>>
      <?= te('staff_reports.settings.enabled') ?>
    </label>

    <div class="form-row">
      <label><?= te('staff_reports.settings.mode') ?>
        <select name="sr_mode">
          <option value="procedure_slot"<?= ($analysis['mode'] ?? '') === 'procedure_slot' ? ' selected' : '' ?>><?= te('staff_reports.settings.mode_procedure_slot') ?></option>
          <option value="booking_create"<?= ($analysis['mode'] ?? '') === 'booking_create' ? ' selected' : '' ?>><?= te('staff_reports.settings.mode_booking_create') ?></option>
          <option value="score_time"<?= ($analysis['mode'] ?? '') === 'score_time' ? ' selected' : '' ?>><?= te('staff_reports.settings.mode_score_time') ?></option>
        </select>
      </label>
    </div>

    <div class="form-row">
      <label><?= te('staff_reports.settings.staff_field') ?>
        <select name="sr_staff_field">
          <option value="ID_OPERATOR"<?= ($analysis['staff_field'] ?? '') === 'ID_OPERATOR' ? ' selected' : '' ?>><?= te('staff_reports.settings.staff_field_operator') ?></option>
          <option value="ID_ADMIN"<?= ($analysis['staff_field'] ?? '') === 'ID_ADMIN' ? ' selected' : '' ?>><?= te('staff_reports.settings.staff_field_admin') ?></option>
        </select>
      </label>
    </div>

    <div class="form-row-inline">
      <label><?= te('staff_reports.settings.procedure_buffer') ?>
        <input type="number" min="0" name="sr_procedure_buffer" value="<?= (int)($analysis['procedure_time_buffer'] ?? 1600) ?>">
      </label>
      <label><?= te('staff_reports.settings.booking_buffer') ?>
        <input type="number" min="0" name="sr_booking_buffer" value="<?= (int)($analysis['booking_buffer_sec'] ?? 900) ?>">
      </label>
    </div>

    <div class="form-row">
      <label><?= te('staff_reports.settings.excluded_types') ?>
        <input type="text" name="sr_excluded_types" value="<?= e($excluded) ?>" placeholder="44">
      </label>
    </div>
    <div class="form-row">
      <label><?= te('staff_reports.settings.criteria_ids') ?>
        <input type="text" name="sr_criteria_ids" value="<?= e($criteria) ?>">
      </label>
    </div>

    <div class="form-row-inline">
      <label><?= te('staff_reports.settings.red_max') ?>
        <input type="number" step="0.1" name="sr_red_max" value="<?= e((string)($th['red_max'] ?? 40)) ?>">
      </label>
      <label><?= te('staff_reports.settings.yellow_max') ?>
        <input type="number" step="0.1" name="sr_yellow_max" value="<?= e((string)($th['yellow_max'] ?? 70)) ?>">
      </label>
      <label><?= te('staff_reports.settings.coverage_min') ?>
        <input type="number" step="0.1" name="sr_coverage_min" value="<?= e((string)($cfg['coverage_min_percent'] ?? 70)) ?>">
      </label>
    </div>

    <label class="form-check">
      <input type="checkbox" name="sr_require_full_parse" value="1"<?= !empty($cfg['require_full_parse']) ? ' checked' : '' ?>>
      <?= te('staff_reports.settings.require_full_parse') ?>
    </label>

    <div class="form-row-inline">
      <label><?= te('staff_reports.settings.reward_sum') ?>
        <input type="number" min="0" name="sr_reward_sum" value="<?= (int)($cfg['contract_reward_sum'] ?? 500) ?>">
      </label>
      <label><?= te('staff_reports.settings.managing_contract') ?>
        <input type="number" min="0" name="sr_managing_contract" value="<?= (int)($cfg['managing_contract_id'] ?? 0) ?>">
      </label>
      <label><?= te('staff_reports.settings.ticket_status') ?>
        <input type="number" min="0" name="sr_ticket_status" value="<?= (int)($cfg['ticket_status_id'] ?? 4) ?>">
      </label>
    </div>
    <div class="form-row">
      <label><?= te('staff_reports.settings.ticket_prefix') ?>
        <input type="text" name="sr_ticket_prefix" value="<?= e((string)($cfg['ticket_title_prefix'] ?? '[Отчеты:')) ?>">
      </label>
    </div>
    <label class="check">
      <input type="checkbox" name="sr_llm_enabled" value="1"<?= !empty($cfg['llm']['enabled']) ? ' checked' : '' ?>>
      <?= te('staff_reports.settings.llm_enabled') ?>
    </label>
  <?php endif; ?>
</fieldset>
