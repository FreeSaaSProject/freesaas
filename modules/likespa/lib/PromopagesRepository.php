<?php
declare(strict_types=1);

final class PromopagesRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'code' => 'CODE',
            'descr' => 'DESCR',
            'enabled' => 'ENABLED',
            'parent' => 'ID_PARENT',
        ];
    }

    /**
     * @param array{q?:string,enabled?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_promopages')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(DESCR LIKE ? OR CODE LIKE ? OR CAST(ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $en = (string)($opts['enabled'] ?? '');
        if ($en === '1' || $en === '0') {
            $where[] = 'ENABLED = ?';
            $params[] = (int)$en;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_promopages WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'DESCR ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, DESCR AS descr, CODE AS code, ENABLED AS enabled,
                       ID_PARENT AS id_parent, PHONE AS phone, SKIN AS skin
                FROM base_promopages WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** Root promopages for clone parent select (id => label). */
    public static function parentOptions(?int $excludeId = null): array
    {
        if (!Database::tableExists('base_promopages')) {
            return [];
        }
        $sql = 'SELECT ID, DESCR, CODE FROM base_promopages WHERE ID_PARENT < 1';
        $params = [];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND ID != ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY DESCR ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['ID']] = '#' . (int)$r['ID'] . ' ' . (string)$r['DESCR']
                . ' (' . (string)$r['CODE'] . ')';
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_promopages')) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_promopages WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $raw = $st->fetch(PDO::FETCH_ASSOC);
        if (!$raw) {
            return null;
        }
        return self::normalizeRow($raw);
    }

    public static function cloneCount(int $id): int
    {
        if ($id < 1 || !Database::tableExists('base_promopages')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_promopages WHERE ID_PARENT = ?'
        );
        $st->execute([$id]);
        return (int)$st->fetchColumn();
    }

    /** @param array<string,mixed> $raw */
    private static function normalizeRow(array $raw): array
    {
        $types = UsersRepository::parseHashIds((string)($raw['TYPES'] ?? ''));
        $cosm = UsersRepository::parseHashIds((string)($raw['COSM_LIMIT'] ?? ''));
        return [
            'id' => (int)$raw['ID'],
            'descr' => (string)$raw['DESCR'],
            'code' => (string)$raw['CODE'],
            'skin' => (string)$raw['SKIN'],
            'tpl' => (string)$raw['TPL'],
            'phone' => (string)$raw['PHONE'],
            'special_menu' => (int)$raw['SPECIAL_MENU'],
            'id_parent' => (int)$raw['ID_PARENT'],
            'thanks_url' => (string)$raw['THANKS_URL'],
            'enabled' => (int)$raw['ENABLED'],
            'can_use_oldclients' => (int)$raw['CAN_USE_OLDCLIENTS'],
            'can_use_newclient' => (int)$raw['CAN_USE_NEWCLIENT'],
            'can_use_clubers' => (int)$raw['CAN_USE_CLUBERS'],
            'can_pay_online_only' => (int)$raw['CAN_PAY_ONLINE_ONLY'],
            'can_pay_offline_only' => (int)$raw['CAN_PAY_OFFLINE_ONLY'],
            'can_use_dco' => (int)$raw['CAN_USE_DCO'],
            'can_use_bonus' => (int)$raw['CAN_USE_BONUS'],
            'can_schedule' => (int)$raw['CAN_SCHEDULE'],
            'can_use_today_only' => (int)$raw['CAN_USE_TODAY_ONLY'],
            'dt_expire' => (int)$raw['DT_EXPIRE'],
            'dt_use_only' => (int)$raw['DT_USE_ONLY'],
            'dt_expire_local' => self::tsToLocal((int)$raw['DT_EXPIRE']),
            'dt_use_only_local' => self::tsToLocal((int)$raw['DT_USE_ONLY']),
            'minimal_summ_limit' => (int)$raw['MINIMAL_SUMM_LIMIT'],
            'pricecol' => (int)$raw['PRICECOL'],
            'discount' => (int)$raw['DISCOUNT'],
            'types' => $types,
            'cosm_limit' => $cosm,
            'meta_title' => (string)$raw['META_TITLE'],
            'meta_descr' => (string)$raw['META_DESCR'],
            'synonims' => (string)$raw['SYNONIMS'],
            'modules_raw' => (string)$raw['MODULES'],
            'modules' => PromopageModules::decode((string)$raw['MODULES']),
            'good_prices' => (string)$raw['GOOD_PRICES'],
            'is_clone' => (int)$raw['ID_PARENT'] > 0,
            'has_clones' => false,
        ];
    }

    public static function tsToLocal(int $ts): string
    {
        if ($ts < 1) {
            return '';
        }
        return date('Y-m-d\TH:i', $ts);
    }

    public static function localToTs(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $t = strtotime($value);
        return $t !== false ? (int)$t : 0;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post, bool $isClone = false): array
    {
        $descr = trim((string)($post['descr'] ?? ''));
        $code = trim((string)($post['code'] ?? ''));
        if ($descr === '') {
            throw new InvalidArgumentException(t('likespa.promopages.err.descr_required'));
        }
        if ($code === '') {
            throw new InvalidArgumentException(t('likespa.promopages.err.code_required'));
        }
        if (mb_strlen($code) > 10) {
            throw new InvalidArgumentException(t('likespa.promopages.err.code_too_long'));
        }

        $data = [
            'descr' => mb_substr($descr, 0, 255),
            'code' => $code,
            'skin' => mb_substr(trim((string)($post['skin'] ?? '')), 0, 20),
            'phone' => mb_substr(trim((string)($post['phone'] ?? '')), 0, 16),
            'id_parent' => max(0, (int)($post['id_parent'] ?? 0)),
            'thanks_url' => mb_substr(trim((string)($post['thanks_url'] ?? '')), 0, 255),
            'enabled' => !empty($post['enabled']) ? 1 : 0,
            'dt_expire' => self::localToTs((string)($post['dt_expire_local'] ?? '')),
            'cosm_limit' => self::idsFromPost($post['cosm_limit'] ?? []),
        ];

        if ($isClone || $data['id_parent'] > 0) {
            return $data;
        }

        $data['tpl'] = mb_substr(trim((string)($post['tpl'] ?? '')), 0, 100);
        $data['special_menu'] = !empty($post['special_menu']) ? 1 : 0;
        $data['can_use_oldclients'] = !empty($post['can_use_oldclients']) ? 1 : 0;
        $data['can_use_newclient'] = !empty($post['can_use_newclient']) ? 1 : 0;
        $data['can_use_clubers'] = !empty($post['can_use_clubers']) ? 1 : 0;
        $data['can_pay_online_only'] = !empty($post['can_pay_online_only']) ? 1 : 0;
        $data['can_pay_offline_only'] = !empty($post['can_pay_offline_only']) ? 1 : 0;
        $data['can_use_dco'] = !empty($post['can_use_dco']) ? 1 : 0;
        $data['can_use_bonus'] = !empty($post['can_use_bonus']) ? 1 : 0;
        $data['can_schedule'] = !empty($post['can_schedule']) ? 1 : 0;
        $data['can_use_today_only'] = !empty($post['can_use_today_only']) ? 1 : 0;
        $data['dt_use_only'] = self::localToTs((string)($post['dt_use_only_local'] ?? ''));
        $data['minimal_summ_limit'] = max(0, (int)($post['minimal_summ_limit'] ?? 0));
        $data['pricecol'] = max(0, min(255, (int)($post['pricecol'] ?? 0)));
        $data['discount'] = max(0, min(99, (int)($post['discount'] ?? 0)));
        $data['types'] = self::idsFromPost($post['types'] ?? []);
        $data['meta_title'] = mb_substr(trim((string)($post['meta_title'] ?? '')), 0, 255);
        $data['meta_descr'] = trim((string)($post['meta_descr'] ?? ''));
        $data['synonims'] = trim((string)($post['synonims'] ?? ''));
        $modulesPost = is_array($post['modules'] ?? null) ? $post['modules'] : [];
        $data['modules'] = PromopageModules::fromPost($modulesPost);
        $data['modules_raw'] = PromopageModules::encode($data['modules']);
        return $data;
    }

    /** @param mixed $raw @return list<int> */
    private static function idsFromPost($raw): array
    {
        if (!is_array($raw)) {
            return UsersRepository::parseHashIds((string)$raw);
        }
        $ids = [];
        foreach ($raw as $v) {
            $id = (int)$v;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $now = time();
        $uid = Auth::userId();
        $isClone = ((int)($data['id_parent'] ?? 0)) > 0;
        $st = Database::pdo()->prepare(
            'INSERT INTO base_promopages (
                DESCR, CODE, SKIN, TPL, SHOWS, META_TITLE, META_DESCR,
                DT_CREATE, DT_UPDATE, ID_OPERATOR_CREATE, ID_OPERATOR_UPDATE,
                ENABLED, MODULES, CAN_USE_OLDCLIENTS, CAN_USE_CLUBERS, CAN_USE_NEWCLIENT,
                CAN_PAY_ONLINE_ONLY, CAN_PAY_OFFLINE_ONLY, CAN_USE_DCO, CAN_USE_BONUS,
                CAN_SCHEDULE, DT_EXPIRE, DT_USE_ONLY, MINIMAL_SUMM_LIMIT, TYPES,
                PRICECOL, DISCOUNT, GOOD_PRICES, SPECIAL_MENU, ID_PARENT, SYNONIMS,
                COSM_LIMIT, PHONE, THANKS_URL, CAN_USE_TODAY_ONLY
            ) VALUES (
                ?, ?, ?, ?, 0, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, \'\', ?, ?, ?,
                ?, ?, ?, ?
            )'
        );
        $st->execute([
            $data['descr'],
            $data['code'],
            $data['skin'],
            $isClone ? '' : (string)($data['tpl'] ?? ''),
            $isClone ? '' : (string)($data['meta_title'] ?? ''),
            $isClone ? '' : (string)($data['meta_descr'] ?? ''),
            $now,
            $now,
            $uid,
            $uid,
            (int)$data['enabled'],
            $isClone ? '' : (string)($data['modules_raw'] ?? ''),
            $isClone ? 1 : (int)($data['can_use_oldclients'] ?? 1),
            $isClone ? 1 : (int)($data['can_use_clubers'] ?? 1),
            $isClone ? 1 : (int)($data['can_use_newclient'] ?? 1),
            $isClone ? 1 : (int)($data['can_pay_online_only'] ?? 1),
            $isClone ? 1 : (int)($data['can_pay_offline_only'] ?? 1),
            $isClone ? 1 : (int)($data['can_use_dco'] ?? 1),
            $isClone ? 1 : (int)($data['can_use_bonus'] ?? 1),
            $isClone ? 1 : (int)($data['can_schedule'] ?? 1),
            (int)$data['dt_expire'],
            $isClone ? 0 : (int)($data['dt_use_only'] ?? 0),
            $isClone ? 0 : (int)($data['minimal_summ_limit'] ?? 0),
            $isClone ? '' : UsersRepository::encodeHashIds($data['types'] ?? []),
            $isClone ? 0 : (int)($data['pricecol'] ?? 0),
            $isClone ? 0 : (int)($data['discount'] ?? 0),
            $isClone ? 0 : (int)($data['special_menu'] ?? 0),
            (int)$data['id_parent'],
            $isClone ? '' : (string)($data['synonims'] ?? ''),
            implode(',', $data['cosm_limit'] ?? []),
            $data['phone'],
            $data['thanks_url'],
            $isClone ? 0 : (int)($data['can_use_today_only'] ?? 0),
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data, bool $isClone): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid promopage id');
        }
        $now = time();
        $uid = Auth::userId();
        if ($isClone) {
            $st = Database::pdo()->prepare(
                'UPDATE base_promopages SET
                    DESCR = ?, CODE = ?, SKIN = ?, PHONE = ?, ID_PARENT = ?, THANKS_URL = ?,
                    ENABLED = ?, DT_EXPIRE = ?, COSM_LIMIT = ?,
                    DT_UPDATE = ?, ID_OPERATOR_UPDATE = ?
                 WHERE ID = ?'
            );
            $st->execute([
                $data['descr'],
                $data['code'],
                $data['skin'],
                $data['phone'],
                (int)$data['id_parent'],
                $data['thanks_url'],
                (int)$data['enabled'],
                (int)$data['dt_expire'],
                implode(',', $data['cosm_limit'] ?? []),
                $now,
                $uid,
                $id,
            ]);
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_promopages SET
                DESCR = ?, CODE = ?, SKIN = ?, TPL = ?, PHONE = ?, SPECIAL_MENU = ?,
                ID_PARENT = ?, THANKS_URL = ?, ENABLED = ?,
                CAN_USE_OLDCLIENTS = ?, CAN_USE_NEWCLIENT = ?, CAN_USE_CLUBERS = ?,
                CAN_PAY_ONLINE_ONLY = ?, CAN_PAY_OFFLINE_ONLY = ?, CAN_USE_DCO = ?,
                CAN_USE_BONUS = ?, CAN_SCHEDULE = ?, CAN_USE_TODAY_ONLY = ?,
                DT_EXPIRE = ?, DT_USE_ONLY = ?, MINIMAL_SUMM_LIMIT = ?,
                TYPES = ?, PRICECOL = ?, DISCOUNT = ?, META_TITLE = ?, META_DESCR = ?,
                SYNONIMS = ?, COSM_LIMIT = ?, MODULES = ?,
                DT_UPDATE = ?, ID_OPERATOR_UPDATE = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['descr'],
            $data['code'],
            $data['skin'],
            (string)($data['tpl'] ?? ''),
            $data['phone'],
            (int)($data['special_menu'] ?? 0),
            (int)$data['id_parent'],
            $data['thanks_url'],
            (int)$data['enabled'],
            (int)($data['can_use_oldclients'] ?? 0),
            (int)($data['can_use_newclient'] ?? 0),
            (int)($data['can_use_clubers'] ?? 0),
            (int)($data['can_pay_online_only'] ?? 0),
            (int)($data['can_pay_offline_only'] ?? 0),
            (int)($data['can_use_dco'] ?? 0),
            (int)($data['can_use_bonus'] ?? 0),
            (int)($data['can_schedule'] ?? 0),
            (int)($data['can_use_today_only'] ?? 0),
            (int)$data['dt_expire'],
            (int)($data['dt_use_only'] ?? 0),
            (int)($data['minimal_summ_limit'] ?? 0),
            UsersRepository::encodeHashIds($data['types'] ?? []),
            (int)($data['pricecol'] ?? 0),
            (int)($data['discount'] ?? 0),
            (string)($data['meta_title'] ?? ''),
            (string)($data['meta_descr'] ?? ''),
            (string)($data['synonims'] ?? ''),
            implode(',', $data['cosm_limit'] ?? []),
            (string)($data['modules_raw'] ?? ''),
            $now,
            $uid,
            $id,
        ]);
    }

    /** @param list<int> $ids @return int deleted count */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === [] || !Database::tableExists('base_promopages')) {
            return 0;
        }
        $done = 0;
        foreach ($ids as $id) {
            if (self::cloneCount($id) > 0) {
                continue;
            }
            $st = Database::pdo()->prepare('DELETE FROM base_promopages WHERE ID = ?');
            $st->execute([$id]);
            $done += $st->rowCount();
        }
        return $done;
    }

    public static function publicUrl(string $code): string
    {
        $host = trim((string)Config::get('likespa', 'mothersite', ''));
        if ($host === '' || $code === '') {
            return '';
        }
        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = rtrim($host, '/');
        return 'https://' . $host . '/promo/' . rawurlencode($code) . '/';
    }
}
