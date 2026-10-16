<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,string> $cfg */
/** @var array{flyer_enabled:int,flyer_discount:int,promo_position_ids:list<int>,promo_discounts:array<int,int|string>,discount_types?:array<int,int|string>} $flyerPromo */
/** @var array<int,string> $statusOptions */
/** @var array<int,string> $procedureTypeOptions */
/** @var bool $hasFlyerCols */
/** @var bool $hasPromoCols */
/** @var bool $hasDiscountsTypesCol */
/** @var bool $hasFreeTicketCol */
/** @var int $freeTicketThreshold */
$c = static function (string $key, string $fallback = '') use ($cfg): string {
    return (string)($cfg[$key] ?? $fallback);
};
$on = static function (string $key) use ($c): bool {
    return $c($key, '0') === '1';
};
$selectedPositions = array_fill_keys($flyerPromo['promo_position_ids'] ?? [], true);
$promoDiscounts = is_array($flyerPromo['promo_discounts'] ?? null) ? $flyerPromo['promo_discounts'] : [];
$discountTypes = is_array($flyerPromo['discount_types'] ?? null) ? $flyerPromo['discount_types'] : [];
?>
<section class="page-head">
  <p class="lede"><?= te('likespa.settings.lede') ?></p>
  <p class="muted"><?= te('likespa.settings.ini_hint') ?></p>
  <p class="muted"><?= te('likespa.settings.system_hint') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid likespa-settings">
  <fieldset>
    <legend><?= te('likespa.settings.sec.features') ?></legend>
    <label class="check"><input type="checkbox" name="term_scans_enabled" value="1"<?= $on('term_scans_enabled') ? ' checked' : '' ?>> <?= te('likespa.settings.term_scans_enabled') ?></label>
    <label class="check"><input type="checkbox" name="promo_enabled" value="1"<?= $on('promo_enabled') ? ' checked' : '' ?>> <?= te('likespa.settings.promo_enabled') ?></label>
    <label class="check"><input type="checkbox" name="dops_enabled" value="1"<?= $on('dops_enabled') ? ' checked' : '' ?>> <?= te('likespa.settings.dops_enabled') ?></label>
    <label class="check"><input type="checkbox" name="restores_enabled" value="1"<?= $on('restores_enabled') ? ' checked' : '' ?>> <?= te('likespa.settings.restores_enabled') ?></label>
    <label class="check"><input type="checkbox" name="staff_debts_enabled" value="1"<?= $on('staff_debts_enabled') ? ' checked' : '' ?>> <?= te('likespa.settings.staff_debts_enabled') ?></label>
  </fieldset>

  <fieldset>
    <legend><?= te('likespa.settings.sec.legacy_api') ?></legend>
    <p class="muted"><?= te('likespa.settings.legacy_api_hint') ?></p>
    <label class="check"><input type="checkbox" name="legacy_api_enabled" value="1"<?= $on('legacy_api_enabled') ? ' checked' : '' ?>> <?= te('likespa.settings.legacy_api_enabled') ?></label>
    <label><?= te('likespa.settings.legacy_api_termy_category') ?>
      <input type="number" name="legacy_api_termy_category" min="1" step="1"
             value="<?= (int)$c('legacy_api_termy_category', '16') ?>">
    </label>
    <label><?= te('likespa.settings.legacy_api_key') ?>
      <input type="password" name="legacy_api_key" value="" autocomplete="new-password">
      <span class="muted"><?= te('likespa.settings.legacy_api_key_hint') ?>
        <?= $c('legacy_api_key_set', '0') === '1' ? te('likespa.settings.legacy_api_key_set') : te('likespa.settings.legacy_api_key_empty') ?>
      </span>
    </label>
    <label><?= te('likespa.settings.lead_sec_api') ?>
      <input type="number" name="lead_sec_api" min="0" max="7200" step="60"
             value="<?= (int)$c('lead_sec_api', '600') ?>">
      <span class="muted"><?= te('likespa.settings.lead_sec_api_hint') ?></span>
    </label>
    <label><?= te('likespa.settings.lead_sec_termy') ?>
      <input type="number" name="lead_sec_termy" min="0" max="7200" step="60"
             value="<?= (int)$c('lead_sec_termy', '1200') ?>">
      <span class="muted"><?= te('likespa.settings.lead_sec_termy_hint') ?></span>
    </label>
    <p><a href="/likespa/legacy-api/status"><?= te('likespa.legacy_api.status_title') ?></a></p>
  </fieldset>

  <?php if (!empty($hasFreeTicketCol)): ?>
  <fieldset>
    <legend><?= te('likespa.settings.sec.free_ticket') ?></legend>
    <label><?= te('likespa.settings.free_ticket_threshold') ?>
      <input type="number" name="free_ticket_threshold" min="0" step="1"
             value="<?= (int)($freeTicketThreshold ?? 0) ?>">
      <span class="muted"><?= te('likespa.settings.free_ticket_threshold_hint') ?></span>
    </label>
  </fieldset>
  <?php endif; ?>

  <?php if (!empty($hasFlyerCols)): ?>
  <fieldset>
    <legend><?= te('likespa.settings.sec.flyer') ?></legend>
    <label class="check"><input type="checkbox" name="flyer_enabled" value="1"<?= !empty($flyerPromo['flyer_enabled']) ? ' checked' : '' ?>> <?= te('likespa.settings.flyer_enabled') ?></label>
    <label><?= te('likespa.settings.flyer_discount') ?>
      <input type="number" name="flyer_discount" min="0" max="100" value="<?= (int)($flyerPromo['flyer_discount'] ?? 0) ?>">
      <span class="muted"><?= te('likespa.settings.flyer_discount_hint') ?></span>
    </label>
  </fieldset>
  <?php endif; ?>

  <?php if (!empty($hasPromoCols)): ?>
  <fieldset>
    <legend><?= te('likespa.settings.sec.promo_discounts') ?></legend>
    <p class="muted"><?= te('likespa.settings.promo_positions_hint') ?></p>
    <?php if ($statusOptions === []): ?>
      <p class="muted"><?= te('likespa.settings.promo_positions_empty') ?></p>
    <?php else: ?>
      <div class="check-grid check-grid-tall likespa-promo-positions">
        <?php foreach ($statusOptions as $sid => $sname): ?>
          <label class="check">
            <input type="checkbox" name="promo_position_ids[]" value="<?= (int)$sid ?>"<?= !empty($selectedPositions[(int)$sid]) ? ' checked' : '' ?>>
            <?= e($sname) ?> <span class="muted">#<?= (int)$sid ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p class="muted"><?= te('likespa.settings.promo_discounts_hint') ?></p>
    <?php if ($procedureTypeOptions === []): ?>
      <p class="muted"><?= te('likespa.settings.promo_discounts_empty') ?></p>
    <?php else: ?>
      <div class="table-wrap likespa-promo-discounts">
        <table class="data">
          <thead>
            <tr>
              <th><?= te('likespa.settings.promo_discounts.col.type') ?></th>
              <th><?= te('likespa.settings.promo_discounts.col.pct') ?></th>
              <th><?= te('likespa.settings.promo_discounts.col.state') ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($procedureTypeOptions as $tid => $tlabel):
              $val = $promoDiscounts[(int)$tid] ?? '';
              $onType = $val !== '' && (int)$val > 0;
              ?>
            <tr>
              <td><?= e($tlabel) ?> <span class="muted">#<?= (int)$tid ?></span></td>
              <td>
                <input type="number" name="promo_discounts[<?= (int)$tid ?>]" min="0" max="100" step="1"
                       value="<?= $onType ? e((string)(int)$val) : '' ?>"
                       placeholder="%" class="likespa-promo-discount-input">
              </td>
              <td class="<?= $onType ? 'likespa-promo-on' : 'muted' ?>">
                <?= $onType ? te('likespa.settings.promo_discounts.on') : te('likespa.settings.promo_discounts.off') ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </fieldset>
  <?php endif; ?>

  <?php if (!empty($hasDiscountsTypesCol)): ?>
  <fieldset>
    <legend><?= te('likespa.settings.sec.discount_types') ?></legend>
    <p class="muted"><?= te('likespa.settings.discount_types_hint') ?></p>
    <?php if ($procedureTypeOptions === []): ?>
      <p class="muted"><?= te('likespa.settings.promo_discounts_empty') ?></p>
    <?php else: ?>
      <div class="table-wrap likespa-promo-discounts">
        <table class="data">
          <thead>
            <tr>
              <th><?= te('likespa.settings.promo_discounts.col.type') ?></th>
              <th><?= te('likespa.settings.discount_types.col.pct') ?></th>
              <th><?= te('likespa.settings.promo_discounts.col.state') ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($procedureTypeOptions as $tid => $tlabel):
              $val = $discountTypes[(int)$tid] ?? '';
              $onType = $val !== '' && (int)$val > 0;
              ?>
            <tr>
              <td><?= e($tlabel) ?> <span class="muted">#<?= (int)$tid ?></span></td>
              <td>
                <input type="number" name="discount_types[<?= (int)$tid ?>]" min="0" max="100" step="1"
                       value="<?= $onType ? e((string)(int)$val) : '' ?>"
                       placeholder="%" class="likespa-promo-discount-input">
              </td>
              <td class="<?= $onType ? 'likespa-promo-on' : 'muted' ?>">
                <?= $onType ? te('likespa.settings.promo_discounts.on') : te('likespa.settings.promo_discounts.off') ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </fieldset>
  <?php endif; ?>

  <fieldset>
    <legend><?= te('likespa.settings.sec.promo') ?></legend>
    <label><?= te('likespa.settings.promo_price_col') ?>
      <input type="number" name="promo_price_col" min="1" value="<?= e($c('promo_price_col', '4')) ?>">
      <span class="muted"><?= te('likespa.settings.promo_price_col_hint') ?></span>
    </label>
    <label><?= te('likespa.settings.promo_min_work_hours') ?>
      <input type="number" name="promo_min_work_hours" min="0" step="0.1" value="<?= e($c('promo_min_work_hours', '6.8')) ?>">
    </label>
    <label><?= te('likespa.settings.promo_script_ids') ?>
      <input type="text" name="promo_script_ids" value="<?= e($c('promo_script_ids', '4,20')) ?>" placeholder="4,20">
      <span class="muted"><?= te('likespa.settings.ids_hint') ?></span>
    </label>
    <label><?= te('likespa.settings.promo_min_script_count') ?>
      <input type="number" name="promo_min_script_count" min="0" value="<?= e($c('promo_min_script_count', '95')) ?>">
    </label>
    <label><?= te('likespa.settings.promo_min_conversion') ?>
      <input type="number" name="promo_min_conversion" min="0" step="0.1" value="<?= e($c('promo_min_conversion', '20')) ?>">
      <span class="muted"><?= te('likespa.settings.promo_min_conversion_hint') ?></span>
    </label>
    <label><?= te('likespa.settings.promo_bonus_playtime') ?>
      <input type="number" name="promo_bonus_playtime" min="1" value="<?= e($c('promo_bonus_playtime', '9000')) ?>">
    </label>
    <label><?= te('likespa.settings.promo_bonus_score_add') ?>
      <input type="number" name="promo_bonus_score_add" step="0.1" value="<?= e($c('promo_bonus_score_add', '1.5')) ?>">
    </label>
    <label class="check"><input type="checkbox" name="promo_term_scan_conversion" value="1"<?= $on('promo_term_scan_conversion') ? ' checked' : '' ?>> <?= te('likespa.settings.promo_term_scan_conversion') ?></label>
    <label><?= te('likespa.settings.promo_term_scan_proc_window_hours') ?>
      <input type="number" name="promo_term_scan_proc_window_hours" min="1" max="24" value="<?= e($c('promo_term_scan_proc_window_hours', '4')) ?>">
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('likespa.settings.sec.dops') ?></legend>
    <label><?= te('likespa.settings.dops_procedure_type') ?>
      <input type="number" name="dops_procedure_type" min="0" value="<?= e($c('dops_procedure_type', '44')) ?>">
      <span class="muted"><?= te('likespa.settings.dops_procedure_type_hint') ?></span>
    </label>
    <label><?= te('likespa.settings.dops_position_ids') ?>
      <input type="text" name="dops_position_ids" value="<?= e($c('dops_position_ids', '1')) ?>" placeholder="1">
      <span class="muted"><?= te('likespa.settings.dops_position_ids_hint') ?></span>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('likespa.settings.sec.restores') ?></legend>
    <label><?= te('likespa.settings.restores_lookback_days') ?>
      <input type="number" name="restores_lookback_days" min="0" max="30" value="<?= e($c('restores_lookback_days', '4')) ?>">
      <span class="muted"><?= te('likespa.settings.restores_lookback_days_hint') ?></span>
    </label>
    <label><?= te('likespa.settings.restores_payment_type') ?>
      <input type="number" name="restores_payment_type" min="1" value="<?= e($c('restores_payment_type', '1')) ?>">
      <span class="muted"><?= te('likespa.settings.restores_payment_type_hint') ?></span>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('likespa.settings.sec.staff_debts') ?></legend>
    <p class="muted"><?= te('likespa.settings.staff_debts_hint') ?></p>
    <label><?= te('likespa.settings.staff_debts_news_id') ?>
      <input type="number" name="staff_debts_news_id" min="0" step="1" value="<?= e($c('staff_debts_news_id', '0')) ?>">
      <span class="muted"><?= te('likespa.settings.staff_debts_news_id_hint') ?></span>
    </label>
    <label><?= te('likespa.settings.staff_debts_comment_user_id') ?>
      <input type="number" name="staff_debts_comment_user_id" min="0" step="1" value="<?= e($c('staff_debts_comment_user_id', '0')) ?>">
      <span class="muted"><?= te('likespa.settings.staff_debts_comment_user_id_hint') ?></span>
    </label>
    <label><?= te('likespa.settings.staff_debts_daily_at') ?>
      <input type="text" name="staff_debts_daily_at" pattern="\d{1,2}:\d{2}" placeholder="08:00" value="<?= e($c('staff_debts_daily_at', '08:00')) ?>">
      <span class="muted"><?= te('likespa.settings.staff_debts_daily_at_hint') ?></span>
    </label>
    <?php if ($on('staff_debts_enabled')): ?>
      <p><a href="/likespa/staff-debts"><?= te('likespa.settings.staff_debts_run_link') ?></a></p>
    <?php endif; ?>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
  </div>
</form>
