<?php
declare(strict_types=1);

/**
 * Oper-parity terminal / folio links on procedures list (likespa + terminals).
 */
final class ProcedureFloorLinks
{
    /** @var array<int,array{name:string,code:string}> */
    private static $terminalCache = [];

    /** @var string|null claimed CODE for current user ('' = none, null = not loaded) */
    private static $claimedCode = null;

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onListRow($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }
        if (!ModuleManager::isEnabled('terminals') || !class_exists('TerminalsRepository', false)) {
            return $payload;
        }
        $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
        if ($row === []) {
            return $payload;
        }

        $terminalId = (int)($row['id_terminal'] ?? 0);
        $bracelet = trim((string)($row['onec_bracelet'] ?? ''));
        $term = $terminalId > 0 ? self::terminalMeta($terminalId) : null;
        $termName = $term !== null ? (string)$term['name'] : '';
        $procCode = $term !== null ? (string)$term['code'] : '';

        $bits = [];
        if ($termName !== '' || $terminalId > 0) {
            $bits[] = e(t('likespa.procedures.terminal_label')) . ': '
                . e($termName !== '' ? $termName : ('#' . $terminalId));
        }
        if ($bracelet !== '') {
            $bits[] = e(t('likespa.procedures.bracelet_label')) . ': ' . e($bracelet);
        }

        $links = [];
        $dtstart = (int)($row['dtstart'] ?? 0);
        $summ = (float)($row['summ'] ?? 0);
        $todayStart = (int)mktime(0, 0, 0);
        // Oper show_procedures: SHOW_ADMINISTRATOR_DATA + today + SUMM>0 → [Терминал!]
        // terminalid = currently claimed by logged-in admin, else procedure CODE.
        if (
            Auth::capability('SHOW_ADMINISTRATOR_DATA')
            && $dtstart >= $todayStart
            && $summ > 0
        ) {
            $termCode = self::claimedTerminalCode();
            if ($termCode === '') {
                $termCode = $procCode;
            }
            $q = ['braceletid' => $bracelet];
            if ($termCode !== '') {
                $q['terminalid'] = $termCode;
            }
            $href = '/t/?' . http_build_query($q);
            $links[] = '<a href="' . e($href) . '" target="_blank" rel="noopener">'
                . e(t('likespa.procedures.link_terminal')) . '</a>';
        }
        if (Auth::capability('SUPERADMIN_ACCESS') && $bracelet !== '') {
            $href = '/terminals/1c/clientByCard?' . http_build_query([
                'NFCUID' => $bracelet,
            ]);
            $links[] = '<a href="' . e($href) . '" target="_blank" rel="noopener">'
                . e(t('likespa.procedures.link_folio')) . '</a>';
        }

        $html = '';
        if ($bits !== [] || $links !== []) {
            $html = '<br><small class="muted">';
            if ($bits !== []) {
                $html .= implode(' · ', $bits);
            }
            if ($links !== []) {
                if ($bits !== []) {
                    $html .= ' · ';
                }
                $html .= implode(' · ', $links);
            }
            $html .= '</small>';
        }

        if ($html !== '') {
            $payload['html_after_id'] = (string)($payload['html_after_id'] ?? '') . $html;
        }

        return $payload;
    }

    /** CODE of terminal currently claimed by the logged-in staff (oper id_operator1). */
    private static function claimedTerminalCode(): string
    {
        if (self::$claimedCode !== null) {
            return self::$claimedCode;
        }
        self::$claimedCode = '';
        $adminId = Auth::userId();
        if ($adminId < 1 || !Database::tableExists('base_terminals')) {
            return self::$claimedCode;
        }
        $st = Database::pdo()->prepare(
            'SELECT CODE AS code FROM base_terminals
             WHERE ID_ADMIN = ? AND DT_ADMIN_VALID > ? AND IFNULL(ENABLED, 1) = 1
             ORDER BY DT_ADMIN_VALID DESC LIMIT 1'
        );
        $st->execute([$adminId, time()]);
        $code = trim((string)($st->fetchColumn() ?: ''));
        self::$claimedCode = $code;
        return self::$claimedCode;
    }

    /** @return array{name:string,code:string}|null */
    private static function terminalMeta(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        if (isset(self::$terminalCache[$id])) {
            return self::$terminalCache[$id];
        }
        $found = TerminalsRepository::find($id, false);
        if ($found === null) {
            return null;
        }
        $meta = [
            'name' => (string)($found['name'] ?? ''),
            'code' => (string)($found['code'] ?? ''),
        ];
        self::$terminalCache[$id] = $meta;
        return $meta;
    }
}
