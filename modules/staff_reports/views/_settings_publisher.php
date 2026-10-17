<?php
declare(strict_types=1);
/**
 * @var array<string,mixed> $publisher
 */
$publisher = is_array($publisher ?? null) ? $publisher : StaffReportPublisherConfig::defaults();
$llm = is_array($publisher['llm'] ?? null) ? $publisher['llm'] : [];
?>
<fieldset class="form-fieldset">
  <legend><?= te('staff_reports.settings.publisher') ?></legend>
  <p class="muted"><?= te('staff_reports.settings.publisher_hint') ?></p>
  <input type="hidden" name="staff_reports_publisher_save" value="1">
  <label class="checkbox">
    <input type="checkbox" name="sr_require_full_parse" value="1"<?= !empty($publisher['require_full_parse']) ? ' checked' : '' ?>>
    <?= te('staff_reports.settings.require_full_parse') ?>
  </label>
  <label class="checkbox">
    <input type="checkbox" name="sr_allow_same_day_publish" value="1"<?= !empty($publisher['allow_same_day_publish']) ? ' checked' : '' ?>>
    <?= te('staff_reports.settings.allow_same_day_publish') ?>
  </label>
  <p class="muted small"><?= te('staff_reports.settings.allow_same_day_publish_hint') ?></p>
  <label><?= te('staff_reports.settings.gratitude_news_id') ?>
    <input type="number" min="0" name="sr_gratitude_news_id" value="<?= (int)($publisher['gratitude_news_id'] ?? 0) ?>">
  </label>
  <p class="muted small"><?= te('staff_reports.settings.gratitude_news_id_hint') ?></p>
  <?php
  $mark = StaffReportPublisherConfig::normalizeGratitudeMark((string)($publisher['gratitude_mark'] ?? 'headphones'));
  ?>
  <label><?= te('staff_reports.settings.gratitude_mark') ?>
    <select name="sr_gratitude_mark">
      <?php foreach (StaffReportPublisherConfig::GRATITUDE_MARKS as $markId => $emoji): ?>
        <option value="<?= e($markId) ?>"<?= $mark === $markId ? ' selected' : '' ?>>
          <?= e($emoji) ?> <?= te('staff_reports.settings.gratitude_mark.' . $markId) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <p class="muted small"><?= te('staff_reports.settings.gratitude_mark_hint') ?></p>
  <label><?= te('staff_reports.settings.ticket_prefix') ?>
    <input type="text" name="sr_ticket_prefix" value="<?= e((string)($publisher['ticket_title_prefix'] ?? '[Отчеты:')) ?>">
  </label>
  <label><?= te('staff_reports.settings.ticket_status') ?>
    <input type="number" min="0" name="sr_ticket_status" value="<?= (int)($publisher['ticket_status_id'] ?? 4) ?>">
  </label>
  <label><?= te('staff_reports.settings.managing_contract') ?>
    <input type="number" min="0" name="sr_managing_contract" value="<?= (int)($publisher['managing_contract_id'] ?? 0) ?>">
  </label>
  <label><?= te('staff_reports.settings.contract_working_days') ?>
    <input type="number" min="0" name="sr_contract_working_days" value="<?= (int)($publisher['contract_working_days'] ?? 5) ?>">
  </label>
  <p class="muted small"><?= te('staff_reports.settings.contract_working_days_hint') ?></p>
  <label class="checkbox">
    <input type="checkbox" name="sr_llm_enabled" value="1"<?= !empty($llm['enabled']) ? ' checked' : '' ?>>
    <?= te('staff_reports.settings.llm_enabled') ?>
  </label>
  <p class="muted small"><?= te('staff_reports.settings.gates_on_scripts_hint') ?></p>
</fieldset>
