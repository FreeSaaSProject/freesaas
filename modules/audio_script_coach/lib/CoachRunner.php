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
    public static function start(int $adminId, string $day, int $createdBy): array
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
            $id = CoachRunsRepository::create($adminId, $day, $createdBy);
            $out['ok'] = true;
            $out['run_id'] = $id;
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    /**
     * Advance one phase (or all remaining if $untilDone).
     *
     * @return array{ok:bool,run_id:int,phase:string,status:int,done:bool,error:string,proposals:int}
     */
    public static function processNext(int $runId, bool $untilDone = false): array
    {
        $out = [
            'ok' => false,
            'run_id' => $runId,
            'phase' => '',
            'status' => 0,
            'done' => false,
            'error' => '',
            'proposals' => 0,
        ];
        $run = CoachRunsRepository::find($runId);
        if ($run === null) {
            $out['error'] = t('audio_script_coach.err.run_not_found', ['id' => (string)$runId]);

            return $out;
        }
        if ((int)$run['status'] === CoachRunsRepository::STATUS_READY) {
            $out['ok'] = true;
            $out['done'] = true;
            $out['phase'] = (string)$run['phase'];
            $out['status'] = (int)$run['status'];
            $out['proposals'] = count(CoachRunsRepository::proposalsForRun($runId));

            return $out;
        }
        if ((int)$run['status'] === CoachRunsRepository::STATUS_ERROR) {
            $out['error'] = (string)($run['error_text'] ?? 'error');
            $out['status'] = CoachRunsRepository::STATUS_ERROR;

            return $out;
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
                $out['ok'] = true;
                $out['done'] = true;
                $out['phase'] = 'done';
                $out['status'] = CoachRunsRepository::STATUS_READY;
                $out['proposals'] = count(CoachRunsRepository::proposalsForRun($runId));

                return $out;
            }

            CoachRunsRepository::setPhase($runId, $next, CoachRunsRepository::STATUS_RUNNING);
            try {
                self::runPhase($runId, $next, $adminId, $day);
                $donePhases[$next] = true;
                $out['phase'] = $next;
            } catch (Throwable $e) {
                CoachRunsRepository::setError($runId, $e->getMessage());
                $out['error'] = $e->getMessage();
                $out['status'] = CoachRunsRepository::STATUS_ERROR;
                $out['phase'] = $next;

                return $out;
            }

            if ($next === 'llm') {
                CoachRunsRepository::setPhase($runId, 'done', CoachRunsRepository::STATUS_READY);
                $out['ok'] = true;
                $out['done'] = true;
                $out['phase'] = 'done';
                $out['status'] = CoachRunsRepository::STATUS_READY;
                $out['proposals'] = count(CoachRunsRepository::proposalsForRun($runId));

                return $out;
            }
        }

        $run = CoachRunsRepository::find($runId);
        $out['ok'] = true;
        $out['phase'] = (string)($run['phase'] ?? $out['phase']);
        $out['status'] = (int)($run['status'] ?? CoachRunsRepository::STATUS_RUNNING);
        $out['done'] = (int)$out['status'] === CoachRunsRepository::STATUS_READY;

        return $out;
    }

    private static function runPhase(int $runId, string $phase, int $adminId, string $day): void
    {
        if ($phase === 'catalog') {
            $payload = CoachCatalog::build($adminId);
            CoachRunsRepository::saveArtifact($runId, 'catalog', $payload);

            return;
        }
        if ($phase === 'facts') {
            $catalog = CoachRunsRepository::artifact($runId, 'catalog');
            if ($catalog === null) {
                throw new RuntimeException('catalog missing');
            }
            $payload = CoachFacts::build($adminId, $day, $catalog);
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
            $payload = CoachLlm::build($catalog, $facts, $windows);
            CoachRunsRepository::saveArtifact($runId, 'llm', [
                'errors' => $payload['errors'],
                'raw_count' => count($payload['raw']),
                'proposal_count' => count($payload['proposals']),
            ]);
            CoachRunsRepository::clearProposals($runId);
            foreach ($payload['proposals'] as $p) {
                CoachRunsRepository::insertProposal($runId, $p);
            }

            return;
        }
        throw new InvalidArgumentException('unknown phase: ' . $phase);
    }
}
