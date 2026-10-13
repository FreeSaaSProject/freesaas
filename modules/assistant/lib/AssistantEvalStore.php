<?php
declare(strict_types=1);

/**
 * Last assistant eval run per LLM profile — JSON under var/assistant/eval/ (not DB).
 */
final class AssistantEvalStore
{
    private const VERSION = 1;

    public static function defaultProfileId(): string
    {
        if (class_exists('LlmConfig', false)) {
            $resolved = LlmConfig::resolve(null);
            if (is_array($resolved) && trim((string)($resolved['id'] ?? '')) !== '') {
                return trim((string)$resolved['id']);
            }
            $data = LlmConfig::load();
            $def = trim((string)($data['default_profile'] ?? ''));
            if ($def !== '') {
                return $def;
            }
        }
        return 'main';
    }

    /** @return array<string,mixed>|null */
    public static function loadLatest(): ?array
    {
        $ptr = self::readJson(self::latestPointerPath());
        $profile = is_array($ptr) ? trim((string)($ptr['profile'] ?? '')) : '';
        if ($profile === '') {
            $profile = self::defaultProfileId();
        }
        $run = self::load($profile);
        if ($run !== null) {
            return self::pruneUnknownCases($run);
        }
        // Fall back to any newest profile file if default has no run yet.
        $newest = self::loadNewest();
        return $newest !== null ? self::pruneUnknownCases($newest) : null;
    }

    /**
     * Drop rows whose case id is no longer in the suite (e.g. removed inject case).
     *
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    public static function pruneUnknownCases(array $run): array
    {
        if (!class_exists('AssistantEvalCases', false)) {
            return $run;
        }
        $known = [];
        foreach (AssistantEvalCases::all() as $case) {
            if (!is_array($case)) {
                continue;
            }
            $id = trim((string)($case['id'] ?? ''));
            if ($id !== '') {
                $known[$id] = true;
            }
        }
        $rowsIn = is_array($run['rows'] ?? null) ? $run['rows'] : [];
        $rows = [];
        $dropped = false;
        foreach ($rowsIn as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '' || !isset($known[$id])) {
                $dropped = true;
                continue;
            }
            $rows[] = $row;
        }
        if (!$dropped) {
            return $run;
        }
        $run['rows'] = $rows;
        $saved = self::save($run);
        return is_array($saved['run'] ?? null) ? $saved['run'] : $run;
    }

    /** @return array<string,mixed>|null */
    public static function load(string $profile): ?array
    {
        $profile = self::safeProfile($profile);
        if ($profile === '') {
            return null;
        }
        $data = self::readJson(self::profilePath($profile));
        return self::normalize($data);
    }

    /**
     * Persist a run snapshot. Keys: profile, live, rows, summary, ms, system_prompt?, finished?.
     *
     * @param array<string,mixed> $run
     */
    public static function save(array $run): array
    {
        $profile = trim((string)($run['profile'] ?? ''));
        if ($profile === '') {
            $profile = self::defaultProfileId();
        }
        $safe = self::safeProfile($profile);
        if ($safe === '') {
            return ['ok' => false, 'error' => 'bad profile', 'run' => null];
        }

        $rowsIn = $run['rows'] ?? [];
        $rows = [];
        if (is_array($rowsIn)) {
            foreach ($rowsIn as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string)($row['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $rows[] = self::slimRow($row);
            }
        }

        $summary = self::summarize($rows);
        if (isset($run['summary']) && is_array($run['summary'])) {
            foreach (['pass', 'fail', 'skip'] as $k) {
                if (isset($run['summary'][$k])) {
                    $summary[$k] = (int)$run['summary'][$k];
                }
            }
        }
        $ms = isset($run['ms']) ? (int)$run['ms'] : (int)($summary['ms'] ?? 0);
        $summary['ms'] = $ms;

        $out = [
            'version' => self::VERSION,
            'profile' => $safe,
            'live' => !isset($run['live']) || !empty($run['live']),
            'finished' => !isset($run['finished']) || !empty($run['finished']),
            'finished_at' => trim((string)($run['finished_at'] ?? '')),
            'saved_at' => gmdate('c'),
            'ms' => $ms,
            'summary' => $summary,
            'system_prompt' => (string)($run['system_prompt'] ?? ''),
            'rows' => $rows,
        ];
        if ($out['finished_at'] === '' && !empty($out['finished'])) {
            $out['finished_at'] = $out['saved_at'];
        }

        if (!self::ensureDir()) {
            return ['ok' => false, 'error' => 'mkdir failed', 'run' => null];
        }
        $path = self::profilePath($safe);
        $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return ['ok' => false, 'error' => 'json encode failed', 'run' => null];
        }
        if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'write failed', 'run' => null];
        }
        $ptr = json_encode(['profile' => $safe, 'saved_at' => $out['saved_at']], JSON_UNESCAPED_UNICODE);
        if ($ptr !== false) {
            @file_put_contents(self::latestPointerPath(), $ptr . "\n", LOCK_EX);
        }
        return ['ok' => true, 'error' => '', 'run' => $out];
    }

    /**
     * Merge one row into an existing run (retry single case) and save.
     *
     * @param array<string,mixed> $row
     * @return array{ok:bool,error:string,run:?array}
     */
    public static function upsertRow(string $profile, array $row, array $extra = []): array
    {
        $existing = self::load($profile) ?? [
            'profile' => $profile !== '' ? $profile : self::defaultProfileId(),
            'live' => true,
            'rows' => [],
            'summary' => ['pass' => 0, 'fail' => 0, 'skip' => 0, 'ms' => 0],
            'ms' => 0,
            'system_prompt' => '',
            'finished' => true,
        ];
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '') {
            return ['ok' => false, 'error' => 'empty row id', 'run' => null];
        }
        $rows = is_array($existing['rows'] ?? null) ? $existing['rows'] : [];
        $found = false;
        foreach ($rows as $i => $old) {
            if (!is_array($old)) {
                continue;
            }
            if ((string)($old['id'] ?? '') === $id) {
                $rows[$i] = self::slimRow($row);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $rows[] = self::slimRow($row);
        }
        $payload = array_merge($existing, $extra, [
            'profile' => (string)($existing['profile'] ?? $profile),
            'rows' => $rows,
            'finished' => true,
        ]);
        if (!empty($extra['system_prompt'])) {
            $payload['system_prompt'] = (string)$extra['system_prompt'];
        }
        if (isset($extra['live'])) {
            $payload['live'] = !empty($extra['live']);
        }
        $payload['summary'] = self::summarize($rows);
        $payload['ms'] = (int)($payload['summary']['ms'] ?? 0);
        return self::save($payload);
    }

    public static function dir(): string
    {
        return base_path('var/assistant/eval');
    }

    private static function ensureDir(): bool
    {
        $dir = self::dir();
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        return @mkdir($dir, 0775, true) || is_dir($dir);
    }

    private static function safeProfile(string $profile): string
    {
        $profile = strtolower(trim($profile));
        $profile = preg_replace('/[^a-z0-9._-]+/', '_', $profile) ?? '';
        $profile = trim($profile, '._-');
        if ($profile === '' || strlen($profile) > 64) {
            return '';
        }
        return $profile;
    }

    private static function profilePath(string $safeProfile): string
    {
        return self::dir() . '/' . $safeProfile . '.json';
    }

    private static function latestPointerPath(): string
    {
        return self::dir() . '/_latest.json';
    }

    /** @return array<string,mixed>|null */
    private static function readJson(string $path): ?array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** @return array<string,mixed>|null */
    private static function loadNewest(): ?array
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return null;
        }
        $best = null;
        $bestMtime = 0;
        $files = @scandir($dir);
        if (!is_array($files)) {
            return null;
        }
        foreach ($files as $f) {
            if ($f === '.' || $f === '..' || $f === '_latest.json' || substr($f, -5) !== '.json') {
                continue;
            }
            $path = $dir . '/' . $f;
            $mtime = (int)@filemtime($path);
            if ($mtime >= $bestMtime) {
                $run = self::normalize(self::readJson($path));
                if ($run !== null) {
                    $best = $run;
                    $bestMtime = $mtime;
                }
            }
        }
        return $best;
    }

    /**
     * @param array<string,mixed>|null $data
     * @return array<string,mixed>|null
     */
    private static function normalize(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }
        $rows = [];
        foreach ((array)($data['rows'] ?? []) as $row) {
            if (is_array($row) && trim((string)($row['id'] ?? '')) !== '') {
                $rows[] = self::slimRow($row);
            }
        }
        $summary = self::summarize($rows);
        if (isset($data['summary']) && is_array($data['summary'])) {
            foreach (['pass', 'fail', 'skip', 'ms'] as $k) {
                if (isset($data['summary'][$k])) {
                    $summary[$k] = (int)$data['summary'][$k];
                }
            }
        }
        return [
            'version' => (int)($data['version'] ?? self::VERSION),
            'profile' => (string)($data['profile'] ?? self::defaultProfileId()),
            'live' => !isset($data['live']) || !empty($data['live']),
            'finished' => !isset($data['finished']) || !empty($data['finished']),
            'finished_at' => (string)($data['finished_at'] ?? $data['saved_at'] ?? ''),
            'saved_at' => (string)($data['saved_at'] ?? ''),
            'ms' => (int)($data['ms'] ?? $summary['ms']),
            'summary' => $summary,
            'system_prompt' => (string)($data['system_prompt'] ?? ''),
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function slimRow(array $row): array
    {
        return [
            'id' => (string)($row['id'] ?? ''),
            'layer' => (string)($row['layer'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'explain' => (string)($row['explain'] ?? ''),
            'question' => (string)($row['question'] ?? ''),
            'answer' => (string)($row['answer'] ?? ''),
            'status' => (string)($row['status'] ?? 'skip'),
            'detail' => (string)($row['detail'] ?? ''),
            'ms' => (int)($row['ms'] ?? 0),
            'excerpt' => (string)($row['excerpt'] ?? ''),
            'repaired' => !empty($row['repaired']),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{pass:int,fail:int,skip:int,ms:int}
     */
    private static function summarize(array $rows): array
    {
        $s = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'ms' => 0];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $st = (string)($r['status'] ?? 'skip');
            if (isset($s[$st])) {
                $s[$st]++;
            } else {
                $s['skip']++;
            }
            $s['ms'] += (int)($r['ms'] ?? 0);
        }
        return $s;
    }
}
