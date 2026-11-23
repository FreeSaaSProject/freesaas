<?php
declare(strict_types=1);

/**
 * Oper-parity field locks for procedure edit (SHOW_ADMINISTRATOR_DATA / SUPERADMIN_ACCESS).
 */
final class ProceduresEditAcl
{
    /** @var list<string> */
    private const ALL_FIELDS = [
        'dtstart', 'dtend', 'dtend_plan',
        'type', 'id_good', 'summ', 'summ_default', 'notice', 'enabled', 'restored',
        'id_user', 'id_admin', 'id_operator',
        'token', 'id_table', 'id_terminal', 'id_event_parent', 'onec_id', 'onec_bracelet',
    ];

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   allowed:bool,
     *   deny_reason:string,
     *   editable:array<string,bool>,
     *   lock_dtend_timing:bool,
     *   time_sec:int,
     *   time_net:int
     * }
     */
    public static function forRow(array $row, bool $isNew = false): array
    {
        $editable = [];
        foreach (self::ALL_FIELDS as $f) {
            $editable[$f] = true;
        }

        $idGood = (int)($row['id_good'] ?? 0);
        $timeSec = 0;
        $timeNet = 0;
        if ($idGood > 0 && class_exists('ProductsRepository', false)) {
            $product = ProductsRepository::find($idGood);
            if (is_array($product)) {
                $timeSec = max(0, (int)($product['time_sec'] ?? 0));
                $timeNet = max(0, (int)($product['time_net'] ?? 0));
            }
        }
        $lockDtendTiming = $timeSec > 0;
        if ($lockDtendTiming) {
            $editable['dtend'] = false;
        }

        $selfId = Auth::userId();
        $isSuper = Auth::capability('SUPERADMIN_ACCESS');
        $isAdmin = Auth::capability('SHOW_ADMINISTRATOR_DATA');

        if ($isSuper) {
            self::applyIntegrityLocks($editable, $row, true);
            return [
                'allowed' => true,
                'deny_reason' => '',
                'editable' => $editable,
                'lock_dtend_timing' => $lockDtendTiming,
                'time_sec' => $timeSec,
                'time_net' => $timeNet,
            ];
        }

        if (!$isAdmin) {
            // Executor: foreign procedures forbidden; own rows mostly read-only.
            if (!$isNew && (int)($row['id_operator'] ?? 0) !== $selfId) {
                foreach (self::ALL_FIELDS as $f) {
                    $editable[$f] = false;
                }
                self::applyIntegrityLocks($editable, $row, false);
                return [
                    'allowed' => false,
                    'deny_reason' => t('procedures.edit.err.foreign'),
                    'editable' => $editable,
                    'lock_dtend_timing' => $lockDtendTiming,
                    'time_sec' => $timeSec,
                    'time_net' => $timeNet,
                ];
            }
            if (!$isNew) {
                foreach (self::ALL_FIELDS as $f) {
                    $editable[$f] = false;
                }
            }
            self::applyIntegrityLocks($editable, $row, false);
            // New: can fill the form; timing lock still applies when product has duration.
            return [
                'allowed' => true,
                'deny_reason' => '',
                'editable' => $editable,
                'lock_dtend_timing' => $lockDtendTiming,
                'time_sec' => $timeSec,
                'time_net' => $timeNet,
            ];
        }

        // Admin (not super) on existing rows: lock meta / client / product / type.
        // Prices (summ*) — only SUPERADMIN, same as oper edit_procedure.inc.php.
        if (!$isNew) {
            $editable['dtend_plan'] = false;
            $editable['onec_id'] = false;
            $editable['id_terminal'] = false;
            $editable['enabled'] = false;
            $editable['restored'] = false;
            $editable['id_user'] = false;
            $editable['id_good'] = false;
            $editable['type'] = false;

            if ((int)($row['id_admin'] ?? 0) !== $selfId) {
                $editable['id_admin'] = false;
            }

            $dtstart = (int)($row['dtstart'] ?? 0);
            $todayStart = (int)mktime(0, 0, 0);
            if ($dtstart > 0 && $dtstart < $todayStart) {
                $editable['id_admin'] = false;
                $editable['dtstart'] = false;
                $editable['enabled'] = false;
                $editable['id_operator'] = false;
                $editable['notice'] = false;
            }
        } else {
            // New: admin may set fields, but not planned end / 1C id (oper hides for non-super).
            $editable['dtend_plan'] = false;
            $editable['onec_id'] = false;
        }

        self::applyIntegrityLocks($editable, $row, false);

        return [
            'allowed' => true,
            'deny_reason' => '',
            'editable' => $editable,
            'lock_dtend_timing' => $lockDtendTiming,
            'time_sec' => $timeSec,
            'time_net' => $timeNet,
        ];
    }

    /**
     * Event-driven integrity + money lock + hide integration fields when modules off.
     *
     * @param array<string,bool> $editable
     * @param array<string,mixed> $row
     */
    private static function applyIntegrityLocks(array &$editable, array $row, bool $isSuper): void
    {
        // Oper: SUMM / SUMM_DEFAULT only for SUPERADMIN_ACCESS (admins get HIDDEN too).
        if (!$isSuper) {
            $editable['summ'] = false;
            $editable['summ_default'] = false;
        }

        if ((int)($row['id_event_parent'] ?? 0) > 0) {
            $editable['id_user'] = false;
            $editable['id_event_parent'] = false;
        }
        // Parent link / token are system fields — never free-edited in UI.
        $editable['id_event_parent'] = false;
        $editable['token'] = false;

        if (!ModuleManager::isEnabled('terminals')) {
            $editable['id_terminal'] = false;
        }
        if (!ModuleManager::isEnabled('terminals') && !ModuleManager::isEnabled('likespa')) {
            $editable['onec_id'] = false;
            $editable['onec_bracelet'] = false;
        }
    }

    public static function canEditField(array $acl, string $field): bool
    {
        $editable = is_array($acl['editable'] ?? null) ? $acl['editable'] : [];
        return !empty($editable[$field]);
    }

    /**
     * Merge POST into data, keeping locked fields from $existing; apply time_net end shift.
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $existing Current DB row (empty for create)
     * @param array<string,mixed> $acl from forRow()
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post, array $existing, array $acl): array
    {
        // Seed post with locked values so validation still sees required fields.
        $merged = $post;
        foreach (self::ALL_FIELDS as $field) {
            if (!self::canEditField($acl, $field) && array_key_exists($field, $existing)) {
                $merged[$field] = self::postValueFromRow($field, $existing);
            }
        }

        // Checkboxes: locked enabled/restored must not disappear from POST.
        if (!self::canEditField($acl, 'enabled')) {
            $merged['enabled'] = !empty($existing['enabled']) ? '1' : '';
        }
        if (!self::canEditField($acl, 'restored')) {
            $merged['restored'] = !empty($existing['restored']) ? '1' : '';
        }

        $data = ProceduresRepository::dataFromPost($merged);

        // Non-super cannot invent prices: keep existing, or seed from catalog on create.
        if (!self::canEditField($acl, 'summ')) {
            if (array_key_exists('summ', $existing)) {
                $data['summ'] = max(0, (int)$existing['summ']);
            } elseif ((int)$data['summ'] < 1 && (int)$data['id_good'] > 0
                && class_exists('ProductsRepository', false)
            ) {
                $product = ProductsRepository::find((int)$data['id_good']);
                if (is_array($product)) {
                    $data['summ'] = max(0, (int)round((float)($product['price'] ?? 0)));
                }
            }
        }
        if (!self::canEditField($acl, 'summ_default')) {
            if (array_key_exists('summ_default', $existing)) {
                $data['summ_default'] = max(0, (int)$existing['summ_default']);
            } else {
                $data['summ_default'] = (int)$data['summ'];
            }
        }

        // Timing: when product has time_sec, end = start + time_net (oper DoMoreChecks).
        if (!empty($acl['lock_dtend_timing'])) {
            $timeNet = (int)($acl['time_net'] ?? 0);
            // Re-read product for possibly changed id_good if that field is editable.
            $goodId = (int)$data['id_good'];
            if ($goodId > 0 && class_exists('ProductsRepository', false)) {
                $product = ProductsRepository::find($goodId);
                if (is_array($product) && (int)($product['time_sec'] ?? 0) > 0) {
                    $timeNet = max(0, (int)($product['time_net'] ?? 0));
                }
            }
            if ($timeNet > 0) {
                $data['dtend'] = (int)$data['dtstart'] + $timeNet;
            } elseif (isset($existing['dtend'])) {
                $data['dtend'] = (int)$existing['dtend'];
            }
        }

        if (!self::canEditField($acl, 'dtend_plan') && isset($existing['dtend_plan'])) {
            $data['dtend_plan'] = (int)$existing['dtend_plan'];
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $row
     * @return mixed
     */
    private static function postValueFromRow(string $field, array $row)
    {
        if ($field === 'dtstart' || $field === 'dtend' || $field === 'dtend_plan') {
            return ProceduresRepository::tsToLocal((int)($row[$field] ?? 0));
        }
        return $row[$field] ?? '';
    }
}
