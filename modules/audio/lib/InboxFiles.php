<?php
declare(strict_types=1);

/**
 * Inbox (oper /audio/) listing — same mp3/m4a glob as IngestRunner / recordParser.php.
 */
final class InboxFiles
{
    /** Relative web path label, e.g. /audio */
    public static function relativeDir(): string
    {
        $cfg = AudioSettingsRepository::storageConfig();
        $inbox = trim((string)($cfg['audio_inbox'] ?? 'audio'), '/');
        return '/' . ($inbox !== '' ? $inbox : 'audio');
    }

    /** Count files waiting in inbox (mp3 + m4a). */
    public static function count(): int
    {
        try {
            $cfg = AudioSettingsRepository::storageConfig();
            $storage = FileStorage::fromSettings();
            $inbox = '/' . trim((string)($cfg['audio_inbox'] ?? 'audio'), '/') . '/';
            $files = array_merge(
                $storage->glob($inbox, '*.mp3'),
                $storage->glob($inbox, '*.m4a')
            );
            $storage->close();
            $n = 0;
            foreach ($files as $path) {
                $base = basename((string)$path);
                if ($base === '' || $base === '.' || $base === '..') {
                    continue;
                }
                if (is_string($path) && @is_dir($path)) {
                    continue;
                }
                $n++;
            }
            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }
}
