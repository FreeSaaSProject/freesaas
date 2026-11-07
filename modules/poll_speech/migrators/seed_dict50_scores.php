<?php
declare(strict_types=1);

/**
 * Ensure guest score phrase keys exist in dictionary 50 («Отзывы 10»)
 * and write key_score_map into poll_speech.ini.
 *
 * Phrases taken from cabin ASR around oral asks (digits + word forms + «отлично»).
 * Idempotent by exact key NAME within dict 50.
 *
 * @return array{ok:bool,message:string,dict_id:int,created:int,map:array<int,int>}
 */
return static function (PDO $pdo, array $spec): array {
    $dictId = 50;
    if (!Database::tableExists('base_records_keys')
        || !Database::tableExists('base_records_dictonarys')
    ) {
        return ['ok' => false, 'message' => 'audio_dicts tables missing', 'dict_id' => 0, 'created' => 0, 'map' => []];
    }
    $st = $pdo->prepare('SELECT ID FROM base_records_dictonarys WHERE ID = ? LIMIT 1');
    $st->execute([$dictId]);
    if ((int)$st->fetchColumn() < 1) {
        return ['ok' => false, 'message' => 'dict 50 missing', 'dict_id' => 0, 'created' => 0, 'map' => []];
    }

    // Distinctive phrases (avoid bare "10 минут" collisions where possible).
    $phrases = [
        // score => list of spoken variants
        10 => [
            'ставлю десять',
            'оценка десять',
            'на десять баллов',
            'десять баллов',
            'десяточка',
            'твёрдая десятка',
            'отлично',
            'прям отлично',
            'вообще отлично',
        ],
        9 => [
            'ставлю девять',
            'оценка девять',
            'на девять',
            'девять баллов',
            'девяточка',
        ],
        8 => [
            'ставлю восемь',
            'оценка восемь',
            'на восемь',
            'восемь баллов',
            'хорошо',
        ],
        7 => [
            'ставлю семь',
            'оценка семь',
            'на семь',
            'семь баллов',
            'нормально',
        ],
        6 => [
            'ставлю шесть',
            'оценка шесть',
            'на шесть',
            'шесть баллов',
        ],
        5 => [
            'ставлю пять',
            'оценка пять',
            'на пять баллов',
            'пять баллов',
        ],
        4 => [
            'ставлю четыре',
            'оценка четыре',
            'на четыре',
            'четыре балла',
        ],
        3 => [
            'ставлю три',
            'оценка три',
            'на три',
            'три балла',
        ],
        2 => [
            'ставлю два',
            'оценка два',
            'на два',
            'два балла',
            'плохо',
        ],
        1 => [
            'ставлю один',
            'оценка один',
            'на один',
            'один балл',
            'ужасно',
        ],
    ];

    $find = $pdo->prepare(
        'SELECT ID FROM base_records_keys WHERE ID_DICTONARY = ? AND NAME = ? LIMIT 1'
    );
    $ins = $pdo->prepare(
        'INSERT INTO base_records_keys (DT_CREATE, NAME, DISABLED, ID_DICTONARY, ID_LOOKLIKE)
         VALUES (?,?,0,?,0)'
    );
    $created = 0;
    $map = [];

    foreach ($phrases as $score => $names) {
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $find->execute([$dictId, $name]);
            $id = (int)$find->fetchColumn();
            if ($id < 1) {
                $ins->execute([time(), mb_substr($name, 0, 255), $dictId]);
                $id = (int)$pdo->lastInsertId();
                $created++;
            }
            if ($id > 0) {
                $map[$id] = (int)$score;
            }
        }
    }

    if (class_exists('PollSpeechConfig', false) && $map !== []) {
        Config::set('poll_speech', 'dict_id', (string)$dictId);
        Config::set(
            'poll_speech',
            'key_score_map',
            (string)json_encode($map, JSON_UNESCAPED_UNICODE)
        );
    }

    return [
        'ok' => true,
        'message' => 'dict 50 score keys ready',
        'dict_id' => $dictId,
        'created' => $created,
        'map' => $map,
    ];
};
