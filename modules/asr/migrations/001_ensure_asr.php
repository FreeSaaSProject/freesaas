<?php
declare(strict_types=1);

/**
 * ASR module has no schema — voice queue is filesystem under var/voice_transcribe/.
 * Migration exists so enable-via-UI has a no-op success path.
 */
return static function (PDO $pdo): void {
};
