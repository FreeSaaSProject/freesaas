<?php
declare(strict_types=1);

/**
 * Stem/morph runner — gated on THIRD_PARTY (phpMorphy / LinguaStemRu).
 */
final class StemRunner
{
    public static function libsAvailable(): bool
    {
        // Not vendored until license decision in modules/audio/THIRD_PARTY.md
        return is_file(base_path('modules/audio_pipeline/vendor/phpmorphy/src/common.php'))
            || is_file(base_path('libs/phpmorphy/src/common.php'));
    }

    /**
     * @return array{ok:bool,processed:int,skipped:int,message:string}
     */
    public static function run(): array
    {
        if (!self::libsAvailable()) {
            return [
                'ok' => false,
                'processed' => 0,
                'skipped' => 0,
                'message' => 'stem_libs_unavailable',
            ];
        }
        // Placeholder for morph implementation after license clearance
        return [
            'ok' => false,
            'processed' => 0,
            'skipped' => 0,
            'message' => 'stem_not_implemented',
        ];
    }

    public static function invalidateCaches(): void
    {
        if (Database::tableExists('base_records_stem_key_cache')) {
            Database::pdo()->exec('TRUNCATE TABLE base_records_stem_key_cache');
        }
        // Word cache can stay; key cache must refresh when dicts change
    }
}
