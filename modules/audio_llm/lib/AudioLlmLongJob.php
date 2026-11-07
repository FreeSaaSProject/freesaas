<?php
declare(strict_types=1);

/** LongJob handlers for LLM studies drain and review moderation. */
final class AudioLlmLongJob
{
    public const TYPE_STUDY = 'audio_llm.study_process';
    public const TYPE_MODERATION = 'audio_llm.review_moderation';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function studyOnStart(array $job, array $opts): array
    {
        $studyId = (int)($job['study_id'] ?? 0);
        $p = StudyRunner::progress($studyId);
        $job['total'] = $p ? (int)$p['total'] : 0;
        $job['done'] = $p ? (int)$p['done'] : 0;
        $job['progress'] = $p ? (int)$p['percent'] : 0;
        $job['progress_overall'] = $job['progress'];
        LongJob::appendLog($job, t('audio_llm.longjob.study_start', [
            'id' => (string)$studyId,
            'pending' => (string)($p['pending'] ?? 0),
        ]));
        if ($p && !empty($p['complete'])) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['result'] = ['study_id' => $studyId, 'complete' => true];
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function studyTick(array $job): array
    {
        $studyId = (int)($job['study_id'] ?? 0);
        $limit = max(1, min(10, (int)($job['batch'] ?? 3)));
        $res = StudyRunner::processNext($studyId, $limit);
        $p = StudyRunner::progress($studyId);
        $job['done'] = $p ? (int)$p['done'] : (int)($job['done'] ?? 0);
        $job['total'] = $p ? (int)$p['total'] : (int)($job['total'] ?? 0);
        $job['progress'] = $p ? (int)$p['percent'] : 0;
        $job['progress_overall'] = $job['progress'];
        LongJob::appendLog($job, t('audio_llm.longjob.study_tick', [
            'processed' => (string)(int)$res['processed'],
            'done' => (string)$job['done'],
            'total' => (string)$job['total'],
        ]));
        if ($p && (!empty($p['complete']) || (int)$p['pending'] <= 0)) {
            $sum = StudyRunner::maybeGenerateSummary($studyId);
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['result'] = [
                'study_id' => $studyId,
                'summary' => $sum,
            ];
            LongJob::appendLog($job, t('audio_llm.longjob.study_done'));
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function moderationOnStart(array $job, array $opts): array
    {
        $job['phase'] = 'build';
        $job['progress'] = 5;
        $job['progress_overall'] = 5;
        LongJob::appendLog($job, t('audio_llm.longjob.mod_build'));
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function moderationTick(array $job): array
    {
        $phase = (string)($job['phase'] ?? 'build');
        $adminId = (int)($job['admin_id'] ?? 0);
        $year = (int)($job['year'] ?? 0);
        $month = (int)($job['month'] ?? 0);
        $day = (int)($job['day'] ?? 0);

        if ($phase === 'build') {
            $built = ReviewModerationBuilder::build($adminId, $year, $month, $day);
            if (empty($built['ok'])) {
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = (string)($built['error'] ?? t('audio_llm.moderation.err.none'));
                $job['message'] = $job['error'];
                return $job;
            }
            $job['built'] = [
                'employee_name' => (string)$built['employee_name'],
                'date' => (string)$built['date'],
                'transcripts' => (string)$built['transcripts'],
                'pending_count' => (int)$built['pending_count'],
                'context_count' => (int)$built['context_count'],
            ];
            $job['phase'] = 'llm';
            $job['progress'] = 40;
            $job['progress_overall'] = 40;
            LongJob::appendLog($job, t('audio_llm.longjob.mod_llm', [
                'n' => (string)(int)$built['pending_count'],
            ]));
            return $job;
        }

        if ($phase === 'llm') {
            if (!ReviewModerationLlm::isReady()) {
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = t('audio_llm.moderation.err.llm_not_ready');
                $job['message'] = $job['error'];
                return $job;
            }
            $built = is_array($job['built'] ?? null) ? $job['built'] : [];
            $system = ReviewModerationConfig::systemPrompt();
            $user = ReviewModerationConfig::applyUserTemplate(
                ReviewModerationConfig::userPrompt(),
                [
                    'employee_name' => (string)($built['employee_name'] ?? ''),
                    'date' => (string)($built['date'] ?? ''),
                    'transcripts' => (string)($built['transcripts'] ?? ''),
                ]
            );
            $res = LlmClient::complete([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ], [
                'temperature' => 0.2,
                'max_tokens' => 4000,
                'timeout' => 180,
            ]);
            if (!$res['ok']) {
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = $res['error'] !== '' ? $res['error'] : t('audio_llm.err.llm');
                $job['message'] = $job['error'];
                return $job;
            }
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['result'] = [
                'text' => trim((string)$res['text']),
                'pending_count' => (int)($built['pending_count'] ?? 0),
                'context_count' => (int)($built['context_count'] ?? 0),
            ];
            unset($job['built']); // drop bulky transcripts from disk
            LongJob::appendLog($job, t('audio_llm.longjob.mod_done'));
            return $job;
        }

        $job['status'] = LongJob::STATUS_ERROR;
        $job['error'] = 'Unknown moderation phase';
        return $job;
    }
}
