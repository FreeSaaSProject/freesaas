<?php
declare(strict_types=1);

/**
 * Ship / accept panel shared by /laundry and the linked ticket card.
 */
final class LaundryPanel
{
    public const ACTION_SHIP = 'laundry_ship';
    public const ACTION_ACCEPT = 'laundry_accept';

    public static function isPanelAction(string $action): bool
    {
        return $action === self::ACTION_SHIP || $action === self::ACTION_ACCEPT;
    }

    /**
     * @param array<string,mixed> $post
     */
    public static function handlePost(string $action, array $post, int $userId, int $newsId): void
    {
        if (!Auth::can('laundry.ship')) {
            throw new InvalidArgumentException(t('core.bulk.err.forbidden'));
        }
        $descr = trim((string)($post['descr'] ?? ''));
        $weight = (float)str_replace(',', '.', (string)($post['weight'] ?? '0'));
        if ($action === self::ACTION_SHIP) {
            $qtyLines = self::typeQtyLines($post['qty'] ?? null);
            LaundryShipmentsRepository::createShipment(
                LaundryConfig::termsAccountId(),
                LaundryConfig::laundryAccountId(),
                $qtyLines,
                $descr,
                $weight,
                time(),
                $userId,
                $newsId
            );
            LaundryComments::postShip($newsId, $userId, $qtyLines, $descr, $weight);
            return;
        }
        $lines = [];
        // accept_line[] — not "ret[]": NavContext owns POST/GET ret for return URLs.
        $accept = is_array($post['accept_line'] ?? null) ? $post['accept_line'] : [];
        // Legacy field name (pre-1.0.4) if a stale form is posted.
        if ($accept === [] && is_array($post['ret'] ?? null)) {
            $accept = $post['ret'];
        }
        foreach ($accept as $lineId => $qty) {
            $lines[] = ['id_shipment_line' => (int)$lineId, 'qty' => (int)$qty];
        }
        $extras = self::typeQtyLines($post['extra'] ?? null);
        LaundryShipmentsRepository::acceptReturn(
            $lines,
            $descr,
            $weight,
            time(),
            $userId,
            $newsId,
            $extras
        );
        LaundryComments::postAccept($newsId, $userId, $lines, $extras, $descr, $weight);
    }

    /**
     * @param mixed $raw
     * @return list<array{id_item_type:int,qty:int}>
     */
    public static function typeQtyLines($raw): array
    {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $typeId => $qty) {
            $out[] = ['id_item_type' => (int)$typeId, 'qty' => (int)$qty];
        }
        return $out;
    }

    /**
     * @param array{post_url?:string,show_module_link?:bool,error?:string} $opts
     */
    public static function render(array $opts = []): string
    {
        $accounts = LaundryAccountsRepository::names(true);
        $types = LaundryItemTypesRepository::listEnabled();
        $matrix = LaundryMovesRepository::balanceMatrix();
        $started = LaundryMovesRepository::hasAnyMoves();
        $canShip = Auth::can('laundry.ship');
        $canEdit = Auth::can('laundry.edit');
        $open = $canShip ? LaundryShipmentsRepository::listOpenWithLines() : [];
        $postUrl = (string)($opts['post_url'] ?? '');
        $showModuleLink = !empty($opts['show_module_link']);
        $panelError = (string)($opts['error'] ?? '');
        ob_start();
        require base_path('modules/laundry/views/_panel.php');
        return (string)ob_get_clean();
    }
}
