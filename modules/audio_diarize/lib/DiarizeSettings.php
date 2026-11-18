<?php
declare(strict_types=1);

/**
 * Settings for audio_diarize: requires_profiles gate + match thresholds
 * (module enable is the master switch). Live: cfg/modules/audio_diarize.ini
 */
final class DiarizeSettings
{
    public const KEY_REQUIRES_PROFILES = 'requires_profiles';
    /** When 1: CollectRunner skips script credit if cue speaker matched another staff. Keys stay. */
    public const KEY_SCRIPTS_IGNORE_OTHER = 'scripts_ignore_other_speakers';
    /** Cue→profile cosine gate (edge + PHP rematch soft floor). */
    public const KEY_MATCH_CUE = 'match_cue';
    /** Min (top1 − top2) to accept a match; 0 = off. */
    public const KEY_MATCH_MARGIN = 'match_margin';

    public static function requiresProfiles(): bool
    {
        return (string)Config::get('audio_diarize', self::KEY_REQUIRES_PROFILES, '1') !== '0';
    }

    public static function scriptsIgnoreOtherSpeakers(): bool
    {
        return (string)Config::get('audio_diarize', self::KEY_SCRIPTS_IGNORE_OTHER, '1') !== '0';
    }

    public static function matchCue(): float
    {
        $v = (float)Config::get('audio_diarize', self::KEY_MATCH_CUE, (string)SpeakerService::MATCH_CUE);
        if ($v < 0.20) {
            $v = 0.20;
        }
        if ($v > 0.95) {
            $v = 0.95;
        }

        return $v;
    }

    public static function matchMargin(): float
    {
        $v = (float)Config::get('audio_diarize', self::KEY_MATCH_MARGIN, (string)SpeakerService::MATCH_MARGIN);
        if ($v < 0.0) {
            $v = 0.0;
        }
        if ($v > 0.50) {
            $v = 0.50;
        }

        return $v;
    }

    /**
     * @param array<string,mixed> $post
     * @param list<string> $warnings
     * @return list<string>
     */
    public static function save(array $post, array $warnings = []): array
    {
        $requires = !empty($post['requires_profiles']);
        Config::set('audio_diarize', self::KEY_REQUIRES_PROFILES, $requires ? '1' : '0');
        $ignoreOther = !empty($post['scripts_ignore_other_speakers']);
        Config::set('audio_diarize', self::KEY_SCRIPTS_IGNORE_OTHER, $ignoreOther ? '1' : '0');

        if (array_key_exists('match_cue', $post) && (string)$post['match_cue'] !== '') {
            $cue = (float)$post['match_cue'];
            if ($cue < 0.20) {
                $cue = 0.20;
            }
            if ($cue > 0.95) {
                $cue = 0.95;
            }
            Config::set('audio_diarize', self::KEY_MATCH_CUE, sprintf('%.2f', $cue));
        }
        if (array_key_exists('match_margin', $post) && (string)$post['match_margin'] !== '') {
            $mar = (float)$post['match_margin'];
            if ($mar < 0.0) {
                $mar = 0.0;
            }
            if ($mar > 0.50) {
                $mar = 0.50;
            }
            Config::set('audio_diarize', self::KEY_MATCH_MARGIN, sprintf('%.2f', $mar));
        }

        return $warnings;
    }
}
