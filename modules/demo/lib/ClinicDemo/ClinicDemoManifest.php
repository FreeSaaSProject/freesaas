<?php
declare(strict_types=1);

/**
 * Manifest of everything the seeder created: ids per table, natural-key index,
 * run parameters, step log. Enables idempotent re-runs and targeted cleanup.
 */
final class ClinicDemoManifest
{
    private string $path;
    /** @var array<string,mixed> */
    private array $data = [];

    public function __construct(string $path)
    {
        $this->path = $path;
        if (is_file($path)) {
            $raw = (string)file_get_contents($path);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $this->data = $decoded;
            }
        }
        if (!isset($this->data['ids']) || !is_array($this->data['ids'])) {
            $this->data['ids'] = [];
        }
        if (!isset($this->data['index']) || !is_array($this->data['index'])) {
            $this->data['index'] = [];
        }
        if (!isset($this->data['log']) || !is_array($this->data['log'])) {
            $this->data['log'] = [];
        }
        if (!isset($this->data['kv']) || !is_array($this->data['kv'])) {
            $this->data['kv'] = [];
        }
    }

    public static function defaultPath(): string
    {
        return base_path('var/demo/clinic-manifest.json');
    }

    public function exists(): bool
    {
        return is_file($this->path) && $this->total() > 0;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function add(string $bucket, int $id): void
    {
        if ($id < 1) {
            return;
        }
        if (!isset($this->data['ids'][$bucket]) || !is_array($this->data['ids'][$bucket])) {
            $this->data['ids'][$bucket] = [];
        }
        $this->data['ids'][$bucket][] = $id;
    }

    /** @param list<int> $ids */
    public function addMany(string $bucket, array $ids): void
    {
        foreach ($ids as $id) {
            $this->add($bucket, (int)$id);
        }
    }

    /** @return list<int> */
    public function ids(string $bucket): array
    {
        $raw = $this->data['ids'][$bucket] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $out[$id] = true;
            }
        }
        return array_map('intval', array_keys($out));
    }

    public function total(): int
    {
        $n = 0;
        foreach ($this->data['ids'] as $bucket) {
            if (is_array($bucket)) {
                $n += count($bucket);
            }
        }
        return $n;
    }

    public function forget(string $bucket): void
    {
        unset($this->data['ids'][$bucket]);
    }

    public function set(string $key, $value): void
    {
        $this->data['index'][$key] = $value;
    }

    public function get(string $key, $default = null)
    {
        if (array_key_exists($key, $this->data['index'])) {
            return $this->data['index'][$key];
        }
        if (array_key_exists($key, $this->data['kv'])) {
            return $this->data['kv'][$key];
        }
        return $default;
    }

    public function kv(string $key, $value): void
    {
        $this->data['kv'][$key] = $value;
    }

    /** @param array<string,mixed> $payload */
    public function log(string $step, array $payload = []): void
    {
        $this->data['log'][] = ['step' => $step, 'at' => date('c')] + $payload;
    }

    /** @return list<array<string,mixed>> */
    public function logEntries(): array
    {
        return $this->data['log'];
    }

    public function save(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $tmp = $this->path . '.tmp';
        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('manifest encode failed');
        }
        file_put_contents($tmp, $json);
        rename($tmp, $this->path);
    }

    public function reset(): void
    {
        $this->data = ['ids' => [], 'index' => [], 'log' => [], 'kv' => []];
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /** @return array<string,int> bucket => count */
    public function summary(): array
    {
        $out = [];
        foreach ($this->data['ids'] as $bucket => $ids) {
            if (is_array($ids) && $ids !== []) {
                $out[$bucket] = count(array_unique(array_map('intval', $ids)));
            }
        }
        ksort($out);
        return $out;
    }
}
