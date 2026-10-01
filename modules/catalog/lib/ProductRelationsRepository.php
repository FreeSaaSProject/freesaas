<?php
declare(strict_types=1);

final class ProductRelationsRepository
{
    public const TYPES = ['addon', 'upgrade', 'component'];

    /**
     * @return list<array<string,mixed>>
     */
    public static function forProduct(int $productId, bool $asSource = true): array
    {
        if ($productId < 1 || !Database::tableExists('base_product_relations')) {
            return [];
        }
        $field = $asSource ? 'product_from_id' : 'product_to_id';
        $joinField = $asSource ? 'r.product_to_id' : 'r.product_from_id';
        $st = Database::pdo()->prepare(
            "SELECT r.id, r.product_from_id, r.product_to_id, r.type, r.benefit,
                    r.created_at, r.enabled,
                    p.name AS related_name, p.price, p.price2, p.price3, p.price4, p.price5,
                    p.time_sec, p.time_net
             FROM base_product_relations r
             LEFT JOIN base_products p ON p.id = $joinField
             WHERE r.$field = ?
             ORDER BY r.type ASC, r.id ASC"
        );
        $st->execute([$productId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function componentsOf(int $productId): array
    {
        if ($productId < 1 || !Database::tableExists('base_product_relations')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            "SELECT r.id, r.product_to_id, p.name, p.price, p.price2, p.price3, p.price4, p.price5,
                    p.time_sec, p.time_net
             FROM base_product_relations r
             INNER JOIN base_products p ON p.id = r.product_to_id
             WHERE r.product_from_id = ? AND r.type = 'component' AND r.enabled = 1
             ORDER BY r.id ASC"
        );
        $st->execute([$productId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{price:float,price2:float,price3:float,price4:float,price5:float,time_sec:int,time_net:int}|null */
    public static function sumFromComponents(int $productId): ?array
    {
        $components = self::componentsOf($productId);
        if (!$components) {
            return null;
        }
        $sum = [
            'price' => 0.0,
            'price2' => 0.0,
            'price3' => 0.0,
            'price4' => 0.0,
            'price5' => 0.0,
            'time_sec' => 0,
            'time_net' => 0,
        ];
        foreach ($components as $c) {
            $sum['price'] += (float)$c['price'];
            $sum['price2'] += (float)$c['price2'];
            $sum['price3'] += (float)$c['price3'];
            $sum['price4'] += (float)$c['price4'];
            $sum['price5'] += (float)$c['price5'];
            $time = (int)$c['time_sec'];
            $timeNet = (int)$c['time_net'];
            if ($timeNet <= 0) {
                $timeNet = $time;
            }
            $sum['time_sec'] += $time;
            $sum['time_net'] += $timeNet;
        }
        return $sum;
    }

    public static function add(int $fromId, int $toId, string $type, string $benefit = ''): int
    {
        if ($fromId < 1 || $toId < 1) {
            throw new InvalidArgumentException(t('catalog.rel.err.ids'));
        }
        if ($fromId === $toId) {
            throw new InvalidArgumentException(t('catalog.rel.err.self'));
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(t('catalog.rel.err.type'));
        }
        $pdo = Database::pdo();
        $chk = $pdo->prepare(
            'SELECT id FROM base_product_relations
             WHERE product_from_id = ? AND product_to_id = ? AND type = ? LIMIT 1'
        );
        $chk->execute([$fromId, $toId, $type]);
        if ($chk->fetchColumn()) {
            throw new InvalidArgumentException(t('catalog.rel.err.exists'));
        }
        $st = $pdo->prepare(
            'INSERT INTO base_product_relations
             (product_from_id, product_to_id, type, benefit, created_at, enabled)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $st->execute([
            $fromId,
            $toId,
            $type,
            $benefit !== '' ? $benefit : null,
            time(),
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function delete(int $id, int $productId): void
    {
        if ($id < 1 || $productId < 1) {
            return;
        }
        $st = Database::pdo()->prepare(
            'DELETE FROM base_product_relations
             WHERE id = ? AND (product_from_id = ? OR product_to_id = ?)'
        );
        $st->execute([$id, $productId, $productId]);
    }

    public static function setEnabled(int $id, int $productId, bool $enabled): void
    {
        if ($id < 1 || $productId < 1) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_product_relations SET enabled = ?
             WHERE id = ? AND product_from_id = ?'
        );
        $st->execute([$enabled ? 1 : 0, $id, $productId]);
    }
}
