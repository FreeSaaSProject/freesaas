<?php
declare(strict_types=1);

final class RecordsKeysRepository
{
    /** @var list<array{id:int,name:string,id_dictonary:int,dict_name:string,norm:string}>|null */
    private static $overlapCatalog = null;

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return ['id' => 'ID', 'name' => 'NAME', 'disabled' => 'DISABLED', 'dict' => 'ID_DICTONARY'];
    }

    /**
     * @param array{q?:string,dict?:int,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_records_keys')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(k.NAME LIKE ? OR CAST(k.ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $dict = (int)($opts['dict'] ?? 0);
        if ($dict > 0) {
            $where[] = 'k.ID_DICTONARY = ?';
            $params[] = $dict;
        }
        $hasRepl = self::hasReplaceColumns();
        if ($hasRepl) {
            $rf = (string)($opts['replace'] ?? '');
            if ($rf === 'variants') {
                $where[] = 'k.ID_REPLACE > 0';
            } elseif ($rf === 'canonical') {
                $where[] = 'EXISTS (SELECT 1 FROM base_records_keys c WHERE c.ID_REPLACE = k.ID)';
            } elseif ($rf === 'review') {
                $where[] = "k.REPLACE_STATE IN ('pending','suggested')";
            } elseif ($rf === 'none') {
                $where[] = "k.REPLACE_STATE = ''";
            } elseif (in_array($rf, ['pending', 'suggested', 'approved', 'keep', 'rejected'], true)) {
                $where[] = 'k.REPLACE_STATE = ?';
                $params[] = $rf;
            }
            $of = (int)($opts['replace_of'] ?? 0);
            if ($of > 0) {
                $where[] = 'k.ID_REPLACE = ?';
                $params[] = $of;
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_records_keys k WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'k.ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $replSel = self::replaceSelect('k.')
            . ($hasRepl ? ', rk.NAME AS replace_name' : ", '' AS replace_name");
        $variantSel = $hasRepl
            ? ', (SELECT COUNT(*) FROM base_records_keys c WHERE c.ID_REPLACE = k.ID AND c.ID <> k.ID) AS variant_count'
            : ', 0 AS variant_count';
        $replJoin = $hasRepl ? ' LEFT JOIN base_records_keys rk ON rk.ID = k.ID_REPLACE AND k.ID_REPLACE > 0' : '';
        $sql = "SELECT k.ID AS id, k.DT_CREATE AS dt_create, k.NAME AS name, k.DISABLED AS disabled,
                       k.ID_DICTONARY AS id_dictonary, k.ID_LOOKLIKE AS id_looklike,
                       d.NAME AS dict_name{$replSel}{$variantSel}
                FROM base_records_keys k
                LEFT JOIN base_records_dictonarys d ON d.ID = k.ID_DICTONARY{$replJoin}
                WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_records_keys')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_CREATE AS dt_create, NAME AS name, DISABLED AS disabled,
                    ID_DICTONARY AS id_dictonary, ID_LOOKLIKE AS id_looklike' . self::replaceSelect() . '
             FROM base_records_keys WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $post @return array<string,mixed> */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('audio_dicts.err.name_required'));
        }
        $data = [
            'name' => mb_substr($name, 0, 255),
            'disabled' => !empty($post['disabled']) ? 1 : 0,
            'id_dictonary' => max(0, (int)($post['id_dictonary'] ?? 0)),
            'id_looklike' => max(0, (int)($post['id_looklike'] ?? 0)),
        ];
        // Replacement is part of the save only when the form/agent sent it (absent = keep link).
        if (array_key_exists('replace_text', $post) || array_key_exists('id_replace', $post)) {
            $data['replace_provided'] = true;
            $data['replace_text'] = mb_substr(trim((string)($post['replace_text'] ?? '')), 0, 255);
            $data['id_replace'] = max(0, (int)($post['id_replace'] ?? 0));
        }

        return $data;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $dictId = (int)$data['id_dictonary'];
        $replaceId = self::replaceTarget($data, 0, $dictId) ?? 0;
        // Explicit analysis state (e.g. 'suggested' / 'pending' from add-key UI) when no target is set.
        $replaceState = $replaceId > 0 ? 'approved' : (string)($data['replace_state'] ?? '');
        $suggest = $replaceId > 0 ? '' : mb_substr((string)($data['replace_suggest'] ?? ''), 0, 255);
        $hasRepl = self::hasReplaceColumns();
        $cols = $hasRepl ? ', ID_REPLACE, REPLACE_STATE, REPLACE_SUGGEST' : '';
        $marks = $hasRepl ? ',?,?,?' : '';
        $st = Database::pdo()->prepare(
            "INSERT INTO base_records_keys (DT_CREATE, NAME, DISABLED, ID_DICTONARY, ID_LOOKLIKE{$cols})
             VALUES (?,?,?,?,?{$marks})"
        );
        $params = [
            time(), $data['name'], $data['disabled'],
            $data['id_dictonary'], $data['id_looklike'],
        ];
        if ($hasRepl) {
            $params[] = $replaceId;
            $params[] = $replaceState;
            $params[] = $suggest;
        }
        $st->execute($params);
        // Listeners run their own queries and reset the connection's insert id.
        $id = (int)Database::pdo()->lastInsertId();
        if ($replaceId > 0) {
            self::dropReviewState($replaceId);
        }
        self::clearOverlapCatalogCache();
        if (class_exists('KeyStemConflict', false)) {
            KeyStemConflict::clearCache();
        }
        EventBus::emit('audio_dicts.changed', ['entity' => 'keys', 'id' => $id]);
        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data, string $onDependents = ''): void
    {
        $cur = self::find($id);
        $dictId = (int)$data['id_dictonary'];
        if ($cur !== null && (int)$cur['id_dictonary'] !== $dictId && self::dependentIds($id) !== []) {
            throw new InvalidArgumentException(t('audio_dicts.replace.err.dict_change'));
        }
        $replaceId = self::replaceTarget($data, $id, $dictId);
        $dictChanged = $cur !== null && (int)$cur['id_dictonary'] !== $dictId;
        if ($replaceId === null && $dictChanged && (int)($cur['id_replace'] ?? 0) > 0) {
            // Variant moved to another dictionary: its canonical lives in the old one, unlink.
            $replaceId = 0;
        }
        $sets = 'NAME=?, DISABLED=?, ID_DICTONARY=?, ID_LOOKLIKE=?';
        $params = [$data['name'], $data['disabled'], $data['id_dictonary'], $data['id_looklike']];
        $cascadeIds = [];
        if (!empty($data['disabled']) && $cur !== null && empty($cur['disabled'])) {
            $cascadeIds = self::applyDependentsPolicy([$id], $onDependents);
        }
        if ($replaceId !== null && self::hasReplaceColumns()) {
            $keepState = in_array((string)($cur['replace_state'] ?? ''), ['keep', 'rejected'], true)
                ? (string)$cur['replace_state'] : '';
            $sets .= ', ID_REPLACE=?, REPLACE_STATE=?, REPLACE_SUGGEST=?';
            $params[] = $replaceId;
            $params[] = $replaceId > 0 ? 'approved' : $keepState;
            $params[] = '';
        }
        $params[] = $id;
        $st = Database::pdo()->prepare("UPDATE base_records_keys SET {$sets} WHERE ID=?");
        $st->execute($params);
        if ($replaceId !== null && $replaceId > 0) {
            self::dropReviewState($replaceId);
        }
        if ($cascadeIds !== []) {
            self::setDisabledByIds($cascadeIds, true);
        }
        self::clearOverlapCatalogCache();
        if (class_exists('KeyStemConflict', false)) {
            KeyStemConflict::clearCache();
        }
        EventBus::emit('audio_dicts.changed', ['entity' => 'keys', 'id' => $id]);
    }

    /**
     * A key that became canonical must not keep its own unaccepted replacement review
     * (pending/suggested), otherwise accepting it would build a two-level chain.
     */
    public static function dropReviewState(int $keyId): void
    {
        if ($keyId < 1 || !self::hasReplaceColumns()) {
            return;
        }
        $st = Database::pdo()->prepare(
            "UPDATE base_records_keys SET REPLACE_STATE = '', REPLACE_SUGGEST = ''
             WHERE ID = ? AND ID_REPLACE = 0 AND REPLACE_STATE IN ('pending', 'suggested')"
        );
        $st->execute([$keyId]);
    }

    public static function findByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '' || !Database::tableExists('base_records_keys')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_CREATE AS dt_create, NAME AS name, DISABLED AS disabled,
                    ID_DICTONARY AS id_dictonary, ID_LOOKLIKE AS id_looklike' . self::replaceSelect() . '
             FROM base_records_keys WHERE NAME = ? LIMIT 1'
        );
        $st->execute([mb_substr($name, 0, 255)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Normalize for overlap compare: lower case, ё→е, strip punctuation, collapse spaces.
     */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        if ($name === '') {
            return '';
        }
        $name = str_replace(['ё', 'Ё'], ['е', 'е'], $name);
        $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        return trim($name);
    }

    /**
     * Find keys that equal / contain / are contained in $name (broader than exact NAME=).
     * Oper-style nesting guard: new key must not sit inside another key and vice versa.
     *
     * @return list<array{
     *   id:int,name:string,id_dictonary:int,dict_name:string,
     *   relation:string,relation_label:string
     * }>
     */
    public static function findOverlaps(string $name, int $excludeId = 0, int $limit = 40): array
    {
        $needle = self::normalizeName($name);
        if ($needle === '' || !Database::tableExists('base_records_keys')) {
            return [];
        }
        $needleLen = mb_strlen($needle, 'UTF-8');
        if ($needleLen < 2) {
            return [];
        }

        if (self::$overlapCatalog === null) {
            self::$overlapCatalog = [];
            $sql = 'SELECT k.ID AS id, k.NAME AS name, k.ID_DICTONARY AS id_dictonary,
                           d.NAME AS dict_name
                    FROM base_records_keys k
                    LEFT JOIN base_records_dictonarys d ON d.ID = k.ID_DICTONARY
                    WHERE k.DISABLED < 1
                    LIMIT 12000';
            $st = Database::pdo()->query($sql);
            if ($st) {
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $norm = self::normalizeName((string)$row['name']);
                    if ($norm === '') {
                        continue;
                    }
                    self::$overlapCatalog[] = [
                        'id' => (int)$row['id'],
                        'name' => (string)$row['name'],
                        'id_dictonary' => (int)$row['id_dictonary'],
                        'dict_name' => (string)($row['dict_name'] ?? ''),
                        'norm' => $norm,
                    ];
                }
            }
        }

        $exact = [];
        $contains = []; // existing contains candidate
        $contained = []; // candidate contains existing
        foreach (self::$overlapCatalog as $row) {
            $id = (int)$row['id'];
            if ($excludeId > 0 && $id === $excludeId) {
                continue;
            }
            $other = (string)$row['norm'];
            $relation = '';
            if ($other === $needle) {
                $relation = 'exact';
            } elseif (mb_strpos($other, $needle, 0, 'UTF-8') !== false) {
                $relation = 'contains'; // other key is wider
            } elseif (mb_strpos($needle, $other, 0, 'UTF-8') !== false) {
                // Avoid trivial short fragments inside long phrases (1–2 chars already blocked).
                if (mb_strlen($other, 'UTF-8') >= 3) {
                    $relation = 'contained'; // new key is wider
                }
            }
            if ($relation === '') {
                continue;
            }
            $hit = [
                'id' => $id,
                'name' => (string)$row['name'],
                'id_dictonary' => (int)$row['id_dictonary'],
                'dict_name' => (string)$row['dict_name'],
                'relation' => $relation,
            ];
            if ($relation === 'exact') {
                $exact[] = $hit;
            } elseif ($relation === 'contains') {
                $contains[] = $hit;
            } else {
                $contained[] = $hit;
            }
            if (count($exact) + count($contains) + count($contained) >= $limit) {
                break;
            }
        }

        // Prefer exact, then "existing is wider", then "new is wider".
        return array_slice(array_merge($exact, $contains, $contained), 0, $limit);
    }

    /**
     * Soft nesting only: every hit is "contains" (an existing key already covers
     * this phrase as a substring). Safe to create without force — same rule as
     * coach apply / ASR short forms. Empty list counts as soft.
     *
     * @param list<array{relation?:string}> $overlaps
     */
    public static function isSoftContainsOnly(array $overlaps): bool
    {
        if ($overlaps === []) {
            return true;
        }
        foreach ($overlaps as $ov) {
            if ((string)($ov['relation'] ?? '') !== 'contains') {
                return false;
            }
        }

        return true;
    }

    /** ID_REPLACE / REPLACE_STATE exist (audio_dicts migration 014). */
    public static function hasReplaceColumns(): bool
    {
        return Database::columnExists('base_records_keys', 'ID_REPLACE')
            && Database::columnExists('base_records_keys', 'REPLACE_STATE')
            && Database::columnExists('base_records_keys', 'REPLACE_SUGGEST');
    }

    /** SELECT fragment with replacement columns (leading comma); zeros before the migration. */
    private static function replaceSelect(string $alias = ''): string
    {
        if (!self::hasReplaceColumns()) {
            return ", 0 AS id_replace, '' AS replace_state, '' AS replace_suggest";
        }

        return ", {$alias}ID_REPLACE AS id_replace, {$alias}REPLACE_STATE AS replace_state"
            . ", {$alias}REPLACE_SUGGEST AS replace_suggest";
    }

    /** Variant keys (ID_REPLACE points at $canonicalId). @return list<int> */
    public static function dependentIds(int $canonicalId): array
    {
        if ($canonicalId < 1 || !self::hasReplaceColumns()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_records_keys WHERE ID_REPLACE = ? AND ID <> ? ORDER BY ID'
        );
        $st->execute([$canonicalId, $canonicalId]);

        return array_values(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    /**
     * Canonical keys among $ids that still have variants outside $ids.
     *
     * @param list<int> $ids
     * @return array<int,list<int>> canonical id => variant ids
     */
    public static function dependentsOf(array $ids): array
    {
        $out = [];
        foreach ($ids as $cid) {
            $deps = array_values(array_diff(self::dependentIds((int)$cid), $ids));
            if ($deps !== []) {
                $out[(int)$cid] = $deps;
            }
        }

        return $out;
    }

    /**
     * Bulk guard: ids whose disable/delete needs an explicit variant policy (cascade|detach).
     *
     * @param list<int> $ids
     * @return array<int,string> id => reason
     */
    public static function dependentBlockers(array $ids): array
    {
        $out = [];
        foreach (self::dependentsOf($ids) as $cid => $deps) {
            $out[$cid] = t('audio_dicts.replace.err.has_variants', ['n' => count($deps)]);
        }

        return $out;
    }

    /**
     * Apply the policy for variants of canonical keys about to be disabled/deleted.
     * cascade: returns variant ids to run the same operation on (variants do not work without it).
     * detach: unlinks variants (ID_REPLACE=0), returns []. No choice: throws.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private static function applyDependentsPolicy(array $ids, string $mode): array
    {
        $map = self::dependentsOf($ids);
        if ($map === []) {
            return [];
        }
        $all = array_values(array_unique(array_merge(...array_values($map))));
        if ($mode === 'cascade') {
            return $all;
        }
        if ($mode === 'detach') {
            $in = implode(',', array_fill(0, count($all), '?'));
            $st = Database::pdo()->prepare(
                "UPDATE base_records_keys SET ID_REPLACE = 0, REPLACE_STATE = '' WHERE ID IN ($in)"
            );
            $st->execute($all);

            return [];
        }
        throw new InvalidArgumentException(t('audio_dicts.replace.err.has_variants', ['n' => count($all)]));
    }

    /** @param list<int> $ids @return list<int> */
    private static function withDependents(array $ids, string $mode): array
    {
        return array_values(array_unique(array_merge($ids, self::applyDependentsPolicy($ids, $mode))));
    }

    /** Lang key of a bad ID_REPLACE target, or '' when valid. */
    private static function replaceError(int $selfId, int $dictId, int $replaceId): string
    {
        if ($replaceId < 1) {
            return '';
        }
        if ($replaceId === $selfId) {
            return 'audio_dicts.replace.err.self';
        }
        $c = self::find($replaceId);
        if ($c === null) {
            return 'audio_dicts.replace.err.not_found';
        }
        if (!empty($c['disabled'])) {
            return 'audio_dicts.replace.err.disabled';
        }
        if ((int)$c['id_dictonary'] !== $dictId) {
            return 'audio_dicts.replace.err.dict';
        }
        if ((int)($c['id_replace'] ?? 0) > 0) {
            return 'audio_dicts.replace.err.chain';
        }
        if ($selfId > 0 && self::dependentIds($selfId) !== []) {
            return 'audio_dicts.replace.err.is_canonical';
        }

        return '';
    }

    /**
     * Enabled key with this exact name in the dictionary, or a new one (typed replacement).
     */
    public static function resolveReplaceText(int $dictId, string $text): int
    {
        $name = mb_substr(trim($text), 0, 255);
        if ($name === '' || $dictId < 1) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_records_keys WHERE NAME = ? AND ID_DICTONARY = ? AND DISABLED < 1 ORDER BY ID LIMIT 1'
        );
        $st->execute([$name, $dictId]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            return $id;
        }

        return self::create([
            'name' => $name, 'disabled' => 0, 'id_dictonary' => $dictId, 'id_looklike' => 0,
        ]);
    }

    /**
     * Target id from data (replace_text resolves by name in the same dictionary).
     * Returns null when the caller did not send replacement fields (keep link as is).
     *
     * @param array<string,mixed> $data
     */
    private static function replaceTarget(array $data, int $selfId, int $dictId): ?int
    {
        if (empty($data['replace_provided'])) {
            return null;
        }
        $id = (int)($data['id_replace'] ?? 0);
        $text = trim((string)($data['replace_text'] ?? ''));
        if ($id < 1 && $text !== '') {
            $id = self::resolveReplaceText($dictId, $text);
        }
        $err = self::replaceError($selfId, $dictId, $id);
        if ($err !== '') {
            throw new InvalidArgumentException(t($err));
        }

        return $id;
    }

    /**
     * Canonical candidates for the replacement input: enabled keys of the dictionary that are not variants.
     *
     * @return list<array{id:int,name:string}>
     */
    public static function canonicalCandidates(int $dictId, int $selfId = 0, int $limit = 300): array
    {
        if ($dictId < 1 || !self::hasReplaceColumns()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, NAME AS name FROM base_records_keys
             WHERE ID_DICTONARY = ? AND DISABLED < 1 AND ID_REPLACE = 0 AND ID <> ?
             ORDER BY NAME LIMIT ' . max(1, $limit)
        );
        $st->execute([$dictId, $selfId]);

        return array_map(
            static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']],
            $st->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /** Clear request-local overlap catalog (after create/update). */
    public static function clearOverlapCatalogCache(): void
    {
        self::$overlapCatalog = null;
    }

    public static function setDisabled(int $id, bool $disabled, string $onDependents = ''): void
    {
        if ($id < 1 || !Database::tableExists('base_records_keys')) {
            return;
        }
        if ($disabled) {
            self::setDisabledByIds([$id], true, $onDependents);
            return;
        }
        $st = Database::pdo()->prepare('UPDATE base_records_keys SET DISABLED = ? WHERE ID = ?');
        $st->execute([$disabled ? 1 : 0, $id]);
        self::clearOverlapCatalogCache();
        if (class_exists('KeyStemConflict', false)) {
            KeyStemConflict::clearCache();
        }
        EventBus::emit('audio_dicts.changed', ['entity' => 'keys', 'id' => $id]);
    }

    /**
     * @param list<int> $ids
     */
    public static function setDisabledByIds(array $ids, bool $disabled, string $onDependents = ''): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
        if ($ids === [] || !Database::tableExists('base_records_keys')) {
            return 0;
        }
        if ($disabled) {
            $ids = self::withDependents($ids, $onDependents);
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$disabled ? 1 : 0], $ids);
        $st = Database::pdo()->prepare("UPDATE base_records_keys SET DISABLED = ? WHERE ID IN ($in)");
        $st->execute($params);
        self::clearOverlapCatalogCache();
        EventBus::emit('audio_dicts.changed', ['entity' => 'keys']);

        return $st->rowCount();
    }

    /** Analysis states that a human or the LLM may set on a key without a link. */
    public const REPLACE_STATES = ['', 'pending', 'suggested', 'approved', 'keep', 'rejected'];

    /**
     * Set analysis state (pending/keep/rejected) on keys that have no approved link and are not canonical.
     *
     * @param list<int> $ids
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function setReplaceStateByIds(array $ids, string $state): array
    {
        $out = ['done' => 0, 'skipped' => []];
        if (!self::hasReplaceColumns() || !in_array($state, ['pending', 'keep', 'rejected'], true)) {
            return $out;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        foreach ($ids as $id) {
            $row = self::find($id);
            if ($row === null) {
                $out['skipped'][] = ['id' => $id, 'reason' => t('audio_dicts.err.not_found', ['id' => $id])];
                continue;
            }
            if ((int)$row['id_replace'] > 0) {
                $out['skipped'][] = ['id' => $id, 'reason' => t('audio_dicts.replace.skip.has_link')];
                continue;
            }
            if (self::dependentIds($id) !== []) {
                $out['skipped'][] = ['id' => $id, 'reason' => t('audio_dicts.replace.skip.canonical')];
                continue;
            }
            $st = Database::pdo()->prepare(
                'UPDATE base_records_keys SET REPLACE_STATE = ?, REPLACE_SUGGEST = ? WHERE ID = ?'
            );
            $st->execute([$state, '', $id]);
            $out['done']++;
        }
        self::clearOverlapCatalogCache();
        EventBus::emit('audio_dicts.changed', ['entity' => 'keys']);

        return $out;
    }

    /**
     * Persist an LLM/heuristic analysis result (no link is written here; acceptance is manual).
     */
    public static function setReplaceAnalysis(int $id, string $state, string $suggest): void
    {
        if ($id < 1 || !self::hasReplaceColumns() || !in_array($state, self::REPLACE_STATES, true)) {
            return;
        }
        // Canonical keys (someone links to them) are never reviewed for replacement.
        if (in_array($state, ['pending', 'suggested'], true) && self::dependentIds($id) !== []) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_records_keys SET REPLACE_STATE = ?, REPLACE_SUGGEST = ? WHERE ID = ? AND ID_REPLACE = 0'
        );
        $st->execute([$state, mb_substr($suggest, 0, 255), $id]);
        EventBus::emit('audio_dicts.changed', ['entity' => 'keys', 'id' => $id]);
    }

    /**
     * Link a key to a canonical key (by id, or by name in the same dictionary) with the usual invariants.
     *
     * @throws InvalidArgumentException when the key is missing or the link breaks an invariant
     */
    public static function setReplacement(int $id, int $replaceId, string $replaceText = ''): void
    {
        $row = $id > 0 ? self::find($id) : null;
        if ($row === null) {
            throw new InvalidArgumentException(t('audio_dicts.err.not_found', ['id' => $id]));
        }
        if ($replaceId < 1 && trim($replaceText) === '') {
            // Never clear a link/suggestion through this helper; use the edit form for that.
            throw new InvalidArgumentException(t('audio_dicts.replace.skip.no_suggest'));
        }
        $data = [
            'name' => (string)$row['name'],
            'disabled' => (int)$row['disabled'],
            'id_dictonary' => (int)$row['id_dictonary'],
            'id_looklike' => (int)$row['id_looklike'],
        ];
        if ($replaceId > 0) {
            $data['id_replace'] = $replaceId;
        } else {
            $data['replace_text'] = $replaceText;
        }
        self::update($id, self::dataFromPost($data));
    }

    /**
     * Accept stored suggestions (state suggested) for the given keys: link to the suggested canonical key.
     *
     * @param list<int> $ids
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function acceptSuggestionsByIds(array $ids): array
    {
        $out = ['done' => 0, 'skipped' => []];
        if (!self::hasReplaceColumns()) {
            return $out;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        foreach ($ids as $id) {
            $row = self::find($id);
            if ($row === null || (string)$row['replace_state'] !== 'suggested' || (string)$row['replace_suggest'] === '') {
                $out['skipped'][] = ['id' => $id, 'reason' => t('audio_dicts.replace.skip.no_suggest')];
                continue;
            }
            try {
                self::setReplacement($id, 0, (string)$row['replace_suggest']);
                $out['done']++;
            } catch (InvalidArgumentException $e) {
                $out['skipped'][] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return $out;
    }

    /**
     * Reject stored suggestions: state rejected, suggestion cleared.
     *
     * @param list<int> $ids
     * @return array{done:int,skipped:list<array{id:int,reason:string}>}
     */
    public static function rejectSuggestionsByIds(array $ids): array
    {
        $out = ['done' => 0, 'skipped' => []];
        if (!self::hasReplaceColumns()) {
            return $out;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        foreach ($ids as $id) {
            $row = self::find($id);
            if ($row === null || (string)$row['replace_state'] !== 'suggested') {
                $out['skipped'][] = ['id' => $id, 'reason' => t('audio_dicts.replace.skip.no_suggest')];
                continue;
            }
            $st = Database::pdo()->prepare(
                "UPDATE base_records_keys SET REPLACE_STATE = 'rejected', REPLACE_SUGGEST = '' WHERE ID = ?"
            );
            $st->execute([$id]);
            $out['done']++;
        }
        EventBus::emit('audio_dicts.changed', ['entity' => 'keys']);

        return $out;
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids, string $onDependents = ''): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0));
        if (!$ids) {
            return 0;
        }
        $ids = self::withDependents($ids, $onDependents);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_records_keys WHERE ID IN ($in)");
        $st->execute($ids);
        EventBus::emit('audio_dicts.changed', ['entity' => 'keys']);
        return $st->rowCount();
    }
}
