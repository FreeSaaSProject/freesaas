<?php
declare(strict_types=1);

/**
 * Shared HTTP job runner helpers (batch size / time budget from audio.ini).
 */
final class JobRunner
{
    public static function batchSize(): int
    {
        $n = (int)Config::get('audio', 'job_batch_size', 40);
        return max(1, min(500, $n > 0 ? $n : 40));
    }

    public static function timeBudgetSec(): int
    {
        $n = (int)Config::get('audio', 'job_time_budget_sec', 45);
        return max(5, min(300, $n > 0 ? $n : 45));
    }

    public static function applyTimeLimit(?int $budgetSec = null): void
    {
        $budget = $budgetSec !== null ? max(5, min(600, $budgetSec)) : self::timeBudgetSec();
        @set_time_limit($budget + 30);
        @ignore_user_abort(true);
    }

    public static function deadline(?int $budgetSec = null): float
    {
        $budget = $budgetSec !== null ? max(5, min(600, $budgetSec)) : self::timeBudgetSec();
        return microtime(true) + $budget;
    }

    public static function timedOut(float $deadline): bool
    {
        return microtime(true) >= $deadline;
    }

    /**
     * @param array{ok?:bool,processed?:int,skipped?:int,message?:string,extra?:array} $result
     */
    public static function jsonResponse(array $result, int $http = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($http);
        $out = [
            'ok' => !empty($result['ok']),
            'processed' => (int)($result['processed'] ?? 0),
            'skipped' => (int)($result['skipped'] ?? 0),
            'message' => (string)($result['message'] ?? ''),
            'ms' => (int)round((microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000),
        ];
        if (isset($result['extra']) && is_array($result['extra'])) {
            $out['extra'] = $result['extra'];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    public static function blocked(): void
    {
        self::jsonResponse([
            'ok' => false,
            'message' => 'blocked_run',
        ], 409);
    }
}
