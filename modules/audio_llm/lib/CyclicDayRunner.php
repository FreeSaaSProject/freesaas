<?php
declare(strict_types=1);

/**
 * Auto-spawn cyclic LLM studies from staff day reports (oper mr_process_cyclic_llm_studies).
 */
final class CyclicDayRunner
{
    /**
     * EventBus: staff_reports.day.after_publish
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onAfterPublish(array $payload): array
    {
        $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
        if (empty($config['llm']['enabled'])) {
            return $payload;
        }
        if (!StudiesRepository::tablesReady() || !class_exists('CommentsRepository', false)) {
            return $payload;
        }
        $employeeId = (int)($payload['employee_id'] ?? 0);
        $ticketId = (int)($payload['ticket_id'] ?? 0);
        $reportCommentId = (int)($payload['comment_id'] ?? 0);
        $dayData = is_array($payload['day_data'] ?? null) ? $payload['day_data'] : [];
        $name = (string)($payload['name'] ?? ('#' . $employeeId));
        $stats = self::process($employeeId, $dayData, $ticketId, $reportCommentId, $config, $name);
        $payload['cyclic_llm'] = $stats;
        return $payload;
    }

    /**
     * @param array<string,mixed> $dayData
     * @param array<string,mixed> $config
     * @return array{processed:int,skipped:int,contracts:int}
     */
    public static function process(
        int $employeeId,
        array $dayData,
        int $ticketId,
        int $reportCommentId,
        array $config,
        string $name
    ): array {
        $stats = ['processed' => 0, 'skipped' => 0, 'contracts' => 0];
        if ($employeeId < 1 || $ticketId < 1) {
            return $stats;
        }
        $templates = StudiesRepository::listEnabledCyclic();
        if ($templates === []) {
            return $stats;
        }
        $byGood = [];
        foreach ($templates as $tpl) {
            $gid = (int)$tpl['TRIGGER_GOOD_ID'];
            if ($gid > 0) {
                $byGood[$gid][] = $tpl;
            }
        }
        if ($byGood === []) {
            return $stats;
        }

        $buffer = max(0, (int)($config['analysis']['procedure_time_buffer'] ?? 1600));
        $procedures = is_array($dayData['procedures'] ?? null) ? $dayData['procedures'] : [];
        $createdBy = class_exists('TicketWriter', false) ? TicketWriter::robotId() : 0;
        if ($createdBy < 1) {
            $createdBy = $employeeId;
        }
        $workingDays = max(0, (int)($config['contract_working_days'] ?? 5));

        foreach ($procedures as $proc) {
            if (!is_array($proc)) {
                continue;
            }
            $goodId = (int)($proc['id_good'] ?? 0);
            $procedureId = (int)($proc['id'] ?? 0);
            $dtStart = (int)($proc['dt_start'] ?? 0);
            $dtEnd = (int)($proc['dt_end'] ?? 0);
            if ($goodId < 1 || $procedureId < 1 || $dtStart < 1 || $dtEnd <= $dtStart) {
                continue;
            }
            if (!isset($byGood[$goodId])) {
                continue;
            }
            foreach ($byGood[$goodId] as $tpl) {
                $parentId = (int)$tpl['ID'];
                $amountPos = (int)$tpl['AMOUNT_POSITIVE'];
                $amountNeg = (int)$tpl['AMOUNT_NEGATIVE'];
                $prompt = (string)$tpl['PROMPT'];
                $title = (string)$tpl['TITLE'];
                $child = StudiesRepository::findChild($parentId, $procedureId);
                $studyId = $child ? (int)$child['ID'] : 0;
                $windowRecordIds = StudiesRepository::findRecordIdsInWindow(
                    $employeeId,
                    $dtStart - $buffer,
                    $dtEnd + $buffer
                );

                if ($studyId <= 0 && $windowRecordIds === []) {
                    $cid = self::upsertContract(
                        $ticketId,
                        $employeeId,
                        $reportCommentId,
                        $parentId,
                        $procedureId,
                        $title,
                        false,
                        $amountNeg,
                        'нет аудиозаписей',
                        $workingDays
                    );
                    if ($cid > 0) {
                        self::upsertManagerLinks($ticketId, $createdBy, $cid, $parentId, $procedureId, 0, []);
                        $stats['contracts']++;
                    }
                    $stats['processed']++;
                    continue;
                }

                $recordIds = $windowRecordIds;
                if ($studyId <= 0) {
                    $createRes = StudiesRepository::create(
                        $title . ' / проц №' . $procedureId,
                        'record_ids',
                        ['record_ids_raw' => implode(',', $recordIds)],
                        $prompt,
                        $createdBy,
                        ['id_cyclic_parent' => $parentId, 'id_procedure' => $procedureId]
                    );
                    if (empty($createRes['ok'])) {
                        $stats['skipped']++;
                        continue;
                    }
                    $studyId = (int)$createRes['id'];
                } else {
                    $recordIds = StudiesRepository::recordIdsForStudy($studyId);
                    if ($recordIds === []) {
                        $recordIds = $windowRecordIds;
                    }
                }

                $study = StudiesRepository::find($studyId);
                if (!$study) {
                    $stats['skipped']++;
                    continue;
                }
                if (StudyRunner::isCompleted($study) && !CyclicHelpers::studyHasParseableFound($studyId)) {
                    StudyRunner::requeueBlankResults($studyId);
                    $study = StudiesRepository::find($studyId);
                }
                if ($study && !StudyRunner::isCompleted($study)) {
                    StudyRunner::processUntilDone($studyId, 200);
                    $study = StudiesRepository::find($studyId);
                }
                if (!$study || !StudyRunner::isCompleted($study)) {
                    $stats['skipped']++;
                    continue;
                }
                if ($recordIds === []) {
                    $recordIds = StudiesRepository::recordIdsForStudy($studyId);
                }
                $verdict = CyclicHelpers::evalAmount($studyId, $amountPos, $amountNeg);
                $cid = self::upsertContract(
                    $ticketId,
                    $employeeId,
                    $reportCommentId,
                    $parentId,
                    $procedureId,
                    $title,
                    !empty($verdict['found']),
                    (int)$verdict['amount'],
                    (string)$verdict['evidence'],
                    $workingDays
                );
                if ($cid > 0) {
                    self::upsertManagerLinks(
                        $ticketId,
                        $createdBy,
                        $cid,
                        $parentId,
                        $procedureId,
                        $studyId,
                        $recordIds
                    );
                    $stats['contracts']++;
                }
                EventBus::emit('audio_llm.cyclic.spawn_for_procedure', [
                    'parent_id' => $parentId,
                    'procedure_id' => $procedureId,
                    'study_id' => $studyId,
                    'employee_id' => $employeeId,
                    'found' => !empty($verdict['found']),
                    'amount' => (int)$verdict['amount'],
                ]);
                $stats['processed']++;
            }
        }
        if (class_exists('StaffReportRunner', false) && method_exists('StaffReportRunner', 'log')) {
            // optional log via runner if public — skip if not
        }
        unset($name);
        return $stats;
    }

    private static function upsertContract(
        int $ticketId,
        int $employeeId,
        int $reportCommentId,
        int $parentId,
        int $procedureId,
        string $title,
        bool $foundOk,
        int $amount,
        string $evidence,
        int $workingDays
    ): int {
        $marker = CyclicHelpers::contractMarker($parentId, $procedureId);
        $markerLegacy = CyclicHelpers::contractMarkerLegacy($parentId, $procedureId);
        $contractText = trim($title);
        if ($contractText === '') {
            $contractText = 'Проверка процедуры';
        }
        $contractText .= '. проц №' . $procedureId . '. Результат: ' . ($foundOk ? 'ДА' : 'НЕТ') . '.';
        if ($amount !== 0) {
            $contractText .= ' Сумма: ' . $amount . ' р.';
        }
        if ($evidence !== '') {
            $contractText .= ' «' . $evidence . '»';
        }
        $contractText .= "\n" . '<!-- ' . $marker . ' -->';

        $deadline = self::addWorkingDays($workingDays);
        $acceptorId = self::ticketCurator($ticketId);
        $extra = [
            'PRICE_OFFER' => $amount,
            'DT_DEADLINE' => $deadline,
            'PID' => $reportCommentId > 0 ? $reportCommentId : 0,
            'DT_OFFER_ACCEPT' => time(),
            'COLOR' => ($amount < 0) ? '2' : '1',
        ];
        if ($acceptorId > 0) {
            $extra['ID_ACCEPTOR'] = $acceptorId;
        }

        $existingId = self::findCommentByMarkers($ticketId, [$marker, $markerLegacy]);
        if ($existingId > 0) {
            CommentsRepository::updateFields($existingId, $contractText, $extra);
            return $existingId;
        }
        return CommentsRepository::add($ticketId, $employeeId, $contractText, $extra);
    }

    /**
     * @param list<int> $recordIds
     */
    private static function upsertManagerLinks(
        int $ticketId,
        int $authorId,
        int $contractCommentId,
        int $parentId,
        int $procedureId,
        int $studyId,
        array $recordIds
    ): int {
        if ($ticketId < 1 || $authorId < 1 || $contractCommentId < 1) {
            return 0;
        }
        $marker = CyclicHelpers::linksMarker($parentId, $procedureId);
        $safeIds = [];
        foreach ($recordIds as $rid) {
            $rid = (int)$rid;
            if ($rid > 0) {
                $safeIds[$rid] = $rid;
            }
        }
        $idsCsv = implode(',', $safeIds);
        $procLink = $procedureId > 0
            ? '<a href="/procedures/' . $procedureId . '/edit" target="_blank">процедура №' . $procedureId . '</a>'
            : 'процедура не указана';
        $recordsLink = $idsCsv !== ''
            ? '<a href="/audio?ids=' . rawurlencode($idsCsv) . '" target="_blank">записи (' . count($safeIds) . ')</a>'
            : 'записи не найдены';
        $studyLink = $studyId > 0
            ? '<a href="/audio/llm-studies/' . $studyId . '" target="_blank">исследование №' . $studyId . '</a>'
            : 'исследование не проводилось';
        $text = $marker . "\n"
            . 'Для руководителя: ' . $procLink . ', ' . $recordsLink . ', ' . $studyLink . '. '
            . 'Цикл №' . $parentId . '.';

        $existingId = self::findCommentByMarkers($ticketId, [$marker]);
        $extra = ['DISABLED' => 1, 'PID' => $contractCommentId];
        if ($existingId > 0) {
            CommentsRepository::updateFields($existingId, $text, $extra);
            return $existingId;
        }
        return CommentsRepository::add($ticketId, $authorId, $text, $extra);
    }

    /** @param list<string> $markers */
    private static function findCommentByMarkers(int $ticketId, array $markers): int
    {
        foreach ($markers as $m) {
            $m = trim($m);
            if ($m === '') {
                continue;
            }
            if (class_exists('TicketWriter', false)) {
                $id = TicketWriter::findCommentId($ticketId, $m);
                if ($id > 0) {
                    return $id;
                }
            }
            $st = Database::pdo()->prepare(
                'SELECT ID FROM base_comments WHERE ID_OBJECT = ? AND TEXT LIKE ? ORDER BY ID ASC LIMIT 1'
            );
            $st->execute([$ticketId, '%' . $m . '%']);
            $id = (int)$st->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    }

    private static function ticketCurator(int $ticketId): int
    {
        if ($ticketId < 1 || !Database::tableExists('base_news')) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT ID_CURATOR FROM base_news WHERE ID = ? LIMIT 1');
        $st->execute([$ticketId]);
        return (int)$st->fetchColumn();
    }

    private static function addWorkingDays(int $days): int
    {
        $ts = time();
        $left = max(0, $days);
        while ($left > 0) {
            $ts = strtotime('+1 day', $ts) ?: ($ts + 86400);
            $w = (int)date('N', $ts);
            if ($w < 6) {
                $left--;
            }
        }
        return mktime(23, 59, 59, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts)) ?: $ts;
    }
}
