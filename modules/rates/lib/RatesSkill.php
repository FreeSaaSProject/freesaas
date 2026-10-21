<?php
declare(strict_types=1);

/**
 * Diagnose / grant skill (procedure type) via rates matrix + ban flags.
 */
final class RatesSkill
{
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
            $row = RatesRepository::latestForOperator($operatorId, $dayStart);
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
        $dayStart = time();

        $row = RatesRepository::latestForOperator($operatorId, $dayStart);
        $content = is_array($row['content'] ?? null) ? ($row['content'] ?? []) : [];
        $banBooking = is_array($row['ban_booking'] ?? null) ? ($row['ban_booking'] ?? []) : [];
        $banOnline = is_array($row['ban_online'] ?? null) ? ($row['ban_online'] ?? []) : [];
        $existingPct = null;
        $rawExisting = $content[$typeId] ?? $content[(string)$typeId] ?? null;
        if ($rawExisting !== null && is_numeric($rawExisting) && (float)$rawExisting > 0) {
            $existingPct = (float)$rawExisting;
        }
        $wasBanned = !empty($banBooking[$typeId]) || !empty($banBooking[(string)$typeId])
            || ($clearOnline && (!empty($banOnline[$typeId]) || !empty($banOnline[(string)$typeId])));

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
                'percent' => $pct,
                'source' => $source,
                'dry_run' => $dryRun,
            ];
        }

        if ($existingPct !== null && $wasBanned && ($explicitPct === null || abs($explicitPct - $existingPct) < 0.0001)) {
            $message = t('rates.grant.was_banned', [
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
                'percent' => $pct,
                'source' => $source,
                'dry_run' => true,
                'would_clear_ban_booking' => true,
                'would_clear_ban_online' => $clearOnline,
            ];
        }

        $data = self::buildGrantRow($operatorId, $row, $typeId, $pct, $clearOnline);
        // DT = start of today so BookingOperatorGate (DT <= dayStart midnight) sees it immediately.
        $data['dt'] = (int)mktime(0, 0, 0);
        $data['descr'] = self::grantDescr($typeLabel, $pct);
        $newId = RatesRepository::create($data);

        return [
            'ok' => true,
            'changed' => true,
            'message' => $message,
            'operator_id' => $operatorId,
            'type_id' => $typeId,
            'percent' => $pct,
            'source' => $source,
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
    private static function buildGrantRow(int $operatorId, ?array $row, int $typeId, float $pct, bool $clearOnline): array
    {
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

        $typeLabel = ProcedureTypesRepository::names()[$typeId] ?? ('#' . $typeId);
        return [
            'operator_id' => $operatorId,
            'dt' => (int)mktime(0, 0, 0),
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
