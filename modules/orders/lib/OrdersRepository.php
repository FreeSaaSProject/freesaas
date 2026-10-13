<?php
declare(strict_types=1);

/**
 * Website orders in shared base_orders (site + oper).
 */
final class OrdersRepository
{
    /** Statuses counted as “new / placed” for notices and status=new filters. */
    public const NEW_STATUSES = ['cashComplete', 'sberPayReady', 'paidSber'];

    /**
     * UI status filter keys → i18n label keys (DB STATUS values stay raw).
     *
     * @return array<string,string> filterValue => i18n key
     */
    public static function statusFilterOptions(): array
    {
        return [
            'all' => 'orders.list.status_all',
            'new' => 'orders.list.status_new',
            'newToday' => 'orders.list.status_new_today',
            '0' => 'orders.status.0',
            '1' => 'orders.status.1',
            'cashComplete' => 'orders.status.cashComplete',
            'sberPayReady' => 'orders.status.sberPayReady',
            'paidSber' => 'orders.status.paidSber',
            'failedPaySber' => 'orders.status.failedPaySber',
            'userCancelPay' => 'orders.status.userCancelPay',
            'complete' => 'orders.status.complete',
            'ukassaNew' => 'orders.status.ukassaNew',
            'deletedByAdmin' => 'orders.status.deletedByAdmin',
            'paymentProblem' => 'orders.status.paymentProblem',
        ];
    }

    /** Editable STATUS select values (stored as-is in DB). */
    public static function editableStatuses(): array
    {
        return [
            '0',
            'cashComplete',
            'sberPayReady',
            'paidSber',
            'failedPaySber',
            'userCancelPay',
            'complete',
            'ukassaNew',
            'deletedByAdmin',
        ];
    }

    public static function statusLabel(string $status): string
    {
        $status = trim($status);
        if ($status === '' || $status === '0') {
            return t('orders.status.0');
        }
        $known = [
            '1', 'cashComplete', 'deletedByAdmin', 'sberPayReady', 'paidSber',
            'failedPaySber', 'userCancelPay', 'complete', 'ukassaNew', 'paymentProblem',
        ];
        if (in_array($status, $known, true)) {
            return t('orders.status.' . $status);
        }
        return t('orders.status.unknown', ['code' => $status]);
    }

    /** @return array<string,string> sort key => SQL expression */
    public static function listSortMap(): array
    {
        return [
            'id' => 'o.ID',
            'dt_save' => 'o.DT_SAVE',
            'dt_event' => 'o.DT_EVENT_NEW',
            'dt_update' => 'o.DT_UPDATE_BY_USER',
            'status' => 'o.STATUS',
            'summ_real' => 'o.SUMM_REAL',
            'id_user' => 'o.ID_USER',
        ];
    }

    /**
     * @param array{
     *   q?:string,
     *   status?:string,
     *   id_user?:int|null,
     *   id_promocode?:int|null,
     *   free_ticket?:string|null,
     *   limit?:int,
     *   offset?:int,
     *   order?:string
     * } $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_orders')) {
            return ['items' => [], 'total' => 0];
        }

        [$where, $params] = self::buildWhere($opts);
        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'o.ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));

        $pdo = Database::pdo();
        $stCount = $pdo->prepare("SELECT COUNT(*) FROM base_orders o WHERE $where");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();

        $joinClient = Database::tableExists('base_clients')
            ? 'LEFT JOIN base_clients c ON c.id = o.ID_USER'
            : '';
        $clientSelect = $joinClient !== ''
            ? ', c.id AS client_row_id, c.name AS client_name, c.tel AS client_tel'
            : ', NULL AS client_row_id, NULL AS client_name, NULL AS client_tel';

        $joinStaff = Database::tableExists('base_users')
            ? 'LEFT JOIN base_users u ON u.id = o.ID_COSMETOLOG'
            : '';
        $staffSelect = $joinStaff !== ''
            ? ', u.name AS staff_name, u.login AS staff_login'
            : ', NULL AS staff_name, NULL AS staff_login';

        $sql = "SELECT o.ID AS id, o.UID AS uid, o.DT_CREATE AS dt_create,
                       o.DT_UPDATE_BY_USER AS dt_update, o.DT_SAVE AS dt_save,
                       o.DT_EVENT_NEW AS dt_event, o.ID_USER AS id_user,
                       o.ID_COSMETOLOG AS id_cosmetolog, o.ID_EVENT_USE AS id_event_use,
                       o.ID_EVENT_PAID AS id_event_paid, o.ID_SERT AS id_sert,
                       o.PHONE AS phone, o.MAIL AS mail, o.STATUS AS status,
                       o.SUMM AS summ, o.SUMM_REAL AS summ_real, o.SUMM_SBER AS summ_sber,
                       o.SUMM_BONUS AS summ_bonus, o.EPAID AS epaid,
                       o.ID_PROMOCODE AS id_promocode, o.TEXT_PROMOCODE AS text_promocode,
                       o.FREE_TICKET AS free_ticket, o.COMMENT AS comment
                       $clientSelect $staffSelect
                FROM base_orders o
                $joinClient
                $joinStaff
                WHERE $where
                ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::normalizeRow($row);
        }
        return ['items' => self::reconcileClientLinks($items), 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_orders')) {
            return null;
        }
        $pdo = Database::pdo();
        $joinClient = Database::tableExists('base_clients')
            ? 'LEFT JOIN base_clients c ON c.id = o.ID_USER'
            : '';
        $clientSelect = $joinClient !== ''
            ? ', c.id AS client_row_id, c.name AS client_name, c.tel AS client_tel'
            : ', NULL AS client_row_id, NULL AS client_name, NULL AS client_tel';
        $joinStaff = Database::tableExists('base_users')
            ? 'LEFT JOIN base_users u ON u.id = o.ID_COSMETOLOG'
            : '';
        $staffSelect = $joinStaff !== ''
            ? ', u.name AS staff_name, u.login AS staff_login'
            : ', NULL AS staff_name, NULL AS staff_login';

        $st = $pdo->prepare(
            "SELECT o.ID AS id, o.UID AS uid, o.DT_CREATE AS dt_create,
                    o.DT_UPDATE_BY_USER AS dt_update, o.DT_SAVE AS dt_save,
                    o.DT_EVENT_NEW AS dt_event, o.ID_USER AS id_user,
                    o.ID_COSMETOLOG AS id_cosmetolog, o.ID_EVENT_USE AS id_event_use,
                    o.ID_EVENT_PAID AS id_event_paid, o.ID_SERT AS id_sert,
                    o.PHONE AS phone, o.MAIL AS mail, o.STATUS AS status,
                    o.CART AS cart, o.COMMENT AS comment, o.PPP_LOG AS ppp_log,
                    o.SUMM AS summ, o.SUMM_REAL AS summ_real, o.SUMM_SBER AS summ_sber,
                    o.SUMM_BONUS AS summ_bonus, o.EPAID AS epaid,
                    o.SBER_ID AS sber_id, o.SBER_LINK AS sber_link, o.SBER_STATUS AS sber_status,
                    o.ID_PROMOCODE AS id_promocode, o.TEXT_PROMOCODE AS text_promocode,
                    o.FREE_TICKET AS free_ticket
                    $clientSelect $staffSelect
             FROM base_orders o
             $joinClient
             $joinStaff
             WHERE o.ID = ?
             LIMIT 1"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $items = self::reconcileClientLinks([self::normalizeRow($row)]);
        return $items[0] ?? null;
    }

    /**
     * Oper show_orders: untie missing clients; auto-link by PHONE when ID_USER=0.
     * Sets client_auto_linked / client_untied flags for UI.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function reconcileClientLinks(array $items): array
    {
        if ($items === [] || !Database::tableExists('base_orders') || !Database::tableExists('base_clients')) {
            return $items;
        }

        $pdo = Database::pdo();
        $toUntie = [];
        $needPhone = [];
        foreach ($items as $i => $row) {
            $items[$i]['client_auto_linked'] = false;
            $items[$i]['client_untied'] = false;
            $uid = (int)($row['id_user'] ?? 0);
            $joinId = (int)($row['client_row_id'] ?? 0);
            if ($uid > 0 && $joinId < 1) {
                $toUntie[] = (int)$row['id'];
                $items[$i]['id_user'] = 0;
                $items[$i]['client_name'] = null;
                $items[$i]['client_tel'] = null;
                $items[$i]['client_row_id'] = null;
                $items[$i]['client_untied'] = true;
                $uid = 0;
            }
            if ($uid < 1) {
                $phone = trim((string)($row['phone'] ?? ''));
                if ($phone !== '') {
                    $needPhone[$phone][] = $i;
                }
            }
        }

        if ($toUntie !== []) {
            $in = implode(',', array_map('intval', $toUntie));
            $pdo->exec("UPDATE base_orders SET ID_USER = 0 WHERE ID IN ($in)");
        }

        if ($needPhone === []) {
            return $items;
        }

        $phones = array_keys($needPhone);
        $placeholders = implode(',', array_fill(0, count($phones), '?'));
        $st = $pdo->prepare(
            "SELECT id, name, tel FROM base_clients WHERE tel IN ($placeholders)"
        );
        $st->execute($phones);
        $byTel = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
            $tel = (string)$c['tel'];
            // First match wins (oper breaks on first).
            if (!isset($byTel[$tel])) {
                $byTel[$tel] = $c;
            }
        }

        $linkSt = $pdo->prepare('UPDATE base_orders SET ID_USER = ? WHERE ID = ? AND ID_USER = 0');
        foreach ($needPhone as $phone => $indexes) {
            if (!isset($byTel[$phone])) {
                continue;
            }
            $client = $byTel[$phone];
            $clientId = (int)$client['id'];
            foreach ($indexes as $i) {
                $orderId = (int)$items[$i]['id'];
                $linkSt->execute([$clientId, $orderId]);
                $items[$i]['id_user'] = $clientId;
                $items[$i]['client_row_id'] = $clientId;
                $items[$i]['client_name'] = (string)($client['name'] ?? '');
                $items[$i]['client_tel'] = (string)($client['tel'] ?? '');
                $items[$i]['client_auto_linked'] = true;
                $items[$i]['client_untied'] = false;
            }
        }

        return $items;
    }

    /**
     * Staff edit (oper edit_order parity): status, staff, event time, client contact,
     * cart lines, sums, promo, free_ticket, comment.
     *
     * @param array{
     *   status?:string,
     *   id_cosmetolog?:int,
     *   id_user?:int,
     *   phone?:string,
     *   mail?:string,
     *   comment?:string,
     *   free_ticket?:int,
     *   dt_event?:int,
     *   summ?:float,
     *   summ_real?:float,
     *   id_promocode?:int,
     *   text_promocode?:string,
     *   cart_lines?:list<array{id:int,name:string,price:float,price_original:float}>
     * } $data
     */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException(t('orders.err.not_found', ['id' => $id]));
        }
        $prev = self::find($id);
        if ($prev === null) {
            throw new InvalidArgumentException(t('orders.err.not_found', ['id' => $id]));
        }

        $status = (string)($data['status'] ?? $prev['status'] ?? '0');
        if (!in_array($status, self::editableStatuses(), true)) {
            $status = (string)($prev['status'] ?? '0');
        }
        $comment = (string)($data['comment'] ?? $prev['comment'] ?? '');
        $audit = [];
        if ($status !== (string)($prev['status'] ?? '')) {
            $audit[] = 'Изм.ст-с: ' . (string)($prev['status'] ?? '') . ' → ' . $status;
        }
        $newCosm = max(0, (int)($data['id_cosmetolog'] ?? $prev['id_cosmetolog'] ?? 0));
        if ($newCosm !== (int)($prev['id_cosmetolog'] ?? 0)) {
            $audit[] = 'Изм.спец: #' . (int)($prev['id_cosmetolog'] ?? 0) . ' → #' . $newCosm;
        }
        $dtEvent = max(0, (int)($data['dt_event'] ?? $prev['dt_event'] ?? 0));
        if ($dtEvent !== (int)($prev['dt_event'] ?? 0)) {
            $audit[] = 'Изм.даты: '
                . ((int)($prev['dt_event'] ?? 0) > 0 ? date('d.m.Y H:i', (int)$prev['dt_event']) : '—')
                . ' → '
                . ($dtEvent > 0 ? date('d.m.Y H:i', $dtEvent) : '—');
        }

        $cartLines = null;
        $cartSql = '';
        $cartParams = [];
        $cartChanged = false;
        if (array_key_exists('cart_lines', $data) && is_array($data['cart_lines'])) {
            $cartLines = $data['cart_lines'];
            $newCart = self::encodeCart($cartLines);
            $oldCart = (string)($prev['cart'] ?? '');
            $cartChanged = $newCart !== $oldCart;
            $cartSql = ', CART = ?';
            $cartParams[] = $newCart;
        }

        $summ = round((float)($data['summ'] ?? $prev['summ'] ?? 0), 2);
        $summReal = round((float)($data['summ_real'] ?? $prev['summ_real'] ?? 0), 2);
        if ($cartLines !== null) {
            $cartTotal = self::cartLinesTotal($cartLines);
            // Always keep base summ in sync with cart composition/prices.
            $summ = $cartTotal;
            $oldCartTotal = self::cartLinesTotal(self::decodeCart((string)($prev['cart'] ?? '')));
            $oldReal = round((float)($prev['summ_real'] ?? 0), 2);
            $postedReal = round((float)($data['summ_real'] ?? $oldReal), 2);
            // Sync check amount when cart changed and staff had not set a distinct discount
            // (summ_real ≈ previous cart total / previous summ).
            if ($cartChanged && (
                abs($oldReal - $oldCartTotal) < 0.02
                || abs($oldReal - round((float)($prev['summ'] ?? 0), 2)) < 0.02
                || abs($postedReal - $oldReal) < 0.02
            )) {
                $summReal = $cartTotal;
            } else {
                $summReal = $postedReal;
            }
        }
        if ($summ !== round((float)($prev['summ'] ?? 0), 2)) {
            $audit[] = 'Изм.базов.цена: ' . (string)($prev['summ'] ?? 0) . ' → ' . $summ;
        }
        if ($summReal !== round((float)($prev['summ_real'] ?? 0), 2)) {
            $audit[] = 'Изм.реал.цена: ' . (string)($prev['summ_real'] ?? 0) . ' → ' . $summReal;
        }
        if ($audit !== []) {
            $comment = trim($comment . ' ' . implode('; ', $audit));
        }

        $merged = array_merge($data, [
            'status' => $status,
            'id_cosmetolog' => $newCosm,
            'dt_event' => $dtEvent,
            'summ' => $summ,
            'summ_real' => $summReal,
        ]);
        if (class_exists(BookingValidate::class, false)) {
            BookingValidate::assertOrderUpdate($id, $prev, $merged, $cartLines);
        }

        $st = Database::pdo()->prepare(
            'UPDATE base_orders SET
                STATUS = ?, ID_COSMETOLOG = ?, ID_USER = ?, PHONE = ?, MAIL = ?,
                COMMENT = ?, FREE_TICKET = ?, DT_EVENT_NEW = ?,
                SUMM = ?, SUMM_REAL = ?,
                ID_PROMOCODE = ?, TEXT_PROMOCODE = ?
                ' . $cartSql . '
             WHERE ID = ?'
        );
        $params = [
            $status,
            $newCosm,
            max(0, (int)($data['id_user'] ?? $prev['id_user'] ?? 0)),
            mb_substr(preg_replace('/\D+/', '', (string)($data['phone'] ?? $prev['phone'] ?? '')) ?? '', 0, 32),
            mb_substr((string)($data['mail'] ?? ''), 0, 50),
            $comment,
            !empty($data['free_ticket']) ? 1 : 0,
            $dtEvent,
            $summ,
            $summReal,
            max(0, (int)($data['id_promocode'] ?? $prev['id_promocode'] ?? 0)),
            mb_substr((string)($data['text_promocode'] ?? $prev['text_promocode'] ?? ''), 0, 255),
        ];
        foreach ($cartParams as $cp) {
            $params[] = $cp;
        }
        $params[] = $id;
        $st->execute($params);
    }

    /**
     * @param list<array{price?:float|int|string}> $lines
     */
    public static function cartLinesTotal(array $lines): float
    {
        $sum = 0.0;
        foreach ($lines as $line) {
            $sum += (float)($line['price'] ?? 0);
        }
        return round($sum, 2);
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $dtEvent = self::parseDtEvent(
            (string)($post['dt_event'] ?? ''),
            (string)($post['dt_event_date'] ?? ''),
            (string)($post['dt_event_time'] ?? '')
        );

        $cartLines = self::cartLinesFromPost($post);

        return [
            'status' => (string)($post['status'] ?? '0'),
            'id_cosmetolog' => max(0, (int)($post['id_cosmetolog'] ?? 0)),
            'id_user' => max(0, (int)($post['id_user'] ?? 0)),
            'phone' => (string)($post['phone'] ?? ''),
            'mail' => trim((string)($post['mail'] ?? '')),
            'comment' => (string)($post['comment'] ?? ''),
            'free_ticket' => !empty($post['free_ticket']) ? 1 : 0,
            'dt_event' => $dtEvent,
            'summ' => (float)str_replace(',', '.', (string)($post['summ'] ?? '0')),
            'summ_real' => (float)str_replace(',', '.', (string)($post['summ_real'] ?? '0')),
            'id_promocode' => max(0, (int)($post['id_promocode'] ?? 0)),
            'text_promocode' => trim((string)($post['text_promocode'] ?? '')),
            'cart_lines' => $cartLines,
        ];
    }

    /**
     * @param array<string,mixed> $post
     * @return list<array{id:int,name:string,price:float,price_original:float}>
     */
    public static function cartLinesFromPost(array $post): array
    {
        $ids = $post['cart_id'] ?? [];
        $prices = $post['cart_price'] ?? [];
        $origs = $post['cart_price_orig'] ?? [];
        $remove = $post['cart_remove'] ?? [];
        $deleteOne = (int)($post['cart_delete'] ?? -1);
        if (!is_array($ids)) {
            return [];
        }

        $keptIndexes = [];
        foreach ($ids as $i => $rawId) {
            if (!empty($remove[$i]) || (int)$i === $deleteOne) {
                continue;
            }
            $keptIndexes[] = (int)$i;
        }
        // Last service cannot be removed — only replaced via selector.
        if ($keptIndexes === [] && $ids !== []) {
            $keptIndexes[] = (int)array_key_first($ids);
        }

        $out = [];
        foreach ($keptIndexes as $i) {
            $id = (int)($ids[$i] ?? 0);
            $price = is_array($prices)
                ? (float)str_replace(',', '.', (string)($prices[$i] ?? '0'))
                : 0.0;
            $orig = is_array($origs)
                ? (float)str_replace(',', '.', (string)($origs[$i] ?? (string)$price))
                : $price;
            $name = '';
            if ($id > 0 && class_exists(ProductsRepository::class, false)) {
                $p = ProductsRepository::find($id);
                if ($p !== null) {
                    $name = (string)($p['name'] ?? '');
                    $catPrice = (float)($p['price3'] ?? 0);
                    if ($catPrice <= 0) {
                        $catPrice = (float)($p['price'] ?? 0);
                    }
                    if ($price <= 0) {
                        $price = $catPrice;
                    }
                    if ($orig <= 0) {
                        $orig = $catPrice > 0 ? $catPrice : $price;
                    }
                }
            }
            if ($id < 1 && $price <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => $name,
                'price' => $price,
                'price_original' => $orig > 0 ? $orig : $price,
            ];
        }

        $addId = (int)($post['cart_add_id'] ?? 0);
        if ($addId > 0 && class_exists(ProductsRepository::class, false)) {
            $p = ProductsRepository::find($addId);
            if ($p !== null) {
                $price = (float)($p['price3'] ?? 0);
                if ($price <= 0) {
                    $price = (float)($p['price'] ?? 0);
                }
                $out[] = [
                    'id' => $addId,
                    'name' => (string)($p['name'] ?? ''),
                    'price' => $price,
                    'price_original' => $price,
                ];
            }
        }

        return $out;
    }

    /**
     * Staff on shift for the order day (+ always include current assignee).
     *
     * @return list<array{id:int,name:string}>
     */
    public static function staffOptionsForDay(int $dayStart, int $includeStaffId = 0): array
    {
        $byId = [];
        if ($dayStart > 0 && class_exists(WorkweeksRepository::class, false)) {
            foreach (WorkweeksRepository::listForDay($dayStart) as $shift) {
                $oid = (int)($shift['id_operator'] ?? 0);
                if ($oid < 1 || !empty($shift['assist_team'])) {
                    continue;
                }
                $name = trim((string)($shift['operator_name'] ?? ''));
                if ($name === '') {
                    $name = '#' . $oid;
                }
                $byId[$oid] = ['id' => $oid, 'name' => $name];
            }
        }
        if ($includeStaffId > 0 && !isset($byId[$includeStaffId])) {
            $name = '#' . $includeStaffId;
            if (class_exists(UsersRepository::class, false)) {
                $u = UsersRepository::find($includeStaffId);
                if ($u !== null) {
                    $label = trim((string)($u['name'] ?? ''));
                    if ($label === '') {
                        $label = (string)($u['login'] ?? '');
                    }
                    if ($label !== '') {
                        $name = $label;
                    }
                }
            }
            $byId[$includeStaffId] = [
                'id' => $includeStaffId,
                'name' => $name . ' (' . t('orders.edit.staff_not_on_shift') . ')',
            ];
        }
        $out = array_values($byId);
        usort($out, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    public static function parseDtEvent(string $datetimeLocal, string $date = '', string $time = ''): int
    {
        $datetimeLocal = trim($datetimeLocal);
        if ($datetimeLocal !== '') {
            // HTML datetime-local: Y-m-d\TH:i
            $norm = str_replace('T', ' ', $datetimeLocal);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $norm)) {
                $ts = strtotime($norm);
                if ($ts !== false) {
                    return $ts;
                }
            }
        }
        $date = trim($date);
        $time = trim($time);
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            if ($time === '' || !preg_match('/^\d{1,2}:\d{2}/', $time)) {
                $time = '12:00';
            }
            $ts = strtotime($date . ' ' . $time);
            if ($ts !== false) {
                return $ts;
            }
        }
        return 0;
    }

    /**
     * Create a website order (storefront / public checkout).
     *
     * @param array{
     *   phone:string,
     *   mail?:string,
     *   comment?:string,
     *   cart_lines?:list<array{id?:int,name?:string,price?:float,price_original?:float}>,
     *   cart?:string,
     *   summ?:float,
     *   summ_real?:float,
     *   status?:string,
     *   id_user?:int,
     *   id_cosmetolog?:int,
     *   dt_event?:int,
     *   id_promocode?:int,
     *   text_promocode?:string,
     *   free_ticket?:int,
     *   uid?:string
     * } $data
     */
    public static function create(array $data): int
    {
        if (!Database::tableExists('base_orders')) {
            throw new RuntimeException('base_orders missing');
        }
        $phone = preg_replace('/\D+/', '', (string)($data['phone'] ?? '')) ?? '';
        if ($phone === '') {
            throw new InvalidArgumentException('phone required');
        }
        $lines = $data['cart_lines'] ?? null;
        $cart = (string)($data['cart'] ?? '');
        if ($cart === '' && is_array($lines)) {
            $cart = self::encodeCart($lines);
        }
        $summ = round((float)($data['summ'] ?? 0), 2);
        $summReal = round((float)($data['summ_real'] ?? $summ), 2);
        if ($summ <= 0 && is_array($lines)) {
            foreach ($lines as $line) {
                $summ += (float)($line['price'] ?? 0);
            }
            $summ = round($summ, 2);
            if ($summReal <= 0) {
                $summReal = $summ;
            }
        }
        $status = trim((string)($data['status'] ?? 'cashComplete'));
        if ($status === '') {
            $status = 'cashComplete';
        }
        $now = time();
        $uid = trim((string)($data['uid'] ?? ''));
        if ($uid === '') {
            $uid = substr(bin2hex(random_bytes(8)), 0, 15);
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_orders (
                DT_CREATE, DT_UPDATE_BY_USER, DT_SAVE, DT_EVENT_NEW,
                ID_USER, ID_SERT, ID_EVENT_PAID, ID_EVENT_USE, ID_COSMETOLOG,
                CART, PHONE, MAIL, STATUS, COMMENT, PPP_LOG,
                EPAID, SBER_ID, SBER_LINK, SBER_STATUS,
                SUMM, SUMM_REAL, SUMM_SBER, SUMM_BONUS, UID,
                ID_PROMOCODE, TEXT_PROMOCODE, FREE_TICKET
             ) VALUES (
                ?, ?, ?, ?,
                ?, ?, 0, 0, ?,
                ?, ?, ?, ?, ?, \'\',
                0, \'\', \'\', 0,
                ?, ?, 0, 0, ?,
                ?, ?, ?
             )'
        );
        $st->execute([
            $now,
            $now,
            $now,
            max(0, (int)($data['dt_event'] ?? $now)),
            max(0, (int)($data['id_user'] ?? 0)),
            max(0, (int)($data['id_sert'] ?? 0)),
            max(0, (int)($data['id_cosmetolog'] ?? 0)),
            $cart,
            mb_substr($phone, 0, 11),
            mb_substr(trim((string)($data['mail'] ?? '')), 0, 50),
            mb_substr($status, 0, 255),
            (string)($data['comment'] ?? ''),
            $summ,
            $summReal,
            mb_substr($uid, 0, 15),
            max(0, (int)($data['id_promocode'] ?? 0)),
            mb_substr((string)($data['text_promocode'] ?? ''), 0, 255),
            !empty($data['free_ticket']) ? 1 : 0,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * Serialize cart lines for base_orders.CART (legacy PHP serialize).
     *
     * @param list<array{id?:int,name?:string,price?:float,price_original?:float}> $lines
     */
    public static function encodeCart(array $lines): string
    {
        $cart = [];
        foreach ($lines as $item) {
            if (!is_array($item)) {
                continue;
            }
            $price = (float)($item['price'] ?? 0);
            $cart[] = [
                'id' => (int)($item['id'] ?? 0),
                'name' => (string)($item['name'] ?? ''),
                'price' => $price,
                'price_original' => isset($item['price_original'])
                    ? (float)$item['price_original']
                    : $price,
            ];
        }
        return serialize($cart);
    }

    /**
     * Decode CART blob. Returns list of {id,name,price,price_original}.
     *
     * @return list<array{id:int,name:string,price:float,price_original:float}>
     */
    public static function decodeCart(?string $raw): array
    {
        $raw = (string)$raw;
        if ($raw === '') {
            return [];
        }
        $cart = self::safeUnserialize($raw);
        if (!is_array($cart)) {
            return [];
        }
        $out = [];
        foreach ($cart as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int)($item['id'] ?? 0);
            $price = (float)($item['price'] ?? 0);
            $orig = isset($item['price_original']) ? (float)$item['price_original'] : $price;
            $name = (string)($item['name'] ?? '');
            $out[] = [
                'id' => $id,
                'name' => $name,
                'price' => $price,
                'price_original' => $orig,
            ];
        }
        return $out;
    }

    /**
     * Unserialize legacy CART (may have broken s:N lengths after charset conversion).
     *
     * @return mixed
     */
    private static function safeUnserialize(string $str)
    {
        $try = static function (string $payload) {
            return @unserialize($payload, ['allowed_classes' => false]);
        };

        $data = $try($str);
        if ($data !== false || $str === serialize(false)) {
            return $data;
        }

        $fixed = self::fixSerializedLengths($str);
        if ($fixed !== $str) {
            $data = $try($fixed);
            if ($data !== false) {
                return $data;
            }
        }

        return false;
    }

    /** Recalculate s:N:"…" lengths (legacy oper fix_serialized_lengths). */
    private static function fixSerializedLengths(string $str): string
    {
        $fixed = $str;
        $offset = 0;
        while (preg_match('/s:(\d+):"/', $fixed, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = (int)$match[0][1];
            $declaredLength = (int)$match[1][0];
            $stringStart = $matchStart + strlen($match[0][0]);
            $stringContent = '';
            $pos = $stringStart;
            $foundEnd = false;
            $strLen = strlen($fixed);
            while ($pos < $strLen) {
                $char = $fixed[$pos];
                if ($char === '\\' && $pos + 1 < $strLen) {
                    $next = $fixed[$pos + 1];
                    if ($next === '"' || $next === '\\') {
                        $stringContent .= $char . $next;
                        $pos += 2;
                        continue;
                    }
                }
                if ($char === '"') {
                    $foundEnd = true;
                    break;
                }
                $stringContent .= $char;
                $pos++;
            }
            if (!$foundEnd) {
                break;
            }
            $actualLength = strlen($stringContent);
            if ($declaredLength !== $actualLength) {
                $old = 's:' . $declaredLength . ':"';
                $new = 's:' . $actualLength . ':"';
                $fixed = substr_replace($fixed, $new, $matchStart, strlen($old));
                $offset = $matchStart + strlen($new) + $actualLength + 1;
            } else {
                $offset = $stringStart + $actualLength + 1;
            }
            if ($offset >= strlen($fixed)) {
                break;
            }
        }
        return $fixed;
    }

    /**
     * Enrich cart lines with catalog product names when available.
     *
     * @param list<array{id:int,name:string,price:float,price_original:float}> $lines
     * @return list<array{id:int,name:string,price:float,price_original:float}>
     */
    public static function enrichCartNames(array $lines): array
    {
        if ($lines === [] || !Database::tableExists('base_products')) {
            return $lines;
        }
        $ids = [];
        foreach ($lines as $line) {
            if ($line['id'] > 0) {
                $ids[$line['id']] = true;
            }
        }
        if ($ids === []) {
            return $lines;
        }
        $idList = implode(',', array_map('intval', array_keys($ids)));
        $names = [];
        foreach (Database::pdo()->query(
            "SELECT id, name FROM base_products WHERE id IN ($idList)"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $names[(int)$row['id']] = (string)$row['name'];
        }
        foreach ($lines as $i => $line) {
            if ($line['name'] === '' && isset($names[$line['id']])) {
                $lines[$i]['name'] = $names[$line['id']];
            }
        }
        return $lines;
    }

    /**
     * Calendar helper: orders whose DT_EVENT_NEW falls in [start, end).
     *
     * @return list<array<string,mixed>>
     */
    public static function listForDayRange(int $scanStart, int $scanEnd, ?int $staffId = null): array
    {
        if (!Database::tableExists('base_orders') || $scanEnd <= $scanStart) {
            return [];
        }
        $where = 'DT_EVENT_NEW > ? AND DT_EVENT_NEW < ?';
        $params = [$scanStart, $scanEnd];
        if ($staffId !== null && $staffId > 0) {
            $where .= ' AND ID_COSMETOLOG = ?';
            $params[] = $staffId;
        }
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, UID AS uid, DT_EVENT_NEW AS dt_event, ID_USER AS id_user,
                    ID_COSMETOLOG AS id_cosmetolog, ID_EVENT_USE AS id_event_use,
                    PHONE AS phone, STATUS AS status, CART AS cart,
                    SUMM_REAL AS summ_real, FREE_TICKET AS free_ticket,
                    ID_PROMOCODE AS id_promocode, TEXT_PROMOCODE AS text_promocode
             FROM base_orders
             WHERE $where
             ORDER BY ID ASC"
        );
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::normalizeRow($row);
        }
        return $items;
    }

    public static function countByClientId(int $clientId): int
    {
        if ($clientId < 1 || !Database::tableExists('base_orders')) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_orders WHERE ID_USER = ?');
        $st->execute([$clientId]);
        return (int)$st->fetchColumn();
    }

    /**
     * Top-bar notice chips (legacy api.php Зак / ЗакБуд).
     *
     * @return list<array{short:string,href:string,title:string,tone:string}>
     */
    public static function noticesForUser(int $uid): array
    {
        if ($uid < 1 || !Database::tableExists('base_orders')) {
            return [];
        }
        if (!Auth::can('orders.view') || !Auth::capability('SHOW_ADMINISTRATOR_DATA')) {
            return [];
        }
        $out = [];
        $endToday = (int)mktime(23, 59, 59);
        $in = self::newStatusInSql();

        $today = self::countRaw(
            "STATUS IN ($in) AND DT_EVENT_NEW < ?",
            [$endToday]
        );
        if ($today > 0) {
            $out[] = [
                'short' => t('orders.notice.today_short', ['n' => $today]),
                'href' => '/orders?status=newToday',
                'title' => t('orders.notice.today_title'),
                'tone' => 'warn',
            ];
        }

        $allNew = self::countRaw("STATUS IN ($in)", []);
        if ($allNew > 0) {
            $out[] = [
                'short' => t('orders.notice.future_short', ['n' => $allNew]),
                'href' => '/orders?status=new',
                'title' => t('orders.notice.future_title'),
                'tone' => $allNew > 100 ? 'danger' : 'info',
            ];
        }
        return $out;
    }

    public static function countRaw(string $where, array $params): int
    {
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM base_orders WHERE $where");
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function buildWhere(array $opts): array
    {
        $parts = ['1=1'];
        $params = [];

        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $parts[] = 'o.COMMENT LIKE ?';
            $params[] = '%' . $q . '%';
        }

        $status = trim((string)($opts['status'] ?? 'all'));
        if ($status === '' || $status === 'all') {
            $parts[] = 'o.STATUS <> \'\' AND o.STATUS <> \'0\'';
        } elseif ($status === 'new') {
            $parts[] = 'o.STATUS IN (' . self::newStatusInSql() . ')';
        } elseif ($status === 'newToday') {
            $parts[] = 'o.STATUS IN (' . self::newStatusInSql() . ') AND o.DT_EVENT_NEW < ?';
            $params[] = (int)mktime(23, 59, 59);
        } elseif ($status === 'paymentProblem') {
            $parts[] = 'o.STATUS IN (\'failedPaySber\', \'userCancelPay\')';
        } elseif ($status === '0') {
            $parts[] = '(o.STATUS = \'\' OR o.STATUS = \'0\')';
        } elseif ($status === '1') {
            $parts[] = '(o.STATUS = \'\' OR o.STATUS = \'0\') AND o.DT_UPDATE_BY_USER > ?';
            $params[] = time() - 3600;
        } else {
            $parts[] = 'o.STATUS = ?';
            $params[] = $status;
        }

        $idUser = (int)($opts['id_user'] ?? 0);
        if ($idUser > 0) {
            $parts[] = 'o.ID_USER = ?';
            $params[] = $idUser;
        }

        $promo = (int)($opts['id_promocode'] ?? 0);
        if ($promo > 0) {
            $parts[] = 'o.ID_PROMOCODE = ?';
            $params[] = $promo;
        }

        $ft = $opts['free_ticket'] ?? null;
        if ($ft === '0' || $ft === '1') {
            $parts[] = 'o.FREE_TICKET = ?';
            $params[] = (int)$ft;
        }

        return [implode(' AND ', $parts), $params];
    }

    /**
     * Future advance orders for a client (Termy get_my_orders / booking list).
     *
     * @return list<array<string,mixed>>
     */
    public static function listFutureByClient(int $clientId, int $limit = 50): array
    {
        if ($clientId < 1 || !Database::tableExists('base_orders')) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $in = self::newStatusInSql();
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, UID AS uid, DT_CREATE AS dt_create, DT_EVENT_NEW AS dt_event,
                    ID_USER AS id_user, ID_COSMETOLOG AS id_cosmetolog, PHONE AS phone,
                    STATUS AS status, CART AS cart, SUMM AS summ, SUMM_REAL AS summ_real,
                    COMMENT AS comment, FREE_TICKET AS free_ticket
             FROM base_orders
             WHERE ID_USER = ? AND STATUS IN ($in) AND DT_EVENT_NEW >= ?
             ORDER BY DT_EVENT_NEW ASC, ID ASC
             LIMIT $limit"
        );
        $st->execute([$clientId, time() - 3600]);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $n = self::normalizeRow($row);
            $n['cart_lines'] = self::decodeCart((string)($row['cart'] ?? ''));
            $items[] = $n;
        }
        return $items;
    }

    /**
     * Client cancel of an advance order (legacy cancel_order → userCancelPay).
     *
     * @return array{ok:bool,cancelled?:int,error?:string}
     */
    public static function cancelForClient(int $orderId, int $clientId): array
    {
        if ($orderId < 1 || $clientId < 1) {
            return ['ok' => false, 'cancelled' => 0, 'error' => 'invalid ids'];
        }
        $order = self::find($orderId);
        if ($order === null) {
            return ['ok' => false, 'cancelled' => 0, 'error' => 'not found'];
        }
        if ((int)($order['id_user'] ?? 0) !== $clientId) {
            return ['ok' => false, 'cancelled' => 0, 'error' => 'forbidden'];
        }
        $status = (string)($order['status'] ?? '');
        if (!in_array($status, self::NEW_STATUSES, true)) {
            return ['ok' => false, 'cancelled' => 0, 'error' => 'not cancellable'];
        }
        self::update($orderId, ['status' => 'userCancelPay']);
        return ['ok' => true, 'cancelled' => 1];
    }

    private static function newStatusInSql(): string
    {
        return "'" . implode("','", self::NEW_STATUSES) . "'";
    }

    /** @param array<string,mixed> $row */
    private static function normalizeRow(array $row): array
    {
        foreach ([
            'id', 'id_user', 'id_cosmetolog', 'id_event_use', 'id_event_paid', 'id_sert',
            'id_promocode', 'dt_create', 'dt_update', 'dt_save', 'dt_event',
            'epaid', 'sber_status', 'summ_bonus', 'free_ticket', 'client_row_id',
        ] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (int)$row[$k];
            }
        }
        foreach (['summ', 'summ_real', 'summ_sber'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (float)$row[$k];
            }
        }
        if (isset($row['status'])) {
            $row['status'] = (string)$row['status'];
            if ($row['status'] === '') {
                $row['status'] = '0';
            }
        }
        return $row;
    }
}
