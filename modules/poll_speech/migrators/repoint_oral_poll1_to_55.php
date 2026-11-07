<?php
declare(strict_types=1);

/**
 * Repoint oral calendar marks from legacy poll #1 to oral 10-point poll #55.
 * Only invites with ID_PROC>0 and empty CODE or [ORAL_ASKED] log (SMS/full fills on #1 stay).
 * Idempotent.
 *
 * @return array{ok:bool,message:string,updated:int,target_poll:int,source_poll:int}
 */
return static function (PDO $pdo, array $spec): array {
    $source = 1;
    $target = 55;
    if (!Database::tableExists('base_poll_invites') || !Database::tableExists('base_polls')) {
        return [
            'ok' => false,
            'message' => 'tables missing',
            'updated' => 0,
            'target_poll' => $target,
            'source_poll' => $source,
        ];
    }
    $st = $pdo->prepare('SELECT ID FROM base_polls WHERE ID = ? AND PID = 0 LIMIT 1');
    $st->execute([$target]);
    if ((int)$st->fetchColumn() < 1) {
        return [
            'ok' => false,
            'message' => 'target poll #' . $target . ' missing — run oral_poll_10 first',
            'updated' => 0,
            'target_poll' => $target,
            'source_poll' => $source,
        ];
    }

    $note = '[' . date('d.m.y H:i') . '][0]: [POLL_SPEECH] ID_POLL ' . $source . ' → ' . $target . "\n";
    $sql = "UPDATE base_poll_invites
            SET ID_POLL = ?,
                LOG = CONCAT(IFNULL(LOG, ''), ?)
            WHERE ID_POLL = ?
              AND ID_PROC > 0
              AND (CODE = '' OR LOG LIKE '%[ORAL_ASKED]%')";
    $st = $pdo->prepare($sql);
    $st->execute([$target, $note, $source]);
    $n = $st->rowCount();

    if (class_exists('PollSpeechConfig', false) && $n >= 0) {
        Config::set('poll_speech', 'oral_poll_id', (string)$target);
        Config::set('poll_invites', 'oral_poll_id', (string)$target);
    }

    return [
        'ok' => true,
        'message' => 'repointed oral invites',
        'updated' => $n,
        'target_poll' => $target,
        'source_poll' => $source,
    ];
};
