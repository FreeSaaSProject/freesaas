<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Orchestrates coach phases for one run. */
final class CoachRunner
{
    /** @return list<string> */
    public static function phases(): array
    {
        return ['catalog', 'facts', 'windows', 'llm'];
    }

    /**
     * @return array{ok:bool,run_id:int,error:string}
     */
    public static function start(int $adminId, string $day, int $createdBy, string $depth = 'normal'): array
    {
        $out = ['ok' => false, 'run_id' => 0, 'error' => ''];
        if ($adminId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $out['error'] = t('audio_script_coach.err.bad_args');

            return $out;
        }
        if (!CoachRunsRepository::tablesReady()) {
            $out['error'] = t('audio_script_coach.err.tables');

            return $out;
        }
        try {
            $id = CoachRunsRepository::create($adminId, $day, $createdBy, $depth);
            $out['ok'] = true;
            $out['run_id'] = $id;
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    /**
     * Clear artifacts/proposals so the client can tick phases one-by-one (no sync drain).
     *
     * @return array{ok:bool,run_id:int,phase:string,status:int,done:bool,error:string,proposals:int,phases_done:int,phases_total:int}
     */
    public static function rebuild(int $runId, ?string $depth = null): array
    {
        $run = CoachRunsRepository::find($runId);
        if ($run === null) {
            return self::tickMeta($runId, [
                'ok' => false,
                'error' => t('audio_script_coach.err.run_not_found', ['id' => (string)$runId]),
            ]);
        }
        if ($depth !== null) {
            CoachRunsRepository::setDepth($runId, $depth);
        }
        CoachRunsRepository::resetForRebuild($runId);
        $run = CoachRunsRepository::find($runId);

        return self::tickMeta($runId, [
            'ok' => true,
            'phase' => (string)($run['phase'] ?? 'catalog'),
            'status' => (int)($run['status'] ?? CoachRunsRepository::STATUS_PENDING),
            'done' => false,
            'error' => '',
            'proposals' => 0,
        ]);
    }

    /**
     * Enrich processNext / rebuild result with progress counters for UI ticks.
     *
     * @param array<string,mixed> $partial
     * @return array{ok:bool,run_id:int,phase:string,status:int,done:bool,error:string,proposals:int,phases_done:int,phases_total:int}
     */
    public static function tickMeta(int $runId, array $partial = []): array
    {
        $phases = self::phases();
        $doneN = 0;
        foreach (CoachRunsRepository::artifactPhases($runId) as $ap) {
            $ph = (string)($ap['phase'] ?? '');
            if (in_array($ph, $phases, true)) {
                $doneN++;
            }
        }
        $base = [
            'ok' => false,
            'run_id' => $runId,
            'phase' => '',
            'status' => 0,
            'done' => false,
            'error' => '',
            'proposals' => 0,
            'phases_done' => $doneN,
            'phases_total' => count($phases),
        ];

        return array_merge($base, $partial, [
            'run_id' => $runId,
            'phases_done' => $doneN,
            'phases_total' => count($phases),
        ]);
    }

    /**
     * Advance one phase (or all remaining if $untilDone — prefer UI ticks instead).
     *
     * @return array{ok:bool,run_id:int,phase:string,status:int,done:bool,error:string,proposals:int,phases_done:int,phases_total:int}
     */
    public static function processNext(int $runId, bool $untilDone = false): array
    {
        $out = self::tickMeta($runId, [
            'ok' => false,
            'phase' => '',
            'status' => 0,
            'done' => false,
            'error' => '',
            'proposals' => 0,
        ]);
        $run = CoachRunsRepository::find($runId);
        if ($run === null) {
            $out['error'] = t('audio_script_coach.err.run_not_found', ['id' => (string)$runId]);

            return $out;
        }
        if ((int)$run['status'] === CoachRunsRepository::STATUS_READY) {
            return self::tickMeta($runId, [
                'ok' => true,
                'done' => true,
                'phase' => (string)$run['phase'],
                'status' => (int)$run['status'],
                'proposals' => count(CoachRunsRepository::proposalsForRun($runId)),
            ]);
        }
        if ((int)$run['status'] === CoachRunsRepository::STATUS_ERROR) {
            return self::tickMeta($runId, [
                'ok' => false,
                'error' => (string)($run['error_text'] ?? 'error'),
                'status' => CoachRunsRepository::STATUS_ERROR,
                'phase' => (string)($run['phase'] ?? ''),
            ]);
        }

        $adminId = (int)$run['id_admin'];
        $day = (string)$run['day'];
        $donePhases = [];
        foreach (CoachRunsRepository::artifactPhases($runId) as $ap) {
            $donePhases[(string)$ap['phase']] = true;
        }

        $maxSteps = $untilDone ? 4 : 1;
        for ($step = 0; $step < $maxSteps; $step++) {
            $next = null;
            foreach (self::phases() as $ph) {
                if (!isset($donePhases[$ph])) {
                    $next = $ph;
                    break;
                }
            }
            if ($next === null) {
                CoachRunsRepository::setPhase($runId, 'done', CoachRunsRepository::STATUS_READY);

                return self::tickMeta($runId, [
                    'ok' => true,
                    'done' => true,
                    'phase' => 'done',
                    'status' => CoachRunsRepository::STATUS_READY,
                    'proposals' => count(CoachRunsRepository::proposalsForRun($runId)),
                ]);
            }

            CoachRunsRepository::setPhase($runId, $next, CoachRunsRepository::STATUS_RUNNING);
            try {
                self::runPhase($runId, $next, $adminId, $day);
                $donePhases[$next] = true;
                $out['phase'] = $next;
            } catch (Throwable $e) {
                CoachRunsRepository::setError($runId, $e->getMessage());

                return self::tickMeta($runId, [
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'status' => CoachRunsRepository::STATUS_ERROR,
                    'phase' => $next,
                ]);
            }

            if ($next === 'llm') {
                CoachRunsRepository::setPhase($runId, 'done', CoachRunsRepository::STATUS_READY);

                return self::tickMeta($runId, [
                    'ok' => true,
                    'done' => true,
                    'phase' => 'done',
                    'status' => CoachRunsRepository::STATUS_READY,
                    'proposals' => count(CoachRunsRepository::proposalsForRun($runId)),
                ]);
            }

            if (!$untilDone) {
                break;
            }
        }

        $run = CoachRunsRepository::find($runId);

        return self::tickMeta($runId, [
            'ok' => true,
            'phase' => (string)($run['phase'] ?? $out['phase']),
            'status' => (int)($run['status'] ?? CoachRunsRepository::STATUS_RUNNING),
            'done' => (int)($run['status'] ?? 0) === CoachRunsRepository::STATUS_READY,
            'proposals' => count(CoachRunsRepository::proposalsForRun($runId)),
        ]);
    }

    private static function runPhase(int $runId, string $phase, int $adminId, string $day): void
    {
        if ($phase === 'catalog') {
            $payload = CoachCatalog::build($adminId);
            self::noteArtifactSize('catalog', $payload);
            CoachRunsRepository::saveArtifact($runId, 'catalog', $payload);

            return;
        }
        if ($phase === 'facts') {
            $catalog = CoachRunsRepository::artifact($runId, 'catalog');
            if ($catalog === null) {
                throw new RuntimeException('catalog missing');
            }
            $run = CoachRunsRepository::find($runId);
            $depth = is_array($run) ? (string)($run['depth'] ?? 'normal') : 'normal';
            $payload = CoachFacts::build($adminId, $day, $catalog, $depth);
            self::noteArtifactSize('facts', $payload);
            CoachRunsRepository::saveArtifact($runId, 'facts', $payload);

            return;
        }
        if ($phase === 'windows') {
            $catalog = CoachRunsRepository::artifact($runId, 'catalog');
            $facts = CoachRunsRepository::artifact($runId, 'facts');
            if ($catalog === null || $facts === null) {
                throw new RuntimeException('catalog/facts missing');
            }
            $payload = CoachWindows::build($catalog, $facts);
            self::noteArtifactSize('windows', $payload);
            CoachRunsRepository::saveArtifact($runId, 'windows', $payload);

            return;
        }
        if ($phase === 'llm') {
            $catalog = CoachRunsRepository::artifact($runId, 'catalog');
            $facts = CoachRunsRepository::artifact($runId, 'facts');
            $windows = CoachRunsRepository::artifact($runId, 'windows');
            if ($catalog === null || $facts === null || $windows === null) {
                throw new RuntimeException('prior artifacts missing');
            }
            if (class_exists('CoachProgress', false) && CoachProgress::enabled()) {
                CoachProgress::note(t('audio_script_coach.progress.llm_load', [
                    'catalog_kb' => self::jsonKb($catalog),
                    'facts_kb' => self::jsonKb($facts),
                    'windows_kb' => self::jsonKb($windows),
                    'windows_n' => (string)count(is_array($windows['windows'] ?? null) ? $windows['windows'] : []),
                ]), '#93c5fd');
            }
            $payload = CoachLlm::build($catalog, $facts, $windows);
            CoachRunsRepository::saveArtifact($runId, 'llm', [
                'errors' => $payload['errors'],
                'raw_count' => count($payload['raw']),
                'proposal_count' => count($payload['proposals']),
                'filter' => is_array($payload['filter'] ?? null) ? $payload['filter'] : [],
            ]);
            CoachRunsRepository::clearProposals($runId);
            foreach ($payload['proposals'] as $p) {
                CoachRunsRepository::insertProposal($runId, $p);
            }

            return;
        }
        throw new InvalidArgumentException('unknown phase: ' . $phase);
    }

    /** @param array<string,mixed> $payload */
    private static function noteArtifactSize(string $phase, array $payload): void
    {
        if (!class_exists('CoachProgress', false) || !CoachProgress::enabled()) {
            return;
        }
        CoachProgress::note(t('audio_script_coach.progress.artifact_size', [
            'phase' => $phase,
            'kb' => self::jsonKb($payload),
        ]));
    }

    /** @param array<string,mixed> $payload */
    private static function jsonKb(array $payload): string
    {
        $bytes = strlen((string)json_encode($payload, JSON_UNESCAPED_UNICODE));

        return (string)round($bytes / 1024, 1);
    }
}
