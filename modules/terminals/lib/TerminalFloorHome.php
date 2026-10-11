<?php
declare(strict_types=1);

/**
 * Oper-parity extras for /t home: NUM, history, diag, read-only day load.
 */
final class TerminalFloorHome
{
    /**
     * @param array<string,mixed> $terminal
     * @return array{row:?array<string,mixed>,need_prompt:bool,num:int,saved:bool}
     */
    public static function handleBraceletNum(array $terminal, string $braceletUid, int $braceletNumIn): array
    {
        $promptOn = !empty($terminal['is_bracelet_num_prompt'])
            && empty($terminal['masseur_mode'])
            && empty($terminal['is_term_admin_mode']);
        $row = BraceletsRepository::findByUid($braceletUid);
        $saved = false;

        if ($promptOn && $braceletNumIn < 0) {
            if ($row !== null) {
                BraceletsRepository::setNum((int)$row['id'], 0);
                $row = BraceletsRepository::find((int)$row['id']);
            }
            return ['row' => $row, 'need_prompt' => true, 'num' => 0, 'saved' => false];
        }

        if ($promptOn && $braceletNumIn > 0) {
            if ($row === null) {
                $id = BraceletsRepository::create([
                    'uid' => $braceletUid,
                    'uid2' => $braceletUid,
                    'num' => $braceletNumIn,
                ]);
                $row = BraceletsRepository::find($id);
            } else {
                BraceletsRepository::setNum((int)$row['id'], $braceletNumIn);
                $row = BraceletsRepository::find((int)$row['id']);
            }
            $saved = true;
        }

        $num = (int)($row['num'] ?? 0);
        $need = $promptOn && $num < 1;
        return ['row' => $row, 'need_prompt' => $need, 'num' => $num, 'saved' => $saved];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function clientHistory(int $clientId, int $limit = 10): array
    {
        if ($clientId < 1 || !class_exists(ProceduresRepository::class, false)) {
            return [];
        }
        $res = ProceduresRepository::list([
            'id_user' => $clientId,
            'order' => 'p.DTSTART DESC',
            'limit' => max(1, min(20, $limit)),
            'offset' => 0,
            'skip_total' => true,
        ]);
        $out = [];
        $dayStart = (int)mktime(0, 0, 0);
        $cancelFloor = (int)mktime(8, 0, 0);
        $now = time();
        foreach ($res['items'] as $row) {
            $dtStart = (int)($row['dtstart'] ?? 0);
            $dtEnd = (int)($row['dtend'] ?? 0);
            $mins = $dtEnd > $dtStart ? (int)round(($dtEnd - $dtStart) / 60) : 0;
            $opId = (int)($row['id_operator'] ?? 0);
            $enabled = !empty($row['enabled']);
            $canCancel = $enabled && $dtStart >= $cancelFloor;
            $out[] = [
                'id' => (int)($row['id'] ?? 0),
                'dtstart' => $dtStart,
                'minutes' => $mins,
                'enabled' => $enabled,
                'product_name' => (string)($row['product_name'] ?? ('#' . (int)($row['id_good'] ?? 0))),
                'operator_id' => $opId,
                'operator_acronym' => $opId > 0 && class_exists(UsersRepository::class, false)
                    ? UsersRepository::shortName((string)($row['operator_name'] ?? ''))
                    : '',
                'can_cancel' => $canCancel,
                'cancel_staff_only' => $canCancel && $dtStart < $now,
                'today' => $dtStart >= $dayStart,
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $terminal
     * @param array<string,mixed> $guest
     * @return array<string,mixed>
     */
    public static function diag(
        array $terminal,
        string $braceletUid,
        array $guest,
        int $claimedAdminId,
        bool $disable1c,
        int $cacheTtlSec
    ): array {
        $termName = trim((string)($terminal['name'] ?? ''));
        $termShort = $termName !== '' ? (explode(' ', $termName)[0] ?? $termName) : ('#' . (int)($terminal['id'] ?? 0));
        $adminAcronym = '';
        if ($claimedAdminId > 0 && class_exists(UsersRepository::class, false)) {
            $u = UsersRepository::find($claimedAdminId);
            $adminAcronym = UsersRepository::shortName(
                trim((string)($u['name'] ?? $u['login'] ?? ''))
            );
        }
        return [
            'onec_requests' => OnecClient::requestCount(),
            'accessed_at' => time(),
            'cache_ttl' => max(0, $cacheTtlSec),
            'bracelet_uid' => $braceletUid,
            'terminal_short' => $termShort,
            'terminal_name' => $termName,
            'admin_id' => $claimedAdminId,
            'admin_acronym' => $adminAcronym,
            'onec_stub' => $disable1c || !empty($guest['stub']),
            'onec_live' => TerminalsConfig::onecLive() && !$disable1c,
            'guest_onec_id' => (string)($guest['client_id'] ?? ''),
        ];
    }

    /**
     * Read-only staff load board for today (non-interactive).
     *
     * @return array{
     *   operators:list<array{id:int,name:string,acronym:string,color:string,is_hidden?:bool,load_pct?:int,load_min?:bool}>,
     *   blocks:list<array{operator_id:int,label:string,top_pct:float,height_pct:float,color:string,dt_start:int,dt_end:int}>,
     *   day_start:int,
     *   day_end:int,
     *   hour_marks:list<array{label:string,top_pct:float}>
     * }
     */
    public static function dayLoad(): array
    {
        $dayStart = (int)mktime(0, 0, 0);
        $rangeStart = (int)mktime(9, 0, 0);
        $rangeEnd = (int)mktime(23, 0, 0);
        $span = max(1, $rangeEnd - $rangeStart);

        $operators = [];
        $opOrder = [];
        if (class_exists(WorkweeksRepository::class, false)) {
            foreach (WorkweeksRepository::listForDay($dayStart) as $shift) {
                $opId = (int)($shift['id_operator'] ?? 0);
                if ($opId < 1 || !empty($shift['assist_team']) || isset($opOrder[$opId])) {
                    continue;
                }
                $name = trim((string)($shift['operator_name'] ?? ''));
                if ($name === '' && class_exists(UsersRepository::class, false)) {
                    $u = UsersRepository::find($opId);
                    $name = trim((string)($u['name'] ?? $u['login'] ?? ('#' . $opId)));
                }
                $color = self::colorForId($opId);
                if (class_exists(UsersRepository::class, false)) {
                    $u = UsersRepository::find($opId);
                    $c = trim((string)($u['color'] ?? ''));
                    if ($c !== '') {
                        $color = $c;
                    }
                }
                $operators[] = [
                    'id' => $opId,
                    'name' => $name !== '' ? $name : ('#' . $opId),
                    'acronym' => class_exists(UsersRepository::class, false)
                        ? UsersRepository::shortName($name !== '' ? $name : ('#' . $opId))
                        : ('#' . $opId),
                    'color' => $color,
                    'is_hidden' => !empty($shift['is_hidden']),
                ];
                $opOrder[$opId] = count($operators) - 1;
            }
        }

        $blocks = [];
        if (class_exists(ProceduresRepository::class, false)) {
            $rows = ProceduresRepository::listForDayRange($dayStart, $dayStart + 86400);
            foreach ($rows as $row) {
                if (empty($row['enabled'])) {
                    continue;
                }
                $opId = (int)($row['id_operator'] ?? 0);
                if ($opId < 1) {
                    continue;
                }
                if ($opOrder === [] || !isset($opOrder[$opId])) {
                    // Still show block if operator not on shift list — append.
                    if (!isset($opOrder[$opId])) {
                        $name = trim((string)($row['operator_name'] ?? ('#' . $opId)));
                        $operators[] = [
                            'id' => $opId,
                            'name' => $name,
                            'acronym' => class_exists(UsersRepository::class, false)
                                ? UsersRepository::shortName($name)
                                : ('#' . $opId),
                            'color' => self::colorForId($opId),
                        ];
                        $opOrder[$opId] = count($operators) - 1;
                    }
                }
                $dtStart = (int)($row['dtstart'] ?? 0);
                $dtEnd = (int)($row['dtend'] ?? 0);
                if ($dtEnd <= $dtStart) {
                    $dtEnd = $dtStart + 900;
                }
                $top = max(0.0, min(100.0, (($dtStart - $rangeStart) / $span) * 100));
                $height = max(1.2, min(100.0 - $top, (($dtEnd - $dtStart) / $span) * 100));
                $client = trim((string)($row['client_name'] ?? ''));
                $label = trim((string)($row['id'] ?? '')) . ' '
                    . ($client !== '' ? $client : '')
                    . ' ' . date('H:i', $dtStart) . '–' . date('H:i', $dtEnd);
                $blocks[] = [
                    'operator_id' => $opId,
                    'col' => (int)$opOrder[$opId],
                    'label' => trim($label),
                    'top_pct' => $top,
                    'height_pct' => $height,
                    'color' => $operators[$opOrder[$opId]]['color'] ?? self::colorForId($opId),
                    'dt_start' => $dtStart,
                    'dt_end' => $dtEnd,
                ];
            }
        }

        $marks = [];
        for ($h = 9; $h <= 22; $h++) {
            $ts = (int)mktime($h, 0, 0);
            $marks[] = [
                'label' => sprintf('%d:00', $h),
                'top_pct' => (($ts - $rangeStart) / $span) * 100,
            ];
        }

        // Relative load % next to masseur chips (no money UI / no capability gate).
        if ($operators !== [] && class_exists(ProceduresDayLoad::class, false)) {
            $loads = ProceduresDayLoad::forDay($dayStart, $dayStart + 86400, null);
            if ($loads !== []) {
                foreach ($operators as &$op) {
                    $oid = (int)($op['id'] ?? 0);
                    if ($oid < 1 || !empty($op['is_hidden']) || !isset($loads[$oid])) {
                        continue;
                    }
                    $op['load_pct'] = (int)$loads[$oid]['pct'];
                    $op['load_min'] = !empty($loads[$oid]['is_min']);
                }
                unset($op);
            }
        }

        return [
            'operators' => $operators,
            'blocks' => $blocks,
            'day_start' => $rangeStart,
            'day_end' => $rangeEnd,
            'hour_marks' => $marks,
        ];
    }

    /**
     * @param array<string,mixed> $guest
     * @return array{ticket_name:string,time_sec:int,deadline:int,h:int,m:int,s:int}
     */
    public static function ticketClock(array $guest): array
    {
        $timeSec = max(0, (int)($guest['time'] ?? 0));
        $ticket = trim((string)($guest['ticket_name'] ?? $guest['name'] ?? ''));
        if ($ticket === '') {
            $ticket = t('terminals.t.ticket_unknown');
        }
        $deadline = time() + $timeSec;
        $dayCap = (int)mktime(23, 0, 0);
        if ($deadline > $dayCap) {
            $over = $deadline - $dayCap;
            $timeSec = max(0, $timeSec - $over);
            $deadline = $dayCap;
        }
        $h = (int)floor($timeSec / 3600);
        $m = (int)floor(($timeSec % 3600) / 60);
        $s = (int)($timeSec % 60);
        return [
            'ticket_name' => $ticket,
            'time_sec' => $timeSec,
            'deadline' => $deadline,
            'h' => $h,
            'm' => $m,
            's' => $s,
        ];
    }

    private static function colorForId(int $id): string
    {
        $palette = ['#3d7ea6', '#2f6f4e', '#8a5a2b', '#6a3a8a', '#a63d3d', '#3d8a7a', '#8a7a3d'];
        return $palette[abs($id) % count($palette)];
    }
}
