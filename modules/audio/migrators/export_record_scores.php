<?php
declare(strict_types=1);

/**
 * Export base_records.ID → SCORE for rows with non-zero score.
 * Writes var/audio_record_scores.tsv (tab-separated, header id/score).
 *
 * Local (good SCORE copy):
 *   php bin/data-migrate.php --module=audio --id=export_record_scores
 * Copy the TSV to the host, then run import_record_scores there.
 *
 * @return array{file:string,rows:int,path:string}
 */
return static function (PDO $pdo, array $spec = []): array {
    if (!Database::tableExists('base_records')) {
        throw new RuntimeException('base_records missing');
    }
    $dir = base_path('var');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create var/');
    }
    $path = $dir . '/audio_record_scores.tsv';
    $fh = fopen($path, 'wb');
    if ($fh === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
    fwrite($fh, "id\tscore\n");
    $st = $pdo->query(
        'SELECT ID, SCORE FROM base_records
         WHERE SCORE IS NOT NULL AND CAST(SCORE AS CHAR) <> \'\' AND CAST(SCORE AS CHAR) <> \'0\'
         ORDER BY ID ASC'
    );
    $n = 0;
    if ($st) {
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)($row['ID'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $score = trim((string)($row['SCORE'] ?? ''));
            if ($score === '' || $score === '0') {
                continue;
            }
            // Keep score as stored (string/decimal) — no locale reformatting.
            fwrite($fh, $id . "\t" . $score . "\n");
            $n++;
        }
    }
    fclose($fh);

    return [
        'file' => 'audio_record_scores.tsv',
        'path' => $path,
        'rows' => $n,
    ];
};
