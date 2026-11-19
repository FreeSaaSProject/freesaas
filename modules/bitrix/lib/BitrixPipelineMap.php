<?php
/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Bitrix deal categories / lead statuses ↔ FreeSaaS crm pipelines + stages.
 *
 * One local pipeline per Bitrix deal category (kind=sale) and one for leads (kind=lead).
 * Map rows live in base_bitrix_id_map with entity `pipeline` and ext_id `deal:<categoryId>` | `lead`.
 * Stage code = Bitrix STATUS_ID without the `C<n>:` category prefix (unique per local pipeline).
 * Disable with `map_pipelines = 0` (legacy: first enabled pipeline of the kind + code match).
 */
final class BitrixPipelineMap
{
    /** @var array<string, array{pipeline_id:int, stages:array<string,int>}|null> */
    private static array $cache = [];

    /** @var array<int, array<string,mixed>>|null */
    private static ?array $categories = null;

    public static function enabled(): bool
    {
        return BitrixConfig::flag('map_pipelines', true);
    }

    /** Forget cached remote structure (call once per import run). */
    public static function reset(): void
    {
        self::$cache = [];
        self::$categories = null;
    }

    /**
     * Local [pipelineId, stageId] for a Bitrix deal/lead row; [0, 0] when mapping is off or unavailable.
     *
     * @param array<string,mixed> $row
     * @return array{0:int,1:int}
     */
    public static function resolve(string $portal, string $kind, array $row): array
    {
        if (!self::enabled() || !CrmPipelinesRepository::tableReady()) {
            return [0, 0];
        }
        $key = $kind === 'lead' ? 'lead' : 'deal:' . (int)($row['CATEGORY_ID'] ?? 0);
        $map = self::ensure($portal, $key);
        if ($map === null) {
            return [0, 0];
        }
        $code = self::localCode((string)($row['STAGE_ID'] ?? ($row['STATUS_ID'] ?? '')));
        $stageId = $map['stages'][$code] ?? 0;
        if ($stageId < 1) {
            $stageId = CrmPipelinesRepository::firstStageId($map['pipeline_id']);
        }
        return [$map['pipeline_id'], $stageId];
    }

    public static function localCode(string $bitrixStage): string
    {
        $s = strtoupper(trim($bitrixStage));
        if (preg_match('/^C\d+:(.+)$/', $s, $m)) {
            $s = $m[1];
        }
        return $s;
    }

    /**
     * Bitrix STAGE_ID (+ CATEGORY_ID for non-default deal categories) for a local deal (push side).
     *
     * @return array<string,int|string>
     */
    public static function forPush(string $portal, int $pipelineId, string $stageCode): array
    {
        $code = strtoupper(trim($stageCode));
        $ext = $pipelineId > 0 ? BitrixIdMapRepository::findExtId($portal, 'pipeline', $pipelineId) : null;
        if ($ext !== null && preg_match('/^deal:(\d+)$/', $ext, $m) && (int)$m[1] > 0) {
            return ['STAGE_ID' => 'C' . $m[1] . ':' . $code, 'CATEGORY_ID' => (int)$m[1]];
        }
        return ['STAGE_ID' => $code];
    }

    /** @return array{pipeline_id:int, stages:array<string,int>}|null */
    private static function ensure(string $portal, string $key): ?array
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        self::$cache[$key] = null;

        $remote = self::fetchRemote($key);
        if ($remote === null) {
            return null;
        }
        $kind = $key === 'lead' ? 'lead' : 'sale';

        $pipelineId = BitrixIdMapRepository::findLocalId($portal, 'pipeline', $key) ?? 0;
        if ($pipelineId > 0 && CrmPipelinesRepository::findPipeline($pipelineId) === null) {
            $pipelineId = 0;
        }
        if ($pipelineId < 1) {
            $pipelineId = CrmPipelinesRepository::createPipeline([
                'name' => $remote['name'],
                'kind' => $kind,
                'enabled' => 1,
                'sort_order' => $remote['sort'],
            ]);
            BitrixIdMapRepository::upsert($portal, 'pipeline', $key, $pipelineId);
        }

        $existing = [];
        foreach (CrmPipelinesRepository::listStages($pipelineId) as $s) {
            $existing[strtoupper((string)$s['code'])] = (int)$s['id'];
        }
        $stages = [];
        foreach ($remote['statuses'] as $st) {
            $code = self::localCode((string)($st['STATUS_ID'] ?? ''));
            if ($code === '' || isset($stages[$code])) {
                continue;
            }
            $name = trim((string)($st['NAME'] ?? ''));
            $data = [
                'pipeline_id' => $pipelineId,
                'name' => $name !== '' ? $name : $code,
                'code' => $code,
                'semantic' => self::semantic($st),
                'sort_order' => (int)($st['SORT'] ?? 0),
                'enabled' => 1,
            ];
            if (isset($existing[$code])) {
                CrmPipelinesRepository::updateStage($existing[$code], $data);
                $stages[$code] = $existing[$code];
            } else {
                $stages[$code] = CrmPipelinesRepository::createStage($data);
            }
        }
        if ($stages === []) {
            return null;
        }
        return self::$cache[$key] = ['pipeline_id' => $pipelineId, 'stages' => $stages];
    }

    /**
     * @return array{name:string, sort:int, statuses:list<array<string,mixed>>}|null
     */
    private static function fetchRemote(string $key): ?array
    {
        if ($key === 'lead') {
            $statuses = self::statuses('STATUS');
            return $statuses === null ? null : [
                'name' => t('bitrix.pipeline.leads'),
                'sort' => 5,
                'statuses' => $statuses,
            ];
        }
        $categoryId = (int)substr($key, 5);
        $cat = self::categories()[$categoryId] ?? null;
        $name = trim((string)($cat['name'] ?? ''));
        if ($name === '') {
            $name = $categoryId === 0
                ? t('bitrix.pipeline.deals_default')
                : t('bitrix.pipeline.deals_n', ['id' => (string)$categoryId]);
        }
        $statuses = self::statuses($categoryId === 0 ? 'DEAL_STAGE' : 'DEAL_STAGE_' . $categoryId);
        return $statuses === null ? null : [
            'name' => $name,
            'sort' => 100 + (int)($cat['sort'] ?? $categoryId),
            'statuses' => $statuses,
        ];
    }

    /** @return list<array<string,mixed>>|null */
    private static function statuses(string $entityId): ?array
    {
        $res = BitrixApiClient::call('crm.status.list', [
            'order' => ['SORT' => 'ASC'],
            'filter' => ['ENTITY_ID' => $entityId],
        ]);
        if (!$res['ok'] || !is_array($res['result'] ?? null)) {
            return null;
        }
        return array_values(array_filter($res['result'], 'is_array'));
    }

    /** @return array<int, array<string,mixed>> */
    private static function categories(): array
    {
        if (self::$categories !== null) {
            return self::$categories;
        }
        self::$categories = [];
        $res = BitrixApiClient::call('crm.category.list', ['entityTypeId' => 2]);
        $list = $res['ok'] ? ($res['result']['categories'] ?? []) : [];
        foreach (is_array($list) ? $list : [] as $c) {
            if (is_array($c) && isset($c['id'])) {
                self::$categories[(int)$c['id']] = $c;
            }
        }
        return self::$categories;
    }

    /** @param array<string,mixed> $status */
    private static function semantic(array $status): string
    {
        $raw = strtoupper(trim((string)($status['SEMANTICS'] ?? '')));
        if ($raw === '') {
            $extra = is_array($status['EXTRA'] ?? null) ? $status['EXTRA'] : [];
            $raw = strtoupper(trim((string)($extra['SEMANTICS'] ?? '')));
        }
        return match ($raw) {
            'S', 'SUCCESS' => 'S',
            'F', 'FAILURE', 'APOLOGY' => 'F',
            default => 'P',
        };
    }
}
