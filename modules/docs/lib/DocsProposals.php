<?php
declare(strict_types=1);

/**
 * Autofill proposals (base_doc_proposals): producers (audio_llm, recordings, import)
 * never write the payload — staff accepts or rejects each value.
 * Record ↔ visit link is computed (operator + time window); base_records is never modified.
 */
final class DocsProposals
{
    public const STATUS_PENDING = 0;
    public const STATUS_ACCEPTED = 1;
    public const STATUS_REJECTED = 2;

    /** Recording counts toward a visit when it starts this close to the visit window. */
    private const WINDOW_SLACK_SEC = 900;
    /** Adjacent recordings closer than this merge into one talk segment. */
    private const MERGE_GAP_SEC = 120;

    public static function tableReady(): bool
    {
        return Database::tableExists('base_doc_proposals') && DocsInstancesRepository::tableReady();
    }

    /**
     * EventBus docs.instance.propose entry point.
     *
     * @param array<string,mixed> $p instance_id | event_id | procedure_id | client_id (+ kind), fields{}, source,
     *                               confidence, study_id, result_id, record_id(s)
     * @return array{ok:bool,instance_id:int,proposed:int,error:string}
     */
    public static function propose(array $p): array
    {
        $out = ['ok' => false, 'instance_id' => 0, 'proposed' => 0, 'error' => ''];
        if (!self::tableReady()) {
            $out['error'] = 'docs tables missing';
            return $out;
        }
        $fields = is_array($p['fields'] ?? null) ? $p['fields'] : [];
        if ($fields === []) {
            $out['error'] = 'no fields';
            return $out;
        }
        $instanceId = self::resolveInstance($p);
        if ($instanceId < 1) {
            $out['error'] = 'target document not found';
            return $out;
        }
        $row = DocsInstancesRepository::find($instanceId);
        if ($row === null || in_array($row['status'], ['printed', 'void'], true)) {
            $out['error'] = 'target document is frozen';
            return $out;
        }
        $ver = DocsDefsRepository::version((int)$row['id_version']);
        $fieldMap = $ver !== null ? DocsSchema::fieldMap($ver['schema']) : [];
        $source = preg_match('/^[a-z_]{1,32}$/', (string)($p['source'] ?? '')) ? (string)$p['source'] : 'import';
        $confidence = max(0.0, min(1.0, (float)($p['confidence'] ?? 0)));
        $meta = [];
        foreach (['study_id', 'result_id', 'record_id'] as $k) {
            if ((int)($p[$k] ?? 0) > 0) {
                $meta[$k] = (int)$p[$k];
            }
        }
        if (isset($p['record_ids']) && is_array($p['record_ids'])) {
            $meta['record_ids'] = array_values(array_map('intval', $p['record_ids']));
        }

        $pdo = Database::pdo();
        $pendingByField = [];
        foreach (self::pendingFor($instanceId) as $pending) {
            $pendingByField[$pending['field_id']][] = DocsSchema::canon($pending['value']);
        }
        $ins = $pdo->prepare(
            'INSERT INTO base_doc_proposals (ID_INSTANCE, FIELD_ID, VALUE_JSON, SOURCE, CONFIDENCE, META_JSON, STATUS, DT_CREATE)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?)'
        );
        foreach ($fields as $fid => $raw) {
            $fid = (string)$fid;
            if (!isset($fieldMap[$fid])) {
                continue;
            }
            $value = DocsSchema::normalizeValue($fieldMap[$fid], $raw);
            if (DocsSchema::isEmpty($fieldMap[$fid], $value)) {
                continue;
            }
            $canon = DocsSchema::canon($value);
            if ($canon === DocsSchema::canon($row['payload'][$fid] ?? null)
                || in_array($canon, $pendingByField[$fid] ?? [], true)
            ) {
                continue;
            }
            $json = (string)json_encode($value, JSON_UNESCAPED_UNICODE);
            $ins->execute([
                $instanceId,
                $fid,
                $json,
                $source,
                $confidence,
                $meta !== [] ? json_encode($meta) : null,
                time(),
            ]);
            $out['proposed']++;
        }
        $out['ok'] = true;
        $out['instance_id'] = $instanceId;
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function pendingFor(int $instanceId): array
    {
        if ($instanceId < 1 || !self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_doc_proposals WHERE ID_INSTANCE = ? AND STATUS = 0 ORDER BY ID ASC LIMIT 100'
        );
        $st->execute([$instanceId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = self::normalize($r);
        }
        return $out;
    }

    /**
     * Accept a pending proposal into its instance payload.
     * $instanceId must be the document currently open (prevents IDOR via a foreign proposal_id).
     */
    public static function accept(int $proposalId, int $instanceId): void
    {
        $p = self::assertProposalForInstance($proposalId, $instanceId);
        $prov = array_merge(['proposal_id' => (int)$p['id']], $p['meta']);
        if ((float)$p['confidence'] > 0) {
            $prov['confidence'] = (float)$p['confidence'];
        }
        $row = DocsInstancesRepository::find($instanceId);
        $ver = $row !== null ? DocsDefsRepository::version((int)$row['id_version']) : null;
        if ($ver === null || !isset(DocsSchema::fieldMap($ver['schema'])[(string)$p['field_id']])) {
            throw new InvalidArgumentException(t('docs.err.proposal_field', ['id' => (string)$p['field_id']]));
        }
        DocsInstancesRepository::updateFields($instanceId, [(string)$p['field_id'] => $p['value']], (string)$p['source'], $prov);
        self::decide($proposalId, self::STATUS_ACCEPTED);
        // Other pending values for the same field are superseded.
        Database::pdo()->prepare(
            'UPDATE base_doc_proposals SET STATUS = ?, DT_DECIDED = ?, ID_ADMIN_DECIDED = ?
             WHERE ID_INSTANCE = ? AND FIELD_ID = ? AND STATUS = 0'
        )->execute([self::STATUS_REJECTED, time(), (int)(Auth::userId() ?? 0), $instanceId, (string)$p['field_id']]);
    }

    /** Reject a pending proposal; $instanceId must own the proposal (same IDOR guard as accept). */
    public static function reject(int $proposalId, int $instanceId): void
    {
        self::assertProposalForInstance($proposalId, $instanceId);
        self::decide($proposalId, self::STATUS_REJECTED);
    }

    /**
     * Proposal must belong to $instanceId and the caller must be allowed to see that document's kind
     * (docs.medical for medcards).
     *
     * @return array<string,mixed>
     */
    private static function assertProposalForInstance(int $proposalId, int $instanceId): array
    {
        if ($instanceId < 1) {
            throw new InvalidArgumentException(t('docs.err.proposal_mismatch'));
        }
        $p = self::findPending($proposalId);
        if ((int)$p['id_instance'] !== $instanceId) {
            throw new InvalidArgumentException(t('docs.err.proposal_mismatch'));
        }
        $row = DocsInstancesRepository::find($instanceId);
        if ($row === null) {
            throw new InvalidArgumentException(t('docs.err.not_found', ['id' => (string)$instanceId]));
        }
        if (!DocsConfig::canSeeKind((string)$row['kind'])) {
            throw new RuntimeException(t('docs.err.no_medical'));
        }
        return $p;
    }

    /**
     * "Time with patient": recordings of the visit operator inside the visit window → segments proposal.
     *
     * @return int proposals created (0 or 1)
     */
    public static function proposeTalkSegments(int $instanceId): int
    {
        $row = DocsInstancesRepository::find($instanceId);
        if ($row === null || (int)$row['id_event'] < 1 || !class_exists('EventsRepository', false)) {
            return 0;
        }
        $ver = DocsDefsRepository::version((int)$row['id_version']);
        $segField = '';
        foreach ($ver !== null ? DocsSchema::fieldMap($ver['schema']) : [] as $fid => $f) {
            if ((string)$f['type'] === 'segments') {
                $segField = $fid;
                break;
            }
        }
        $ev = EventsRepository::find((int)$row['id_event']);
        if ($segField === '' || !$ev) {
            return 0;
        }
        $records = self::recordsForWindow(
            (int)($ev['id_operator'] ?? 0),
            (int)($ev['dtstart'] ?? 0),
            (int)($ev['dtend'] ?? 0)
        );
        if ($records === []) {
            return 0;
        }
        $segments = [];
        $ids = [];
        foreach ($records as $r) {
            $from = (int)$r['dt'];
            $to = $from + max(1, (int)$r['playtime']);
            $ids[] = (int)$r['id'];
            $last = count($segments) - 1;
            if ($last >= 0 && $from - $segments[$last]['to'] <= self::MERGE_GAP_SEC) {
                $segments[$last]['to'] = max($segments[$last]['to'], $to);
                continue;
            }
            $segments[] = ['from' => $from, 'to' => $to];
        }
        $value = [];
        foreach ($segments as $s) {
            $value[] = ['from' => date('H:i', $s['from']), 'to' => date('H:i', $s['to']), 'note' => ''];
        }
        $res = self::propose([
            'instance_id' => $instanceId,
            'fields' => [$segField => $value],
            'source' => 'audio_records',
            'confidence' => 1,
            'record_ids' => $ids,
        ]);
        return (int)$res['proposed'];
    }

    /**
     * audio_llm.study.result listener: RESULT_TEXT with a JSON "docs" block → proposals
     * for the visit of the study procedure, or the visit the record falls into.
     *
     * @param array<string,mixed> $payload study_id, result_id, record_id, result_text
     */
    public static function fromStudyResult(array $payload): int
    {
        $block = self::parseDocsBlock((string)($payload['result_text'] ?? ''));
        if ($block === null) {
            return 0;
        }
        $kind = in_array((string)($block['kind'] ?? ''), DocsDefsRepository::KINDS, true) ? (string)$block['kind'] : 'medcard_visit';
        $req = [
            'kind' => $kind,
            'fields' => is_array($block['fields'] ?? null) ? $block['fields'] : [],
            'source' => 'audio_llm',
            'confidence' => (float)($block['confidence'] ?? 0),
            'study_id' => (int)($payload['study_id'] ?? 0),
            'result_id' => (int)($payload['result_id'] ?? 0),
            'record_id' => (int)($payload['record_id'] ?? 0),
        ];
        $procedureId = self::studyProcedure((int)($payload['study_id'] ?? 0));
        if ($procedureId > 0) {
            $req['procedure_id'] = $procedureId;
        } else {
            $eventId = self::eventForRecord((int)($payload['record_id'] ?? 0));
            if ($eventId < 1) {
                return 0;
            }
            $req['event_id'] = $eventId;
        }
        return (int)self::propose($req)['proposed'];
    }

    /** Visit (with client) of the record's operator whose window contains the recording start. */
    public static function eventForRecord(int $recordId): int
    {
        if ($recordId < 1 || !Database::tableExists('base_records') || !Database::tableExists('base_events')) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT ID_ADMIN, DT_RECORDED FROM base_records WHERE ID = ? LIMIT 1');
        $st->execute([$recordId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['ID_ADMIN'] < 1 || (int)$r['DT_RECORDED'] < 1) {
            return 0;
        }
        $dt = (int)$r['DT_RECORDED'];
        $ev = Database::pdo()->prepare(
            'SELECT ID FROM base_events
             WHERE ID_OPERATOR = ? AND IS_BLOCK = 0 AND ID_USER > 0 AND DTSTART - ? <= ? AND DTEND + ? >= ?
             ORDER BY ABS(DTSTART - ?) ASC LIMIT 1'
        );
        $ev->execute([(int)$r['ID_ADMIN'], self::WINDOW_SLACK_SEC, $dt, self::WINDOW_SLACK_SEC, $dt, $dt]);
        return (int)$ev->fetchColumn();
    }

    /**
     * @return list<array{id:int,dt:int,playtime:int}>
     */
    private static function recordsForWindow(int $operatorId, int $start, int $end): array
    {
        if ($operatorId < 1 || $start < 1 || !Database::tableExists('base_records')) {
            return [];
        }
        $end = max($end, $start);
        $st = Database::pdo()->prepare(
            'SELECT ID, DT_RECORDED, PLAYTIME FROM base_records
             WHERE ID_ADMIN = ? AND DISABLED = 0 AND DT_RECORDED BETWEEN ? AND ?
             ORDER BY DT_RECORDED ASC, ID ASC LIMIT 200'
        );
        $st->execute([$operatorId, $start - self::WINDOW_SLACK_SEC, $end + self::WINDOW_SLACK_SEC]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = ['id' => (int)$r['ID'], 'dt' => (int)$r['DT_RECORDED'], 'playtime' => (int)$r['PLAYTIME']];
        }
        return $out;
    }

    /**
     * Accepts raw JSON or a fenced ```json block; returns the "docs" object.
     *
     * @return array<string,mixed>|null
     */
    public static function parseDocsBlock(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || strpos($text, '"docs"') === false) {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
            $text = $m[1];
        } elseif (($a = strpos($text, '{')) !== false && ($b = strrpos($text, '}')) !== false && $b > $a) {
            $text = substr($text, $a, $b - $a + 1);
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded) || !is_array($decoded['docs'] ?? null)) {
            return null;
        }
        return $decoded['docs'];
    }

    /** @param array<string,mixed> $p */
    private static function resolveInstance(array $p): int
    {
        $id = (int)($p['instance_id'] ?? 0);
        if ($id > 0) {
            return DocsInstancesRepository::find($id) !== null ? $id : 0;
        }
        $kind = (string)($p['kind'] ?? 'medcard_visit');
        $procedureId = (int)($p['procedure_id'] ?? 0);
        $eventId = (int)($p['event_id'] ?? 0);
        if ($procedureId > 0) {
            $hit = DocsInstancesRepository::list(['procedure' => $procedureId, 'kind' => $kind, 'limit' => 1])['items'][0] ?? null;
            if ($hit !== null) {
                return (int)$hit['id'];
            }
            if ($eventId < 1 && class_exists('ProceduresRepository', false)) {
                $pr = ProceduresRepository::find($procedureId);
                $eventId = $pr ? (int)($pr['id_event_parent'] ?? 0) : 0;
            }
        }
        if ($eventId > 0) {
            foreach (DocsInstancesRepository::listForEvent($eventId) as $inst) {
                if ($inst['kind'] === $kind && !in_array($inst['status'], ['printed', 'void'], true)) {
                    return (int)$inst['id'];
                }
            }
            // No sheet yet: create the shell on the visit so the proposal has a home.
            $def = DocsDefsRepository::firstOfKind($kind);
            $ev = class_exists('EventsRepository', false) ? EventsRepository::find($eventId) : null;
            if ($def !== null && $def['scope'] === 'event' && $ev && (int)($ev['id_user'] ?? 0) > 0) {
                return DocsInstancesRepository::create($def, [
                    'client' => (int)$ev['id_user'],
                    'event' => $eventId,
                    'procedure' => $procedureId,
                    'source' => 'spawn',
                ]);
            }
            return 0;
        }
        $clientId = (int)($p['client_id'] ?? 0);
        if ($clientId > 0) {
            if ($kind === 'medcard_profile') {
                return (int)(DocsSpawner::ensureProfile($clientId) ?? 0);
            }
            $hit = DocsInstancesRepository::latestForClient($clientId, $kind);
            return $hit !== null ? (int)$hit['id'] : 0;
        }
        return 0;
    }

    private static function studyProcedure(int $studyId): int
    {
        if ($studyId < 1 || !Database::tableExists('base_records_llm_studies')) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT ID_PROCEDURE FROM base_records_llm_studies WHERE ID = ? LIMIT 1');
        $st->execute([$studyId]);
        return (int)$st->fetchColumn();
    }

    /** @return array<string,mixed> */
    private static function findPending(int $proposalId): array
    {
        $st = Database::pdo()->prepare('SELECT * FROM base_doc_proposals WHERE ID = ? AND STATUS = 0 LIMIT 1');
        $st->execute([$proposalId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            throw new InvalidArgumentException(t('docs.err.proposal_not_found'));
        }
        return self::normalize($r);
    }

    private static function decide(int $proposalId, int $status): void
    {
        Database::pdo()->prepare(
            'UPDATE base_doc_proposals SET STATUS = ?, DT_DECIDED = ?, ID_ADMIN_DECIDED = ? WHERE ID = ?'
        )->execute([$status, time(), (int)(Auth::userId() ?? 0), $proposalId]);
    }

    /** @param array<string,mixed> $r */
    private static function normalize(array $r): array
    {
        $meta = json_decode((string)($r['META_JSON'] ?? ''), true);
        return [
            'id' => (int)$r['ID'],
            'id_instance' => (int)$r['ID_INSTANCE'],
            'field_id' => (string)$r['FIELD_ID'],
            'value' => json_decode((string)($r['VALUE_JSON'] ?? 'null'), true),
            'source' => (string)$r['SOURCE'],
            'confidence' => (float)$r['CONFIDENCE'],
            'meta' => is_array($meta) ? $meta : [],
            'status' => (int)$r['STATUS'],
            'dt_create' => (int)$r['DT_CREATE'],
        ];
    }
}
