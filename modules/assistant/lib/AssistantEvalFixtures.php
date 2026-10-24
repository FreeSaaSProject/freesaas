<?php
declare(strict_types=1);

/**
 * Synthetic tool payloads for assistant eval (JSON under modules/assistant/eval/fixtures/).
 * Dates use {{tomorrow}} / {{today}} / {{yesterday}}; rewritten at load time.
 */
final class AssistantEvalFixtures
{
    /**
     * Build harness tool_stubs map for a fixture pack id (filename without .json).
     *
     * @return array<string,callable>|null null if missing/invalid
     */
    public static function toolStubs(string $packId): ?array
    {
        $pack = self::load($packId);
        if ($pack === null) {
            return null;
        }
        $day = is_array($pack['day'] ?? null) ? $pack['day'] : [];
        $ops = is_array($pack['ops'] ?? null) ? $pack['ops'] : [];
        if ($ops === []) {
            return null;
        }

        $stubs = [];
        foreach ($ops as $opId => $spec) {
            $opId = trim((string)$opId);
            if ($opId === '' || !is_array($spec)) {
                continue;
            }
            $stubs[$opId] = self::makeStub($opId, $spec, $day);
        }
        return $stubs !== [] ? $stubs : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function load(string $packId): ?array
    {
        $packId = preg_replace('/[^a-zA-Z0-9_-]/', '', $packId) ?? '';
        if ($packId === '') {
            return null;
        }
        $path = base_path('modules/assistant/eval/fixtures/' . $packId . '.json');
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        return self::expandDates($data);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function expandDates(array $data): array
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day') ?: time());
        $tomorrow = date('Y-m-d', strtotime('+1 day') ?: time());
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return $data;
        }
        $json = str_replace(
            ['{{today}}', '{{yesterday}}', '{{tomorrow}}'],
            [$today, $yesterday, $tomorrow],
            $json
        );
        $out = json_decode($json, true);
        return is_array($out) ? $out : $data;
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<string,mixed> $day
     */
    private static function makeStub(string $opId, array $spec, array $day): callable
    {
        $from = (string)($spec['from'] ?? '');
        if (!empty($spec['stub_pending'])) {
            return static function (string $calledOp, array $args) use ($opId): array {
                $summary = $calledOp . ' ' . json_encode($args, JSON_UNESCAPED_UNICODE);
                return [
                    'ok' => true,
                    'pending' => true,
                    'token' => 'eval-stub-' . substr(sha1($opId . $summary), 0, 12),
                    'message' => 'Write queued (eval fixture). UI shows Confirm/Cancel.',
                    'summary' => $summary,
                    '_eval_fixture' => true,
                ];
            };
        }
        if ($from === 'day') {
            return static function (string $calledOp, array $args) use ($day): array {
                $payload = $day;
                $date = trim((string)($args['date'] ?? ''));
                if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $payload['date'] = $date;
                    if (isset($payload['shifts']) && is_array($payload['shifts'])) {
                        foreach ($payload['shifts'] as $i => $row) {
                            if (is_array($row)) {
                                $payload['shifts'][$i]['date'] = $date;
                            }
                        }
                    }
                }
                $payload['_eval_fixture'] = true;
                return $payload;
            };
        }
        if ($from === 'day_as_list') {
            return static function (string $calledOp, array $args) use ($day): array {
                $shifts = is_array($day['shifts'] ?? null) ? $day['shifts'] : [];
                $q = trim((string)($args['q'] ?? ''));
                if ($q !== '') {
                    $ql = function_exists('mb_strtolower') ? mb_strtolower($q) : strtolower($q);
                    $shifts = array_values(array_filter($shifts, static function ($row) use ($ql): bool {
                        if (!is_array($row)) {
                            return false;
                        }
                        $name = (string)($row['operator_name'] ?? '');
                        $nl = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
                        return $nl !== '' && strpos($nl, $ql) !== false;
                    }));
                }
                return [
                    'ok' => true,
                    'items' => $shifts,
                    'total' => count($shifts),
                    '_eval_fixture' => true,
                ];
            };
        }
        if ($from === 'day_get') {
            return static function (string $calledOp, array $args) use ($day): array {
                $id = (int)($args['id'] ?? 0);
                $shifts = is_array($day['shifts'] ?? null) ? $day['shifts'] : [];
                foreach ($shifts as $row) {
                    if (is_array($row) && (int)($row['id'] ?? 0) === $id) {
                        return [
                            'ok' => true,
                            'item' => $row,
                            '_eval_fixture' => true,
                        ];
                    }
                }
                return ['ok' => false, 'error' => 'Not found (eval fixture)', '_eval_fixture' => true];
            };
        }
        // Static payload.
        $static = $spec;
        unset($static['from'], $static['stub_pending']);
        if (!isset($static['ok'])) {
            $static['ok'] = true;
        }
        $static['_eval_fixture'] = true;
        return static function (string $calledOp, array $args) use ($static): array {
            return $static;
        };
    }
}
