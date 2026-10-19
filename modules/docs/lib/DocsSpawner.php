<?php
declare(strict_types=1);

/**
 * Creates draft document shells for a visit by definition triggers
 * (legacy getDocs on event save) and the client's standing medcard profile.
 */
final class DocsSpawner
{
    /** @var array<int,bool> */
    private static $medicalTypeCache = [];

    /**
     * @return list<int> created instance ids
     */
    public static function ensureForEvent(int $eventId): array
    {
        if ($eventId < 1 || !DocsInstancesRepository::tableReady() || !class_exists('EventsRepository', false)) {
            return [];
        }
        $ev = EventsRepository::find($eventId);
        if (!$ev || !empty($ev['is_block'])) {
            return [];
        }
        $clientId = (int)($ev['id_user'] ?? 0);
        if ($clientId < 1) {
            return [];
        }
        DocsDefsRepository::ensureSeeded();
        $goods = [];
        foreach ((array)($ev['items'] ?? []) as $it) {
            $gid = (int)($it['id_good'] ?? 0);
            if ($gid > 0) {
                $goods[] = $gid;
            }
        }
        $types = self::typeIdsForGoods($goods);
        $hasMedical = false;
        foreach ($types as $typeId) {
            if (self::isMedicalType($typeId)) {
                $hasMedical = true;
                break;
            }
        }

        $created = [];
        $now = time();
        foreach (DocsDefsRepository::listAll(true) as $def) {
            if (!self::matches($def, $goods, $types, $hasMedical)) {
                continue;
            }
            if ($def['scope'] === 'client') {
                if (DocsInstancesRepository::findValidForClient((int)$def['id'], $clientId, $now) !== null) {
                    continue;
                }
            } elseif (DocsInstancesRepository::findForEventDef($eventId, (int)$def['id']) !== null) {
                continue;
            }
            $procedureId = $def['kind'] === 'medcard_visit' ? self::singleMedicalProcedure($eventId) : 0;
            $payload = [];
            if ($def['kind'] === 'medcard_visit' && (int)($ev['id_operator'] ?? 0) > 0) {
                $payload['doctor_id'] = (int)$ev['id_operator'];
            }
            $created[] = DocsInstancesRepository::create($def, [
                'client' => $clientId,
                'event' => $eventId,
                'procedure' => $procedureId,
                'payload' => $payload,
                'source' => 'spawn',
            ]);
        }

        // Visit sheet created before createFromEvent: attach the journal row once it exists.
        foreach (DocsInstancesRepository::listForEvent($eventId) as $inst) {
            if ($inst['kind'] === 'medcard_visit' && (int)$inst['id_procedure'] === 0) {
                $pid = self::singleMedicalProcedure($eventId);
                if ($pid > 0) {
                    DocsInstancesRepository::setProcedure((int)$inst['id'], $pid);
                }
            }
        }
        return $created;
    }

    /** Current medcard profile for a client (created on first open). */
    public static function ensureProfile(int $clientId): ?int
    {
        if ($clientId < 1 || !DocsInstancesRepository::tableReady()) {
            return null;
        }
        DocsDefsRepository::ensureSeeded();
        $cur = DocsInstancesRepository::latestForClient($clientId, 'medcard_profile');
        if ($cur !== null) {
            return (int)$cur['id'];
        }
        $def = DocsDefsRepository::firstOfKind('medcard_profile');
        if ($def === null) {
            return null;
        }
        return DocsInstancesRepository::create($def, ['client' => $clientId, 'source' => 'spawn']);
    }

    /** True when the visit has a medcard sheet that is not filled yet (print gate). */
    public static function eventMedcardBlocking(int $eventId): bool
    {
        if ($eventId < 1) {
            return false;
        }
        foreach (DocsInstancesRepository::listForEvent($eventId) as $inst) {
            if ($inst['kind'] === 'medcard_visit' && !in_array($inst['status'], ['ready', 'printed'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $def
     * @param list<int> $goods
     * @param list<int> $types
     */
    private static function matches(array $def, array $goods, array $types, bool $hasMedical): bool
    {
        if ($def['kind'] === 'medcard_profile' || $def['kind'] === 'medcard_dossier') {
            return false;
        }
        switch ((string)$def['trigger_mode']) {
            case 'every':
                return true;
            case 'medical':
                return $hasMedical;
            case 'non_medical':
                return !$hasMedical;
            case 'types':
                return array_intersect($def['trigger_types'], $types) !== [];
            case 'procs':
                return array_intersect($def['trigger_procs'], $goods) !== [];
            default:
                return false;
        }
    }

    /**
     * @param list<int> $goods
     * @return list<int>
     */
    private static function typeIdsForGoods(array $goods): array
    {
        $types = [];
        if (!class_exists('ProductsRepository', false)) {
            return [];
        }
        foreach ($goods as $gid) {
            $p = ProductsRepository::find($gid);
            $tid = $p ? (int)($p['type_id'] ?? 0) : 0;
            if ($tid > 0 && !in_array($tid, $types, true)) {
                $types[] = $tid;
            }
        }
        return $types;
    }

    public static function isMedicalType(int $typeId): bool
    {
        if ($typeId < 1 || !class_exists('ProcedureTypesRepository', false)) {
            return false;
        }
        if (!array_key_exists($typeId, self::$medicalTypeCache)) {
            $t = ProcedureTypesRepository::find($typeId);
            self::$medicalTypeCache[$typeId] = $t !== null && !empty($t['is_medical']);
        }
        return self::$medicalTypeCache[$typeId];
    }

    /** Journal row of the visit when exactly one medical procedure exists. */
    private static function singleMedicalProcedure(int $eventId): int
    {
        if (!class_exists('ProceduresRepository', false)) {
            return 0;
        }
        $found = [];
        foreach (ProceduresRepository::listByEventParent($eventId) as $pr) {
            $types = self::typeIdsForGoods([(int)($pr['id_good'] ?? 0)]);
            if ($types !== [] && self::isMedicalType($types[0])) {
                $found[] = (int)$pr['id'];
            }
        }
        return count($found) === 1 ? $found[0] : 0;
    }
}
