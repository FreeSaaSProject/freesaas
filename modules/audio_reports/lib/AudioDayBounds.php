<?php
declare(strict_types=1);

/**
 * Day-bound windows for rating / recording, stored in cfg/modules/audio_reports.ini [audio_reports].
 */
final class AudioDayBounds
{
    /** Public field → ini key. */
    public const KEYS = [
        'hourStartOffRate' => 'hour_start_off_rate',
        'minStartOffRate' => 'min_start_off_rate',
        'hourEndOffRate' => 'hour_end_off_rate',
        'minEndOffRate' => 'min_end_off_rate',
        'hourStartOffRecord' => 'hour_start_off_record',
        'minStartOffRecord' => 'min_start_off_record',
        'hourEndOffRecord' => 'hour_end_off_record',
        'minEndOffRecord' => 'min_end_off_record',
    ];

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
        foreach ($out as $key => $default) {
            $raw = Config::get('audio_reports', self::KEYS[$key], null);
            if ($raw !== null && $raw !== '') {
                $out[$key] = self::clamp($key, (int)$raw);
            }
        }
        return $out;
    }

    /** @param array<string,int> $data */
    public static function save(array $data): void
    {
        foreach (self::defaults() as $key => $default) {
            Config::set('audio_reports', self::KEYS[$key], (string)self::clamp($key, (int)($data[$key] ?? $default)));
        }
    }

    private static function clamp(string $key, int $v): int
    {
        return strpos($key, 'hour') === 0 ? max(0, min(23, $v)) : max(0, min(59, $v));
    }
}
