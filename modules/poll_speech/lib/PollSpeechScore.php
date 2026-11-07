<?php
declare(strict_types=1);

/** Map poll DATA_OPTS answer ids ↔ numeric 1..10 scores for oral speech poll. */
final class PollSpeechScore
{
    /**
     * @param array<int|string,mixed> $dataOpts
     * @return array{staff:int,service:int}
     */
    public static function fromDataOpts(array $dataOpts): array
    {
        $qidStaff = PollSpeechConfig::qidStaff();
        $qidService = PollSpeechConfig::qidService();
        $staffMap = array_flip(PollSpeechConfig::answersStaff()); // answer_id => score
        $serviceMap = array_flip(PollSpeechConfig::answersService());
        $staff = 0;
        $service = 0;
        if ($qidStaff > 0) {
            $aid = (int)($dataOpts[(string)$qidStaff] ?? $dataOpts[$qidStaff] ?? 0);
            if ($aid > 0 && isset($staffMap[$aid])) {
                $staff = (int)$staffMap[$aid];
            }
        }
        if ($qidService > 0) {
            $aid = (int)($dataOpts[(string)$qidService] ?? $dataOpts[$qidService] ?? 0);
            if ($aid > 0 && isset($serviceMap[$aid])) {
                $service = (int)$serviceMap[$aid];
            }
        }
        return ['staff' => $staff, 'service' => $service];
    }

    /**
     * Prefer staff score for master KPI; fall back to service.
     *
     * @param array<int|string,mixed> $dataOpts
     */
    public static function primaryFromDataOpts(array $dataOpts): int
    {
        $s = self::fromDataOpts($dataOpts);
        if ($s['staff'] >= 1) {
            return $s['staff'];
        }
        return $s['service'] >= 1 ? $s['service'] : 0;
    }
}
