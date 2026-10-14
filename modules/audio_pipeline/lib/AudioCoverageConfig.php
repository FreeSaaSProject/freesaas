<?php
declare(strict_types=1);

/**
 * Coverage / procedure-match settings.
 * Global: cfg/modules/audio.ini [audio].
 * Overrides: base_records_coverage_profiles (per status or per staff).
 */
final class AudioCoverageConfig
{
    /**
     * @return array{
     *   disable_outside_coverage:bool,
     *   match_procedures:bool,
     *   procedure_buffer_sec:int,
     *   min_procedure_sec:int
     * }
     */
    public static function defaults(): array
    {
        return [
            'disable_outside_coverage' => false,
            'match_procedures' => false,
            'procedure_buffer_sec' => 1600,
            'min_procedure_sec' => 360,
        ];
    }

    /**
     * @return array{
     *   disable_outside_coverage:bool,
     *   match_procedures:bool,
     *   procedure_buffer_sec:int,
     *   min_procedure_sec:int
     * }
     */
    public static function read(): array
    {
        $d = self::defaults();
        $d['disable_outside_coverage'] = self::truthy(Config::get(
            'audio',
            'disable_outside_coverage',
            $d['disable_outside_coverage'] ? '1' : '0'
        ));
        $d['match_procedures'] = self::truthy(Config::get(
            'audio',
            'match_procedures',
            $d['match_procedures'] ? '1' : '0'
        ));
        $d['procedure_buffer_sec'] = max(0, (int)Config::get(
            'audio',
            'procedure_buffer_sec',
            $d['procedure_buffer_sec']
        ));
        $d['min_procedure_sec'] = max(0, (int)Config::get(
            'audio',
            'min_procedure_sec',
            $d['min_procedure_sec']
        ));

        return $d;
    }

    /**
     * Resolved settings for a staff member: user profile → position profile → global.
     *
     * @return array{
     *   disable_outside_coverage:bool,
     *   match_procedures:bool,
     *   procedure_buffer_sec:int,
     *   min_procedure_sec:int,
     *   source:string
     * }
     */
    public static function forAdmin(int $adminId): array
    {
        $base = self::read();
        $base['source'] = 'global';
        if ($adminId < 1 || !class_exists('AudioCoverageProfiles', false)) {
            return $base;
        }
        $override = AudioCoverageProfiles::resolvedForAdmin($adminId);
        if ($override === null) {
            return $base;
        }
        $cfg = is_array($override['config'] ?? null) ? $override['config'] : [];
        if (array_key_exists('disable_outside_coverage', $cfg)) {
            $base['disable_outside_coverage'] = !empty($cfg['disable_outside_coverage']);
        }
        if (array_key_exists('match_procedures', $cfg)) {
            $base['match_procedures'] = !empty($cfg['match_procedures']);
        }
        if (isset($cfg['procedure_buffer_sec'])) {
            $base['procedure_buffer_sec'] = max(0, (int)$cfg['procedure_buffer_sec']);
        }
        if (isset($cfg['min_procedure_sec'])) {
            $base['min_procedure_sec'] = max(0, (int)$cfg['min_procedure_sec']);
        }
        $base['source'] = (string)($override['source'] ?? 'profile');

        return $base;
    }

    /** @param array<string,mixed> $data */
    public static function save(array $data): void
    {
        $d = self::defaults();
        Config::set('audio', 'disable_outside_coverage', !empty($data['disable_outside_coverage']) ? '1' : '0');
        Config::set('audio', 'match_procedures', !empty($data['match_procedures']) ? '1' : '0');
        Config::set(
            'audio',
            'procedure_buffer_sec',
            (string)max(0, (int)($data['procedure_buffer_sec'] ?? $d['procedure_buffer_sec']))
        );
        Config::set(
            'audio',
            'min_procedure_sec',
            (string)max(0, (int)($data['min_procedure_sec'] ?? $d['min_procedure_sec']))
        );
    }

    /** @param mixed $v */
    private static function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string)$v));

        return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'on';
    }
}
