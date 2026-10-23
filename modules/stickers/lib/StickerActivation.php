<?php
declare(strict_types=1);

/**
 * How stickers become active (DT_PRINT): only by scanning the QR on the stuck item,
 * or also by a staff checkbox at receipt time.
 */
final class StickerActivation
{
    public const MODE_SCAN = 'scan';
    public const MODE_MANUAL = 'manual';

    public static function mode(): string
    {
        if (!class_exists('Config', false)) {
            return self::MODE_SCAN;
        }
        $mode = strtolower(trim((string)Config::get('stickers', 'activation_mode', self::MODE_SCAN)));
        return $mode === self::MODE_MANUAL ? self::MODE_MANUAL : self::MODE_SCAN;
    }

    public static function setMode(string $mode): void
    {
        $mode = strtolower(trim($mode));
        if ($mode !== self::MODE_SCAN && $mode !== self::MODE_MANUAL) {
            throw new InvalidArgumentException(t('stickers.settings.err.activation_mode'));
        }
        Config::set('stickers', 'activation_mode', $mode);
    }

    public static function manualAllowed(): bool
    {
        return self::mode() === self::MODE_MANUAL;
    }
}
