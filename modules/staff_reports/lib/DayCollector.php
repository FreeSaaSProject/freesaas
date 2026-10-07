<?php
declare(strict_types=1);

/** Collect procedures / windows for a staff day report. */
final class DayCollector
{
    /**
     * @param array<string,mixed> $config
     * @return array{
     *   unique_clients:int,
     *   procedures:list<array{id:int,client_id:int,dt_start:int,dt_end:int,type:int,id_good:int,dt_create:int}>,
     *   analysis_procedures:list<array{id:int,client_id:int,dt_start:int,dt_end:int,type:int,id_good:int,dt_create:int}>,
     *   windows:list<array{client_id:int,win_start:int,win_end:int,procedure_id:int}>
     * }
     */
    public static function collect(int $employeeId, int $dayStart, int $dayEnd, array $config): array
    {
        $empty = [
            'unique_clients' => 0,
            'procedures' => [],
            'analysis_procedures' => [],
            'windows' => [],
        ];
        if ($employeeId < 1 || $dayStart < 1 || $dayEnd < $dayStart) {
            return $empty;
        }
        if (!Database::tableExists('base_procedures')) {
            return $empty;
        }

        $field = (string)($config['analysis']['staff_field'] ?? StaffReportConfig::FIELD_OPERATOR);
        if ($field !== StaffReportConfig::FIELD_ADMIN) {
            $field = StaffReportConfig::FIELD_OPERATOR;
        }
        $mode = (string)($config['analysis']['mode'] ?? StaffReportConfig::MODE_PROCEDURE_SLOT);
        $excluded = [];
        foreach (StaffReportConfig::intList($config['excluded_procedure_type_ids'] ?? []) as $tid) {
            $excluded[$tid] = true;
        }

        $goods = self::goodsTimeNet();
        $hasCreate = Database::columnExists('base_procedures', 'DTCREATE');
        $cols = 'ID, ID_USER, ID_GOOD, DTSTART, DTEND, TYPE, SUMM';
        if ($hasCreate) {
            $cols .= ', DTCREATE';
        }

        // Slot modes filter by DTSTART in day; booking_create also matches DTCREATE in day.
        if ($mode === StaffReportConfig::MODE_BOOKING_CREATE && $hasCreate) {
            $sql = "SELECT {$cols} FROM base_procedures
                    WHERE {$field} = ?
                      AND ENABLED = 1 AND SUMM > 0
                      AND ((DTSTART >= ? AND DTSTART <= ?) OR (DTCREATE >= ? AND DTCREATE <= ?))
                    ORDER BY DTSTART ASC, ID ASC";
            $params = [$employeeId, $dayStart, $dayEnd, $dayStart, $dayEnd];
        } else {
            $sql = "SELECT {$cols} FROM base_procedures
                    WHERE {$field} = ?
                      AND ENABLED = 1 AND SUMM > 0
                      AND DTSTART >= ? AND DTSTART <= ?
                    ORDER BY DTSTART ASC, ID ASC";
            $params = [$employeeId, $dayStart, $dayEnd];
        }

        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $procedures = [];
        $unique = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $clientId = (int)($row['ID_USER'] ?? 0);
            if ($clientId > 0) {
                $unique[$clientId] = true;
            }
            $dtStart = (int)($row['DTSTART'] ?? 0);
            $dtEnd = (int)($row['DTEND'] ?? 0);
            $dtCreate = $hasCreate ? (int)($row['DTCREATE'] ?? 0) : 0;
            if ($dtEnd <= $dtStart) {
                $goodId = (int)($row['ID_GOOD'] ?? 0);
                $timeNet = (int)($goods[$goodId] ?? 0);
                if ($timeNet > 0) {
                    $dtEnd = $dtStart + $timeNet;
                }
            }
            $procType = (int)($row['TYPE'] ?? 0);
            if ($dtStart > 0 && $dtEnd > $dtStart) {
                $procedures[] = [
                    'id' => (int)($row['ID'] ?? 0),
                    'client_id' => $clientId,
                    'dt_start' => $dtStart,
                    'dt_end' => $dtEnd,
                    'type' => $procType,
                    'id_good' => (int)($row['ID_GOOD'] ?? 0),
                    'dt_create' => $dtCreate,
                ];
            } elseif ($mode === StaffReportConfig::MODE_BOOKING_CREATE && $dtCreate > 0) {
                $procedures[] = [
                    'id' => (int)($row['ID'] ?? 0),
                    'client_id' => $clientId,
                    'dt_start' => $dtCreate,
                    'dt_end' => $dtCreate + 1,
                    'type' => $procType,
                    'id_good' => (int)($row['ID_GOOD'] ?? 0),
                    'dt_create' => $dtCreate,
                ];
            }
        }

        $analysis = [];
        foreach ($procedures as $proc) {
            if (isset($excluded[(int)$proc['type']])) {
                continue;
            }
            $analysis[] = $proc;
        }

        $windows = self::buildWindows($analysis, $config, $mode);

        return [
            'unique_clients' => count($unique),
            'procedures' => $procedures,
            'analysis_procedures' => $analysis,
            'windows' => $windows,
        ];
    }

    /**
     * @param list<array{id:int,client_id:int,dt_start:int,dt_end:int,type:int,id_good:int,dt_create:int}> $procedures
     * @param array<string,mixed> $config
     * @return list<array{client_id:int,win_start:int,win_end:int,procedure_id:int}>
     */
    public static function buildWindows(array $procedures, array $config, string $mode): array
    {
        $windows = [];
        if ($mode === StaffReportConfig::MODE_SCORE_TIME) {
            return $windows;
        }
        $slotBuf = max(0, (int)($config['analysis']['procedure_time_buffer'] ?? 1600));
        $bookBuf = max(0, (int)($config['analysis']['booking_buffer_sec'] ?? 900));
        foreach ($procedures as $proc) {
            $clientId = (int)$proc['client_id'];
            if ($clientId < 1) {
                continue;
            }
            if ($mode === StaffReportConfig::MODE_BOOKING_CREATE) {
                $center = (int)($proc['dt_create'] ?? 0);
                if ($center < 1) {
                    $center = (int)$proc['dt_start'];
                }
                $winStart = $center - $bookBuf;
                $winEnd = $center + $bookBuf;
            } else {
                $winStart = (int)$proc['dt_start'] - $slotBuf;
                $winEnd = (int)$proc['dt_end'] + $slotBuf;
            }
            if ($winEnd <= $winStart) {
                continue;
            }
            $windows[] = [
                'client_id' => $clientId,
                'win_start' => $winStart,
                'win_end' => $winEnd,
                'procedure_id' => (int)$proc['id'],
            ];
        }
        return $windows;
    }

    /** @return array<int,int> good_id => TIME_NET */
    private static function goodsTimeNet(): array
    {
        $out = [];
        if (!Database::tableExists('base_goods') || !Database::columnExists('base_goods', 'TIME_NET')) {
            return $out;
        }
        $st = Database::pdo()->query('SELECT ID, TIME_NET FROM base_goods');
        if ($st) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $out[(int)$row['ID']] = (int)$row['TIME_NET'];
            }
        }
        return $out;
    }
}
