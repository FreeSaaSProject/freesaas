<?php
declare(strict_types=1);

/**
 * Optional auto-comments on the linked laundry ticket after ship / accept.
 */
final class LaundryComments
{
    public static function enabled(): bool
    {
        if (!LaundryConfig::autoComment()) {
            return false;
        }
        return ModuleManager::isEnabled('comments')
            && class_exists('CommentsRepository', false)
            && Database::tableExists('base_comments');
    }

    /**
     * Soft-fail: never undoes a successful laundry op.
     *
     * @param list<array{id_item_type:int,qty:int}> $typeQty
     * @param list<array{id_item_type:int,qty:int}> $extras
     */
    public static function postShip(int $newsId, int $userId, array $typeQty): void
    {
        $items = self::formatItems($typeQty);
        if ($items === '') {
            return;
        }
        self::post($newsId, $userId, t('laundry.comment.ship', ['items' => $items]));
    }

    /**
     * @param list<array{id_shipment_line:int,qty:int}> $returnLines
     * @param list<array{id_item_type:int,qty:int}> $extras
     */
    public static function postAccept(int $newsId, int $userId, array $returnLines, array $extras): void
    {
        $byType = self::aggregateReturnByType($returnLines);
        $items = self::formatItems($byType);
        $extraItems = self::formatItems($extras);
        if ($items === '' && $extraItems === '') {
            return;
        }
        if ($items !== '') {
            $text = t('laundry.comment.accept', ['items' => $items]);
            if ($extraItems !== '') {
                $text .= t('laundry.comment.extra_suffix', ['items' => $extraItems]);
            }
        } else {
            $text = t('laundry.comment.accept_extra_only', ['items' => $extraItems]);
        }
        self::post($newsId, $userId, $text);
    }

    private static function post(int $newsId, int $userId, string $text): void
    {
        if ($newsId < 1 || $userId < 1 || !self::enabled()) {
            return;
        }
        $text = trim($text);
        if ($text === '') {
            return;
        }
        try {
            CommentsRepository::add($newsId, $userId, $text);
        } catch (Throwable $e) {
            // Soft-fail: laundry move already committed.
        }
    }

    /**
     * @param list<array{id_shipment_line:int,qty:int}> $returnLines
     * @return list<array{id_item_type:int,qty:int}>
     */
    private static function aggregateReturnByType(array $returnLines): array
    {
        $ids = [];
        $qtyByLine = [];
        foreach ($returnLines as $row) {
            $lineId = (int)($row['id_shipment_line'] ?? 0);
            $qty = (int)($row['qty'] ?? 0);
            if ($lineId < 1 || $qty < 1) {
                continue;
            }
            $ids[] = $lineId;
            $qtyByLine[$lineId] = ($qtyByLine[$lineId] ?? 0) + $qty;
        }
        if ($ids === []) {
            return [];
        }
        $ids = array_values(array_unique($ids));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, ID_ITEM_TYPE AS id_item_type
             FROM base_laundry_shipment_lines WHERE ID IN ($placeholders)"
        );
        $st->execute($ids);
        $byType = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $lineId = (int)$row['id'];
            $typeId = (int)$row['id_item_type'];
            $qty = (int)($qtyByLine[$lineId] ?? 0);
            if ($typeId < 1 || $qty < 1) {
                continue;
            }
            $byType[$typeId] = ($byType[$typeId] ?? 0) + $qty;
        }
        $out = [];
        foreach ($byType as $typeId => $qty) {
            $out[] = ['id_item_type' => (int)$typeId, 'qty' => (int)$qty];
        }
        return $out;
    }

    /**
     * Non-zero qty only, joined with " + ".
     *
     * @param list<array{id_item_type:int,qty:int}> $typeQty
     */
    private static function formatItems(array $typeQty): string
    {
        $names = LaundryItemTypesRepository::names(false);
        $parts = [];
        foreach ($typeQty as $row) {
            $typeId = (int)($row['id_item_type'] ?? 0);
            $qty = (int)($row['qty'] ?? 0);
            if ($typeId < 1 || $qty < 1) {
                continue;
            }
            $name = (string)($names[$typeId] ?? ('#' . $typeId));
            $parts[] = t('laundry.comment.item', ['qty' => $qty, 'name' => $name]);
        }
        return implode(t('laundry.comment.join'), $parts);
    }
}
