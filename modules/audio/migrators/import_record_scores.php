<?php
declare(strict_types=1);

/**
 * Apply SCORE from var/audio_record_scores.tsv onto base_records by ID.
 * Does not touch other columns. Skips missing IDs.
 *
 * On host (after uploading TSV to var/audio_record_scores.tsv):
 *   php bin/data-migrate.php --module=audio --id=import_record_scores
 *
 * Or via /db → data migrators if UI lists them (prefer CLI).
 *
 * @return array{file:string,updated:int,skipped:int,missing:int}
 */
return static function (PDO $pdo, array $spec = []): array {
    if (!Database::tableExists('base_records')) {
        throw new RuntimeException('base_records missing');
    }
    $path = base_path('var/audio_record_scores.tsv');
    if (!is_file($path)) {
        throw new RuntimeException('Missing file: var/audio_record_scores.tsv (run export_record_scores locally and copy here)');
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Cannot read ' . $path);
    }

    $upd = $pdo->prepare('UPDATE base_records SET SCORE = ? WHERE ID = ?');
    $exists = $pdo->prepare('SELECT 1 FROM base_records WHERE ID = ? LIMIT 1');
    $updated = 0;
    $skipped = 0;
    $missing = 0;
    $lineNo = 0;
    $batch = 0;
    $pdo->beginTransaction();
    try {
        while (($line = fgets($fh)) !== false) {
            $lineNo++;
            $line = trim($line);
            if ($line === '' || ($lineNo === 1 && preg_match('/^id\s*[\t,;]/i', $line))) {
                continue;
            }
            if (strpos($line, "\t") !== false) {
                $parts = explode("\t", $line, 2);
            } elseif (strpos($line, ';') !== false) {
                $parts = explode(';', $line, 2);
            } else {
                $parts = explode(',', $line, 2);
            }
            if (count($parts) < 2) {
                $skipped++;
                continue;
            }
            $id = (int)trim($parts[0]);
            $score = trim($parts[1]);
            if ($id < 1 || $score === '') {
                $skipped++;
                continue;
            }
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) {
                $missing++;
                continue;
            }
            $upd->execute([$score, $id]);
            $updated++;
            $batch++;
            if ($batch >= 500) {
                $pdo->commit();
                $pdo->beginTransaction();
                $batch = 0;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fclose($fh);
        throw $e;
    }
    fclose($fh);

    return [
        'file' => 'audio_record_scores.tsv',
        'updated' => $updated,
        'skipped' => $skipped,
        'missing' => $missing,
    ];
};
