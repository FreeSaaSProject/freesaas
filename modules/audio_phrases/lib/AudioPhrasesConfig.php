<?php
declare(strict_types=1);

/** Ini-backed settings ([audio_phrases] in cfg/modules/audio_phrases.ini). */
final class AudioPhrasesConfig
{
    public const CRON_JOB_ID = 'audio_phrases.daily';

    public static function words(): int
    {
        return max(2, min(8, (int)Config::get('audio_phrases', 'words', 3)));
    }

    public static function minInstances(): int
    {
        return max(1, (int)Config::get('audio_phrases', 'min_instances', 3));
    }

    public static function deltaSurround(): int
    {
        return max(0, min(10, (int)Config::get('audio_phrases', 'delta_surround', 3)));
    }

    public static function dailyAt(): string
    {
        $raw = trim((string)Config::get('audio_phrases', 'daily_at', '03:40'));
        if (!preg_match('/^\d{1,2}:\d{2}$/', $raw)) {
            return '03:40';
        }
        return $raw;
    }

    public static function backfillDays(): int
    {
        return max(0, min(14, (int)Config::get('audio_phrases', 'backfill_days', 3)));
    }

    /** Harvest top-phrases after calendar Re-® / day reparse (default on). */
    public static function withReparse(): bool
    {
        $raw = Config::get('audio_phrases', 'with_reparse', '1');
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower(trim((string)$raw));
        return !in_array($s, ['0', 'false', 'off', 'no', ''], true);
    }

    /** Whether nightly cron harvest is enabled (base_cron_job_state; default on). */
    public static function cronEnabled(): bool
    {
        if (!class_exists('Cron', false) || !Cron::tablesReady()) {
            return true;
        }
        foreach (Cron::catalogWithState() as $row) {
            if ((string)($row['job_id'] ?? '') === self::CRON_JOB_ID) {
                return (int)($row['enabled'] ?? 1) === 1;
            }
        }
        return true;
    }

    public static function setCronEnabled(bool $enabled): void
    {
        if (class_exists('Cron', false)) {
            Cron::setEnabled(self::CRON_JOB_ID, $enabled);
        }
        Config::set('audio_phrases', 'cron_enabled', $enabled ? '1' : '0');
    }

    /** @return list<string> */
    public static function stopwords(): array
    {
        $raw = trim((string)Config::get('audio_phrases', 'stopwords', ''));
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/u', mb_strtolower($raw, 'UTF-8')) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p !== '') {
                $out[$p] = true;
            }
        }
        return array_keys($out);
    }
}
