<?php
declare(strict_types=1);

/**
 * Diagnose / grant / revoke skill (procedure type or product) via rates matrix + ban flags.
 */
final class RatesSkill
{
    /**
     * Resolve service names for the assistant (types + catalog products).
     *
     * @return array{ok:bool,q:string,types:list<array{id:int,name:string}>,products:list<array{id:int,name:string,type_id:int,type_name:string}>}
     */
    public static function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        $limit = max(1, min(50, $limit));
        $types = [];
        $products = [];
        if ($q === '') {
            return ['ok' => true, 'q' => $q, 'types' => [], 'products' => []];
        }
        $qLower = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
        if (class_exists('ProcedureTypesRepository', false)) {
            foreach (ProcedureTypesRepository::names() as $tid => $name) {
                $name = trim((string)$name);
                if ($name === '') {
                    continue;
                }
                $hay = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
                if ($hay === $qLower || strpos($hay, $qLower) !== false) {
                    $types[] = ['id' => (int)$tid, 'name' => $name];
                    if (count($types) >= $limit) {
                        break;
                    }
                }
            }
        }
        if (class_exists('ProductsRepository', false)) {
            $listed = ProductsRepository::list([
                'q' => $q,
                'enabled' => 1,
                'is_material' => 0,
                'limit' => $limit,
                'offset' => 0,
            ]);
            $typeNames = class_exists('ProcedureTypesRepository', false)
                ? ProcedureTypesRepository::names()
                : [];
            foreach ($listed['items'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $tid = (int)($row['type_id'] ?? 0);
                $products[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'name' => trim((string)($row['name'] ?? '')),
                    'type_id' => $tid,
                    'type_name' => $tid > 0 ? (string)($typeNames[$tid] ?? ('#' . $tid)) : '',
                ];
            }
        }
        return [
            'ok' => true,
            'q' => $q,
            'types' => $types,
            'products' => $products,
            'hint' => 'Prefer products[].id as good_id when a product matches; else types[].id as type_id. Then rates.skill.diagnose / grant / revoke.',
        ];
    }

    /**
     * @return array{
     *   ok:bool,
     *   operator_id:int,
     *   type_id:int,
     *   product_id:int,
     *   blockers:list<string>,
     *   percent:?float,
     *   banned_booking:bool,
     *   banned_online:bool,
     *   has_rates_row:bool,
     *   message:string
     * }
     */
    public static function diagnose(int $operatorId, int $typeId = 0, int $productId = 0, int $dayStart = 0): array
    {
        $dayStart = $dayStart > 0 ? $dayStart : (int)mktime(0, 0, 0);
        $asOf = RatesRepository::asOfForDay($dayStart);
        if ($typeId < 1 && $productId > 0 && class_exists(ProductsRepository::class, false)) {
            $p = ProductsRepository::find($productId);
            $typeId = $p ? max(0, (int)($p['type_id'] ?? 0)) : 0;
        }
        $probe = [['id' => max(0, $productId), 'type_id' => $typeId]];
        $exp = class_exists(BookingOperatorGate::class, false)
            ? BookingOperatorGate::explain($operatorId, $probe, $dayStart, false)
            : ['ok' => false, 'blockers' => ['gate_unavailable']];
        $expOnline = class_exists(BookingOperatorGate::class, false)
            ? BookingOperatorGate::explain($operatorId, $probe, $dayStart, true)
            : $exp;

        $row = null;
        if (class_exists(RatesRepository::class, false)) {
            $row = RatesRepository::latestForOperator($operatorId, $asOf);
        }
        $pct = null;
        $bannedBooking = false;
        $bannedOnline = false;
        $bannedProc = false;
        if ($row !== null && $typeId > 0) {
            $content = is_array($row['content'] ?? null) ? $row['content'] : [];
            $raw = $content[$typeId] ?? $content[(string)$typeId] ?? null;
            if ($raw !== null && is_numeric($raw)) {
                $pct = (float)$raw;
            }
            $bb = is_array($row['ban_booking'] ?? null) ? $row['ban_booking'] : [];
            $bo = is_array($row['ban_online'] ?? null) ? $row['ban_online'] : [];
            $bp = is_array($row['ban_procs'] ?? null) ? $row['ban_procs'] : [];
            $bannedBooking = !empty($bb[$typeId]) || !empty($bb[(string)$typeId]);
            $bannedOnline = !empty($bo[$typeId]) || !empty($bo[(string)$typeId]);
            if ($productId > 0) {
                $bannedProc = !empty($bp[$productId]) || !empty($bp[(string)$productId]);
            }
        }

        $blockers = array_values(array_unique(array_merge(
            $exp['blockers'] ?? [],
            array_map(static fn(string $b): string => 'online:' . $b, $expOnline['blockers'] ?? [])
        )));

        return [
            'ok' => !empty($exp['ok']),
            'ok_online' => !empty($expOnline['ok']),
            'operator_id' => $operatorId,
            'type_id' => $typeId,
            'product_id' => $productId,
            'blockers' => $blockers,
            'percent' => $pct,
            'banned_booking' => $bannedBooking,
            'banned_online' => $bannedOnline,
            'banned_proc' => $bannedProc,
            'has_rates_row' => $row !== null,
            'message' => t('rates.diagnose.title', ['id' => $operatorId]),
        ];
    }

    /**
     * Enable skill for type: ensure % and clear bans.
     *
     * @param array{operator_id:int,type_id?:int,good_id?:int,percent?:float|null,dry_run?:bool,clear_online_ban?:bool} $opts
     * @return array<string,mixed>
     */
    public static function grant(array $opts): array
    {
        $operatorId = (int)($opts['operator_id'] ?? 0);
        $typeId = (int)($opts['type_id'] ?? 0);
        $goodId = (int)($opts['good_id'] ?? $opts['product_id'] ?? 0);
        $dryRun = !empty($opts['dry_run']);
        $clearOnline = array_key_exists('clear_online_ban', $opts) ? !empty($opts['clear_online_ban']) : true;
        $explicitPct = array_key_exists('percent', $opts) && $opts['percent'] !== null && $opts['percent'] !== ''
            ? (float)str_replace(',', '.', (string)$opts['percent'])
            : null;

        if ($operatorId < 1) {
            throw new InvalidArgumentException(t('rates.err.operator_required'));
        }
        if ($typeId < 1 && $goodId > 0 && class_exists(ProductsRepository::class, false)) {
            $p = ProductsRepository::find($goodId);
            $typeId = $p ? max(0, (int)($p['type_id'] ?? 0)) : 0;
        }
        if ($typeId < 1) {
            throw new InvalidArgumentException(t('rates.grant.needs_percent', ['type' => 0]));
        }

        $typeNames = ProcedureTypesRepository::names();
        $typeLabel = $typeNames[$typeId] ?? ('#' . $typeId);

        $row = RatesRepository::latestForOperator($operatorId, time());
        $content = (is_array($row) && is_array($row['content'] ?? null)) ? $row['content'] : [];
        $banBooking = (is_array($row) && is_array($row['ban_booking'] ?? null)) ? $row['ban_booking'] : [];
        $banOnline = (is_array($row) && is_array($row['ban_online'] ?? null)) ? $row['ban_online'] : [];
        $banProcs = (is_array($row) && is_array($row['ban_procs'] ?? null)) ? $row['ban_procs'] : [];
        $existingPct = null;
        $rawExisting = $content[$typeId] ?? $content[(string)$typeId] ?? null;
        if ($rawExisting !== null && is_numeric($rawExisting) && (float)$rawExisting > 0) {
            $existingPct = (float)$rawExisting;
        }
        $procBanned = $goodId > 0
            && (!empty($banProcs[$goodId]) || !empty($banProcs[(string)$goodId]));
        $wasBanned = !empty($banBooking[$typeId]) || !empty($banBooking[(string)$typeId])
            || ($clearOnline && (!empty($banOnline[$typeId]) || !empty($banOnline[(string)$typeId])))
            || $procBanned;

        $source = '';
        $pct = $explicitPct;
        if ($pct !== null && $pct > 0) {
            $source = 'explicit';
        } elseif ($existingPct !== null) {
            $pct = $existingPct;
            $source = 'existing';
        } else {
            $suggested = self::suggestPercent($operatorId, $typeId, $row);
            if ($suggested === null) {
                return [
                    'ok' => false,
                    'error' => 'needs_percent',
                    'message' => t('rates.grant.needs_percent', ['type' => $typeId]),
                    'operator_id' => $operatorId,
                    'type_id' => $typeId,
                    'dry_run' => $dryRun,
                ];
            }
            $pct = $suggested['percent'];
            $source = $suggested['source'];
        }

        $alreadyOn = $existingPct !== null && !$wasBanned
            && abs($existingPct - $pct) < 0.0001;

        if ($alreadyOn) {
            $message = t('rates.grant.already_on', [
                'type' => $typeLabel,
                'pct' => self::fmtPct($pct),
            ]);
            return [
                'ok' => true,
                'changed' => false,
                'message' => $message,
                'operator_id' => $operatorId,
                'type_id' => $typeId,
                'product_id' => $goodId,
                'percent' => $pct,
                'source' => $source,
                'dry_run' => $dryRun,
            ];
        }

        if ($existingPct !== null && $wasBanned && ($explicitPct === null || abs($explicitPct - $existingPct) < 0.0001)) {
            $message = $procBanned && $goodId > 0
                ? t('rates.grant.was_banned_product', [
                    'type' => $typeLabel,
                    'pct' => self::fmtPct($existingPct),
                ])
                : t('rates.grant.was_banned', [
                    'type' => $typeLabel,
                    'pct' => self::fmtPct($existingPct),
                ]);
            $pct = $existingPct;
            $source = 'existing';
        } else {
            $message = t('rates.grant.enabled_with_percent', [
                'type' => $typeLabel,
                'pct' => self::fmtPct($pct),
                'source' => t('rates.grant.source.' . $source),
            ]);
        }

        if ($dryRun) {
            return [
                'ok' => true,
                'changed' => true,
                'message' => $message,
                'operator_id' => $operatorId,
                'type_id' => $typeId,
                'product_id' => $goodId,
                'percent' => $pct,
                'source' => $source,
                'dry_run' => true,
                'would_clear_ban_booking' => true,
                'would_clear_ban_online' => $clearOnline,
                'would_clear_ban_proc' => $goodId > 0,
            ];
        }

        $data = self::buildGrantRow($operatorId, $row, $typeId, $pct, $clearOnline, $goodId);
        // DT = now so same-day later rows win; gate uses asOfForDay (end of day).
        $data['dt'] = time();
        $data['descr'] = self::grantDescr($typeLabel, $pct);
        $newId = RatesRepository::create($data);

        return [
            'ok' => true,
            'changed' => true,
            'message' => $message,
            'operator_id' => $operatorId,
            'type_id' => $typeId,
            'product_id' => $goodId,
            'percent' => $pct,
            'source' => $source,
            'rates_id' => $newId,
            'dry_run' => false,
        ];
    }

    /**
     * Disable skill: ban type (booking/online) or a single product (ban_procs). Keeps %.
     *
     * @param array{operator_id:int,type_id?:int,good_id?:int,dry_run?:bool,ban_online?:bool} $opts
     * @return array<string,mixed>
     */
    public static function revoke(array $opts): array
    {
        $operatorId = (int)($opts['operator_id'] ?? 0);
        $typeId = (int)($opts['type_id'] ?? 0);
        $goodId = (int)($opts['good_id'] ?? $opts['product_id'] ?? 0);
        $dryRun = !empty($opts['dry_run']);
        $banOnline = array_key_exists('ban_online', $opts) ? !empty($opts['ban_online']) : true;

        if ($operatorId < 1) {
            throw new InvalidArgumentException(t('rates.err.operator_required'));
        }
        if ($typeId < 1 && $goodId > 0 && class_exists('ProductsRepository', false)) {
            $p = ProductsRepository::find($goodId);
            $typeId = $p ? max(0, (int)($p['type_id'] ?? 0)) : 0;
        }
        if ($typeId < 1 && $goodId < 1) {
            throw new InvalidArgumentException(t('rates.revoke.needs_target'));
        }

        $typeNames = class_exists('ProcedureTypesRepository', false)
            ? ProcedureTypesRepository::names()
            : [];
        $typeLabel = $typeId > 0 ? ($typeNames[$typeId] ?? ('#' . $typeId)) : '';
        $productLabel = '';
        if ($goodId > 0 && class_exists('ProductsRepository', false)) {
            $p = ProductsRepository::find($goodId);
            $productLabel = $p ? trim((string)($p['name'] ?? '')) : ('#' . $goodId);
        }

        $row = RatesRepository::latestForOperator($operatorId, time());
        $banBooking = (is_array($row) && is_array($row['ban_booking'] ?? null)) ? $row['ban_booking'] : [];
        $banOnlineMap = (is_array($row) && is_array($row['ban_online'] ?? null)) ? $row['ban_online'] : [];
        $banProcs = (is_array($row) && is_array($row['ban_procs'] ?? null)) ? $row['ban_procs'] : [];

        $mode = $goodId > 0 ? 'product' : 'type';
        if ($mode === 'type' && $typeId < 1) {
            throw new InvalidArgumentException(t('rates.revoke.needs_target'));
        }
        if ($mode === 'product') {
            $alreadyOff = !empty($banProcs[$goodId]) || !empty($banProcs[(string)$goodId]);
            $label = $productLabel !== '' ? $productLabel : ('#' . $goodId);
            $message = $alreadyOff
                ? t('rates.revoke.already_off_product', ['product' => $label])
                : t('rates.revoke.product', ['product' => $label]);
        } else {
            $bb = !empty($banBooking[$typeId]) || !empty($banBooking[(string)$typeId]);
            $bo = !empty($banOnlineMap[$typeId]) || !empty($banOnlineMap[(string)$typeId]);
            $alreadyOff = $bb && (!$banOnline || $bo);
            $message = $alreadyOff
                ? t('rates.revoke.already_off_type', ['type' => $typeLabel])
                : t('rates.revoke.type', ['type' => $typeLabel]);
        }

        if ($alreadyOff) {
            return [
                'ok' => true,
                'changed' => false,
                'message' => $message,
                'operator_id' => $operatorId,
                'type_id' => $typeId,
                'product_id' => $goodId,
                'mode' => $mode,
                'dry_run' => $dryRun,
            ];
        }

        if ($dryRun) {
            return [
                'ok' => true,
                'changed' => true,
                'message' => $message,
                'operator_id' => $operatorId,
                'type_id' => $typeId,
                'product_id' => $goodId,
                'mode' => $mode,
                'dry_run' => true,
                'would_ban_booking' => $mode === 'type',
                'would_ban_online' => $mode === 'type' && $banOnline,
                'would_ban_proc' => $mode === 'product',
            ];
        }

        $data = self::buildRevokeRow($operatorId, $row, $typeId, $goodId, $mode, $banOnline);
        $data['dt'] = time();
        $short = $mode === 'product'
            ? ($productLabel !== '' ? $productLabel : ('#' . $goodId))
            : ($typeLabel !== '' ? explode(' — ', $typeLabel)[0] : ('#' . $typeId));
        $data['descr'] = 'revoke ' . $short;
        $newId = RatesRepository::create($data);

        return [
            'ok' => true,
            'changed' => true,
            'message' => $message,
            'operator_id' => $operatorId,
            'type_id' => $typeId,
            'product_id' => $goodId,
            'mode' => $mode,
            'rates_id' => $newId,
            'dry_run' => false,
        ];
    }

    /**
     * @return array{percent:float,source:string}|null
     */
    public static function suggestPercent(int $operatorId, int $typeId, ?array $ownRow = null): ?array
    {
        if ($ownRow === null) {
            $ownRow = RatesRepository::latestForOperator($operatorId, time());
        }
        if ($ownRow !== null) {
            $content = is_array($ownRow['content'] ?? null) ? $ownRow['content'] : [];
            $vals = [];
            foreach ($content as $tid => $v) {
                if ((int)$tid === $typeId) {
                    continue;
                }
                $f = (float)str_replace(',', '.', (string)$v);
                if ($f > 0) {
                    $vals[] = $f;
                }
            }
            if ($vals !== []) {
                sort($vals);
                $mid = $vals[(int)floor((count($vals) - 1) / 2)];
                return ['percent' => $mid, 'source' => 'own_other'];
            }
        }

        // Peer staff: latest rows with this type > 0
        if (!Database::tableExists('base_procent_history')) {
            return null;
        }
        $peerVals = [];
        $listed = RatesRepository::list(['limit' => 200, 'offset' => 0]);
        $seenOp = [];
        foreach ($listed['items'] as $item) {
            $oid = (int)($item['operator_id'] ?? 0);
            if ($oid < 1 || $oid === $operatorId || isset($seenOp[$oid])) {
                continue;
            }
            // Prefer latest per operator: list is ID DESC so first hit wins
            $seenOp[$oid] = true;
            $content = is_array($item['content'] ?? null) ? $item['content'] : [];
            $raw = $content[$typeId] ?? $content[(string)$typeId] ?? null;
            if ($raw === null) {
                continue;
            }
            $f = (float)str_replace(',', '.', (string)$raw);
            if ($f > 0) {
                $peerVals[] = $f;
            }
        }
        if ($peerVals === []) {
            return null;
        }
        sort($peerVals);
        $mid = $peerVals[(int)floor((count($peerVals) - 1) / 2)];
        return ['percent' => $mid, 'source' => 'peer'];
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    private static function buildGrantRow(
        int $operatorId,
        ?array $row,
        int $typeId,
        float $pct,
        bool $clearOnline,
        int $clearProductId = 0
    ): array {
        $typeIds = array_keys(ProcedureTypesRepository::names());
        $maps = [];
        foreach (['content', 'content_sales', 'content_income', 'content_terminal'] as $key) {
            $base = is_array($row[$key] ?? null) ? $row[$key] : [];
            $map = [];
            foreach ($typeIds as $tid) {
                $map[(int)$tid] = (float)($base[$tid] ?? $base[(string)$tid] ?? 0);
            }
            $maps[$key] = $map;
        }
        $maps['content'][$typeId] = $pct;

        $banBooking = is_array($row['ban_booking'] ?? null) ? $row['ban_booking'] : [];
        $banOnline = is_array($row['ban_online'] ?? null) ? $row['ban_online'] : [];
        $banProcs = is_array($row['ban_procs'] ?? null) ? $row['ban_procs'] : [];
        unset($banBooking[$typeId], $banBooking[(string)$typeId]);
        if ($clearOnline) {
            unset($banOnline[$typeId], $banOnline[(string)$typeId]);
        }
        if ($clearProductId > 0) {
            unset($banProcs[$clearProductId], $banProcs[(string)$clearProductId]);
        }

        $typeLabel = ProcedureTypesRepository::names()[$typeId] ?? ('#' . $typeId);
        return [
            'operator_id' => $operatorId,
            'dt' => time(),
            'descr' => self::grantDescr($typeLabel, $pct),
            'content' => $maps['content'],
            'content_sales' => $maps['content_sales'],
            'content_income' => $maps['content_income'],
            'content_terminal' => $maps['content_terminal'],
            'ban_booking' => $banBooking,
            'ban_online' => $banOnline,
            'ban_procs' => $banProcs,
            'loss' => (float)($row['loss'] ?? 0),
            'loss_fix' => (float)($row['loss_fix'] ?? 0),
            'offers' => (int)($row['offers'] ?? 0),
            'audio_hour_rate' => (float)($row['audio_hour_rate'] ?? 0),
            'script_score_rate' => (float)($row['script_score_rate'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    private static function buildRevokeRow(
        int $operatorId,
        ?array $row,
        int $typeId,
        int $goodId,
        string $mode,
        bool $banOnline
    ): array {
        $typeIds = array_keys(ProcedureTypesRepository::names());
        $maps = [];
        foreach (['content', 'content_sales', 'content_income', 'content_terminal'] as $key) {
            $base = is_array($row[$key] ?? null) ? $row[$key] : [];
            $map = [];
            foreach ($typeIds as $tid) {
                $map[(int)$tid] = (float)($base[$tid] ?? $base[(string)$tid] ?? 0);
            }
            $maps[$key] = $map;
        }

        $banBooking = is_array($row['ban_booking'] ?? null) ? $row['ban_booking'] : [];
        $banOnlineMap = is_array($row['ban_online'] ?? null) ? $row['ban_online'] : [];
        $banProcs = is_array($row['ban_procs'] ?? null) ? $row['ban_procs'] : [];

        if ($mode === 'product' && $goodId > 0) {
            $banProcs[$goodId] = 1;
        } elseif ($typeId > 0) {
            $banBooking[$typeId] = 1;
            if ($banOnline) {
                $banOnlineMap[$typeId] = 1;
            }
        }

        return [
            'operator_id' => $operatorId,
            'dt' => time(),
            'descr' => 'revoke',
            'content' => $maps['content'],
            'content_sales' => $maps['content_sales'],
            'content_income' => $maps['content_income'],
            'content_terminal' => $maps['content_terminal'],
            'ban_booking' => $banBooking,
            'ban_online' => $banOnlineMap,
            'ban_procs' => $banProcs,
            'loss' => (float)($row['loss'] ?? 0),
            'loss_fix' => (float)($row['loss_fix'] ?? 0),
            'offers' => (int)($row['offers'] ?? 0),
            'audio_hour_rate' => (float)($row['audio_hour_rate'] ?? 0),
            'script_score_rate' => (float)($row['script_score_rate'] ?? 0),
        ];
    }

    private static function grantDescr(string $typeLabel, float $pct): string
    {
        $short = explode(' — ', $typeLabel)[0];
        return 'grant ' . $short . ' ' . self::fmtPct($pct) . '%';
    }

    private static function fmtPct(float $pct): string
    {
        return rtrim(rtrim(number_format($pct, 4, '.', ''), '0'), '.');
    }
}
