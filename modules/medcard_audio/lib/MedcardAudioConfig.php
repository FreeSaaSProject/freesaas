<?php
declare(strict_types=1);

/** Ini-backed settings for medcard_audio ([medcard_audio] in cfg/modules/medcard_audio.ini). */
final class MedcardAudioConfig
{
    public const FILL_DIRECT = 'direct';
    public const FILL_PROPOSE = 'propose';

    /** @return array<string, string|int|float> */
    public static function defaults(): array
    {
        return [
            'bot_enabled' => 1,
            'fill_mode' => self::FILL_DIRECT,
            'llm_profile' => '',
            'max_tokens' => 8000,
            'confidence_default' => 0.75,
        ];
    }

    /** @return array<string, string> */
    public static function formValues(): array
    {
        return [
            'bot_enabled' => self::botEnabled() ? '1' : '0',
            'fill_mode' => self::fillMode(),
            'llm_profile' => self::llmProfile(),
            'max_tokens' => (string)self::maxTokens(),
            'confidence_default' => (string)self::confidenceDefault(),
        ];
    }

    /**
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        $btn = !empty($post['bot_enabled']) ? 1 : 0;
        Config::set('medcard_audio', 'bot_enabled', (string)$btn);

        $mode = (string)($post['fill_mode'] ?? self::FILL_DIRECT);
        if (!in_array($mode, [self::FILL_DIRECT, self::FILL_PROPOSE], true)) {
            $mode = self::FILL_DIRECT;
        }
        Config::set('medcard_audio', 'fill_mode', $mode);

        $profile = trim((string)($post['llm_profile'] ?? ''));
        if (strlen($profile) > 64) {
            $profile = substr($profile, 0, 64);
        }
        Config::set('medcard_audio', 'llm_profile', $profile);

        $tokens = max(1000, min(16000, (int)($post['max_tokens'] ?? 8000)));
        Config::set('medcard_audio', 'max_tokens', (string)$tokens);

        $conf = (float)($post['confidence_default'] ?? 0.75);
        if ($conf < 0.0) {
            $conf = 0.0;
        }
        if ($conf > 1.0) {
            $conf = 1.0;
        }
        Config::set('medcard_audio', 'confidence_default', (string)$conf);
    }

    public static function botEnabled(): bool
    {
        $v = Config::get('medcard_audio', 'bot_enabled', (string)self::defaults()['bot_enabled']);
        return $v === '1' || $v === 1 || $v === true || $v === 'true';
    }

    public static function fillMode(): string
    {
        $mode = (string)Config::get('medcard_audio', 'fill_mode', self::FILL_DIRECT);
        return in_array($mode, [self::FILL_DIRECT, self::FILL_PROPOSE], true) ? $mode : self::FILL_DIRECT;
    }

    public static function llmProfile(): string
    {
        return trim((string)Config::get('medcard_audio', 'llm_profile', ''));
    }

    public static function maxTokens(): int
    {
        return max(1000, min(16000, (int)Config::get('medcard_audio', 'max_tokens', '8000')));
    }

    public static function confidenceDefault(): float
    {
        $c = (float)Config::get('medcard_audio', 'confidence_default', '0.75');
        if ($c < 0.0) {
            return 0.0;
        }
        if ($c > 1.0) {
            return 1.0;
        }
        return $c;
    }
}
