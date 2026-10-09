<?php
declare(strict_types=1);

/**
 * Strict per-user ownership for wellness data.
 * No SUPERADMIN bypass — every user sees only their own rows.
 */
final class WellnessOwner
{
    public static function userId(): int
    {
        $id = Auth::userId();
        if ($id < 1) {
            throw new RuntimeException(t('wellness.err.auth_required'));
        }
        return $id;
    }

    /**
     * INSERT into a wellness table with ID_USER ownership.
     * Dual-writes ID_OPERATOR when the legacy oper column still exists (NOT NULL on prod).
     *
     * @param array<string,scalar|null> $fields column => value (without ID_USER / ID_OPERATOR)
     */
    public static function insertOwned(string $table, array $fields, ?int $userId = null): int
    {
        $uid = $userId !== null ? $userId : self::userId();
        if ($uid < 1) {
            throw new RuntimeException(t('wellness.err.auth_required'));
        }
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
        if ($table === '' || !Database::tableExists($table)) {
            throw new RuntimeException(t('wellness.err.not_found'));
        }
        $cols = ['ID_USER'];
        $vals = [$uid];
        if (Database::columnExists($table, 'ID_OPERATOR')) {
            $cols[] = 'ID_OPERATOR';
            $vals[] = $uid;
        }
        foreach ($fields as $col => $val) {
            $col = (string)$col;
            if ($col === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col)) {
                continue;
            }
            if (strcasecmp($col, 'ID_USER') === 0 || strcasecmp($col, 'ID_OPERATOR') === 0) {
                continue;
            }
            $cols[] = $col;
            $vals[] = $val;
        }
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . $ph . ')';
        $st = Database::pdo()->prepare($sql);
        $st->execute($vals);
        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    public static function requireOwned(?array $row, string $notFoundKey = 'wellness.err.not_found'): array
    {
        if ($row === null) {
            throw new InvalidArgumentException(t($notFoundKey));
        }
        $owner = (int)($row['user_id'] ?? $row['ID_USER'] ?? 0);
        if ($owner !== self::userId()) {
            throw new RuntimeException(t('wellness.err.forbidden'));
        }
        return $row;
    }

    /** Day start/end unix for Y-m-d in server timezone. @return array{0:int,1:int} */
    public static function dayRange(string $ymd): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            $ymd = date('Y-m-d');
        }
        $start = strtotime($ymd . ' 00:00:00');
        $end = strtotime($ymd . ' 23:59:59');
        if ($start === false || $end === false) {
            $start = strtotime(date('Y-m-d') . ' 00:00:00') ?: time();
            $end = $start + 86399;
        }
        return [(int)$start, (int)$end];
    }

    /**
     * Parse datetime-local / date / unix into unix timestamp.
     *
     * @param mixed $raw
     */
    public static function parseDt($raw, ?int $fallback = null): int
    {
        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            $n = (int)$raw;
            if ($n > 0) {
                return $n;
            }
        }
        $s = trim((string)$raw);
        if ($s !== '') {
            $ts = strtotime($s);
            if ($ts !== false) {
                return (int)$ts;
            }
        }
        return $fallback ?? time();
    }

    /** Month start/end unix. @return array{0:int,1:int,2:int} year, month days count helpers via year/month */
    public static function monthRange(int $year, int $month): array
    {
        $year = max(1970, min(2100, $year));
        $month = max(1, min(12, $month));
        $start = strtotime(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        if ($start === false) {
            $start = strtotime(date('Y-m-01 00:00:00')) ?: time();
        }
        $end = strtotime(date('Y-m-t 23:59:59', $start));
        if ($end === false) {
            $end = $start + 31 * 86400;
        }
        return [(int)$start, (int)$end];
    }
}
