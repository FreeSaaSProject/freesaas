<?php
declare(strict_types=1);

/**
 * funds ↔ ledger integration through ledger's generic hooks.
 * Ledger owns the ID_FUND_MOVEMENT column and the ID_FUND_ENABLED type flag; everything else lives here.
 */
final class FundsLedgerHooks
{
    /** ODDS class "investments" in base_account_move_types.ID_TYPE. */
    public const ODDS_INVEST = 2;

    public static function register(): void
    {
        EventBus::on('ledger.types.meta', [self::class, 'onTypesMeta']);
        EventBus::on('ledger.moves.form.fields', [self::class, 'onFormFields']);
        EventBus::on('ledger.moves.validate', [self::class, 'onValidate']);
        EventBus::on('ledger.moves.edit.sections', [self::class, 'onEditSections']);
        EventBus::on('ledger.moves.badges', [self::class, 'onBadges']);
    }

    /** @param array<string,mixed> $type */
    public static function typeLinksFunds(array $type): bool
    {
        return (int)($type['fund_enabled'] ?? 0) > 0 || (int)($type['odds_type'] ?? 0) === self::ODDS_INVEST;
    }

    /** @param mixed $payload @return mixed */
    public static function onTypesMeta($payload)
    {
        if (!is_array($payload) || !is_array($payload['meta'] ?? null)) {
            return $payload;
        }
        foreach ($payload['meta'] as $id => $row) {
            if (is_array($row)) {
                $payload['meta'][$id]['fund_link'] = self::typeLinksFunds($row) ? 1 : 0;
            }
        }
        return $payload;
    }

    /** @param mixed $payload @return mixed */
    public static function onFormFields($payload)
    {
        if (!is_array($payload) || !Auth::can('funds.view')) {
            return $payload;
        }
        $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
        $type = is_array($payload['type'] ?? null) ? $payload['type'] : null;
        $on = $type !== null && !empty($type['fund_link']);
        $currentFm = (int)($row['fund_movement_id'] ?? 0);
        $direction = (int)($row['direction'] ?? 0);
        if ($direction === 0 && $type !== null) {
            $direction = (int)($type['direction'] ?? 0);
        }
        $dt = is_numeric($row['dt'] ?? null) ? (int)$row['dt'] : (int)(strtotime((string)($row['dt'] ?? '')) ?: 0);
        $groups = FundMovementsRepository::optionsForDps($currentFm, $direction, $dt);
        $known = false;
        foreach ($groups as $g) {
            if (isset($g['options'][$currentFm])) {
                $known = true;
            }
        }
        ob_start();
        ?>
<label data-ledger-requires-meta="fund_link"<?= $on ? '' : ' hidden' ?>><?= te('funds.dps.field') ?>
  <select name="fund_movement_id"<?= $on ? '' : ' disabled' ?>>
    <option value="0"><?= te('funds.dps.none') ?></option>
    <?php if ($currentFm > 0 && !$known): ?>
      <option value="<?= $currentFm ?>" selected><?= te('funds.dps.missing', ['id' => $currentFm]) ?></option>
    <?php endif; ?>
    <?php foreach ($groups as $g): ?>
      <optgroup label="<?= e($g['label']) ?>">
        <?php foreach ($g['options'] as $fmId => $label): ?>
          <option value="<?= (int)$fmId ?>"<?= (int)$fmId === $currentFm ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </optgroup>
    <?php endforeach; ?>
  </select>
</label>
        <?php
        $payload['html'][] = (string)ob_get_clean();
        return $payload;
    }

    /** @param mixed $payload @return mixed */
    public static function onValidate($payload)
    {
        if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
            return $payload;
        }
        $type = is_array($payload['type'] ?? null) ? $payload['type'] : [];
        $fmId = (int)($payload['data']['fund_movement_id'] ?? 0);
        if (!self::typeLinksFunds($type)) {
            $payload['data']['fund_movement_id'] = 0;
            return $payload;
        }
        if ($fmId > 0 && FundMovementsRepository::find($fmId) === null) {
            $payload['error'] = t('funds.dps.err.fm_not_found', ['id' => $fmId]);
        }
        return $payload;
    }

    /**
     * Link state of one DPS for UI and agent JSON.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $type
     * @return array<string,mixed>
     */
    public static function linkInfo(int $moveId, array $row, ?array $type): array
    {
        $fmId = (int)($row['fund_movement_id'] ?? 0);
        $info = [
            'fund_link' => $type !== null && self::typeLinksFunds($type),
            'fund_movement_id' => $fmId,
            'linked' => null,
            'create_url' => $moveId > 0 ? '/fund-movements/new?dps=' . $moveId : null,
        ];
        if ($fmId < 1) {
            return $info;
        }
        $fm = FundMovementsRepository::find($fmId);
        if ($fm === null) {
            $info['linked'] = ['fund_movement_id' => $fmId, 'missing' => true];
            return $info;
        }
        $withDps = FundMovementsRepository::attachDps([$fm])[0];
        $fund = FundsRepository::allRows()[(int)$fm['fund_id']] ?? null;
        $info['linked'] = [
            'fund_movement_id' => $fmId,
            'name' => (string)$fm['name'],
            'type_id' => (int)$fm['type_id'],
            'summ' => round((float)$fm['summ'], 2),
            'fund_id' => (int)$fm['fund_id'],
            'fund_name' => $fund !== null ? $fund['name'] : '',
            'dps_sum' => (float)$withDps['dps_sum'],
            'dps_ids' => array_map(static function (array $d): int {
                return (int)$d['id'];
            }, $withDps['dps']),
            'mismatch' => (bool)$withDps['mismatch'],
            'missing' => false,
        ];
        return $info;
    }

    /** @param mixed $payload @return mixed */
    public static function onEditSections($payload)
    {
        if (!is_array($payload) || !Auth::can('funds.view')) {
            return $payload;
        }
        $moveId = (int)($payload['id'] ?? 0);
        $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
        $type = is_array($payload['type'] ?? null) ? $payload['type'] : null;
        $info = self::linkInfo($moveId, $row, $type);
        if (!isset($payload['data']) || !is_array($payload['data'])) {
            $payload['data'] = [];
        }
        $payload['data']['funds'] = $info;
        if (!$info['fund_link'] && $info['linked'] === null) {
            return $payload;
        }
        $canEdit = Auth::can('funds.edit');
        ob_start();
        require base_path('modules/funds/views/_dps_section.php');
        $payload['html'][] = (string)ob_get_clean();
        return $payload;
    }

    /** @param mixed $payload @return mixed */
    public static function onBadges($payload)
    {
        if (!is_array($payload) || !Auth::can('funds.view')) {
            return $payload;
        }
        $ids = array_values(array_filter(array_map('intval', (array)($payload['ids'] ?? []))));
        if ($ids === [] || !Database::tableExists('base_fund_movements')) {
            return $payload;
        }
        $fmByMove = LedgerMovesRepository::fundMovementRefs($ids);
        if ($fmByMove === []) {
            return $payload;
        }
        $fmIds = array_values(array_unique($fmByMove));
        $ph = implode(',', array_fill(0, count($fmIds), '?'));
        $st = Database::pdo()->prepare("SELECT ID, ID_FUND FROM base_fund_movements WHERE ID IN ($ph)");
        $st->execute($fmIds);
        $fundByFm = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $fundByFm[(int)$r['ID']] = (int)$r['ID_FUND'];
        }
        $funds = FundsRepository::allRows();
        $byId = is_array($payload['by_id'] ?? null) ? $payload['by_id'] : [];
        foreach ($fmByMove as $mid => $fmId) {
            $fundId = $fundByFm[$fmId] ?? 0;
            $name = $fundId > 0 ? (string)($funds[$fundId]['name'] ?? ('#' . $fundId)) : t('funds.dps.missing', ['id' => $fmId]);
            $href = $fundId > 0 ? '/funds/' . $fundId : '/fund-movements';
            $html = '<a class="pill" href="' . e($href) . '" title="' . te('funds.dps.badge_title', ['id' => $fmId]) . '">'
                . te('funds.dps.badge', ['name' => $name]) . '</a>';
            $byId[$mid] = trim((string)($byId[$mid] ?? '') . ' ' . $html);
        }
        $payload['by_id'] = $byId;
        return $payload;
    }
}
