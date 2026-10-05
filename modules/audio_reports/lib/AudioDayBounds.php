<?php
declare(strict_types=1);

/**
 * Legacy day-bound windows on base_system for rating / recording.
 */
final class AudioDayBounds
{
    /** @return array<string,int> */
    public static function defaults(): array
    {
        return [
            'hourStartOffRate' => 9,
            'minStartOffRate' => 0,
            'hourEndOffRate' => 22,
            'minEndOffRate' => 0,
            'hourStartOffRecord' => 9,
            'minStartOffRecord' => 0,
            'hourEndOffRecord' => 22,
            'minEndOffRecord' => 0,
        ];
    }

    /** @return array<string,int> */
    public static function read(): array
    {
        $out = self::defaults();
        if (!Database::tableExists('base_system')) {
            return $out;
        }
        $row = Database::pdo()->query('SELECT * FROM base_system ORDER BY id ASC LIMIT 1')->fetch();
        if (!$row) {
            return $out;
        }
        foreach ($out as $key => $default) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                $out[$key] = max(0, min(59, (int)$row[$key]));
                if (strpos($key, 'hour') === 0) {
                    $out[$key] = max(0, min(23, (int)$row[$key]));
                }
            }
        }
        return $out;
    }

    /** @param array<string,int> $data */
    public static function save(array $data): void
    {
        if (!Database::tableExists('base_system')) {
            throw new RuntimeException('base_system missing');
        }
        $defaults = self::defaults();
        $vals = [];
        foreach ($defaults as $key => $default) {
            $v = (int)($data[$key] ?? $default);
            if (strpos($key, 'hour') === 0) {
                $v = max(0, min(23, $v));
            } else {
                $v = max(0, min(59, $v));
            }
            $vals[$key] = $v;
        }
        foreach ($vals as $col => $_) {
            if (!Database::columnExists('base_system', $col)) {
                throw new RuntimeException('Missing column base_system.' . $col . ' — run audio_reports migrations');
            }
        }
        $row = Database::pdo()->query('SELECT id FROM base_system ORDER BY id ASC LIMIT 1')->fetch();
        if (!$row) {
            throw new RuntimeException('base_system empty');
        }
        $sql = 'UPDATE base_system SET
            hourStartOffRate = ?, minStartOffRate = ?, hourEndOffRate = ?, minEndOffRate = ?,
            hourStartOffRecord = ?, minStartOffRecord = ?, hourEndOffRecord = ?, minEndOffRecord = ?
            WHERE id = ?';
        Database::pdo()->prepare($sql)->execute([
            $vals['hourStartOffRate'], $vals['minStartOffRate'], $vals['hourEndOffRate'], $vals['minEndOffRate'],
            $vals['hourStartOffRecord'], $vals['minStartOffRecord'], $vals['hourEndOffRecord'], $vals['minEndOffRecord'],
            (int)$row['id'],
        ]);
    }
}
