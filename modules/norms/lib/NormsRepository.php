<?php
declare(strict_types=1);

final class NormsRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'id_proc' => 'ID_PROC',
            'id_good' => 'ID_GOOD',
            'qtyu2' => 'QTYU2',
        ];
    }

    /**
     * @param array{id_proc?:int|null,id_good?:int|null,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_good_defaults')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        if (!empty($opts['id_proc'])) {
            $where[] = 'ID_PROC = ?';
            $params[] = (int)$opts['id_proc'];
        }
        if (!empty($opts['id_good'])) {
            $where[] = 'ID_GOOD = ?';
            $params[] = (int)$opts['id_good'];
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_good_defaults WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $order = trim((string)($opts['order'] ?? '')) ?: 'ID DESC';
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT ID AS id, ID_PROC AS id_proc, ID_GOOD AS id_good, QTYU AS qtyu, QTYU2 AS qtyu2
                FROM base_good_defaults WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_good_defaults')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_PROC AS id_proc, ID_GOOD AS id_good, QTYU AS qtyu, QTYU2 AS qtyu2
             FROM base_good_defaults WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $post */
    public static function dataFromPost(array $post): array
    {
        $idProc = (int)($post['id_proc'] ?? 0);
        $idGood = (int)($post['id_good'] ?? 0);
        if ($idProc < 1) {
            throw new InvalidArgumentException(t('norms.err.proc_required'));
        }
        if ($idGood < 1) {
            throw new InvalidArgumentException(t('norms.err.good_required'));
        }
        $qtyu2 = (float)str_replace(',', '.', (string)($post['qtyu2'] ?? '0'));
        if ($qtyu2 < 0) {
            $qtyu2 = 0;
        }
        $qtyu = (int)round($qtyu2);
        if (isset($post['qtyu']) && (string)$post['qtyu'] !== '') {
            $qtyu = (int)$post['qtyu'];
        }
        return [
            'id_proc' => $idProc,
            'id_good' => $idGood,
            'qtyu' => $qtyu,
            'qtyu2' => $qtyu2,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_good_defaults (ID_PROC, ID_GOOD, QTYU, QTYU2) VALUES (?, ?, ?, ?)'
        );
        $st->execute([$data['id_proc'], $data['id_good'], $data['qtyu'], $data['qtyu2']]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::pdo()->prepare(
            'UPDATE base_good_defaults SET ID_PROC = ?, ID_GOOD = ?, QTYU = ?, QTYU2 = ? WHERE ID = ?'
        )->execute([$data['id_proc'], $data['id_good'], $data['qtyu'], $data['qtyu2'], $id]);
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === [] || !Database::tableExists('base_good_defaults')) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_good_defaults WHERE ID IN ($ph)");
        $st->execute($ids);
        return $st->rowCount();
    }

    public static function embedHtmlForProc(int $procId): string
    {
        if ($procId < 1 || !Auth::can('norms.view')) {
            return '';
        }
        $items = self::list(['id_proc' => $procId, 'limit' => 100, 'order' => 'ID ASC'])['items'];
        $products = class_exists('ProductsRepository', false) ? ProductsRepository::names(false) : [];
        $html = '<section class="norms-embed" style="margin-top:1.5rem"><h2>' . e(t('norms.embed.title')) . '</h2>';
        if (Auth::can('norms.edit')) {
            $html .= '<p><a class="btn-secondary" href="/norms/new?id_proc=' . $procId . '">' . e(t('norms.embed.add')) . '</a></p>';
        }
        $html .= '<div class="table-wrap"><table class="data"><thead><tr>'
            . '<th>ID</th><th>' . e(t('norms.col.good')) . '</th>'
            . '<th>' . e(t('norms.col.qtyu2')) . '</th></tr></thead><tbody>';
        foreach ($items as $r) {
            $gid = (int)$r['id_good'];
            $name = e($products[$gid] ?? ('#' . $gid));
            $link = Auth::can('norms.edit')
                ? '<a href="/norms/' . (int)$r['id'] . '/edit">' . (int)$r['id'] . '</a>'
                : (string)(int)$r['id'];
            $html .= '<tr><td>' . $link . '</td><td>' . $name . '</td><td>' . e((string)$r['qtyu2']) . '</td></tr>';
        }
        if ($items === []) {
            $html .= '<tr><td colspan="3" class="muted">' . e(t('norms.list.empty')) . '</td></tr>';
        }
        $html .= '</tbody></table></div></section>';
        return $html;
    }
}
