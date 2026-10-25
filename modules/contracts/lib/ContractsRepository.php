<?php
declare(strict_types=1);

final class ContractsRepository
{
    /** @var array<int, array{payment_id:int, sum:float, offer:int, mismatch:bool}> */
    private static $paymentWarnByComment = [];

    /** @var int */
    private static $paymentWarnNewsId = 0;

    /** cfg/modules/contracts.ini → [contracts] deadline_penalty (default on, oper-compatible). */
    public static function deadlinePenaltyEnabled(): bool
    {
        return (int)Config::get('contracts', 'deadline_penalty', 1) === 1;
    }

    /** Active in-work offer acceptor on ticket (0 if none). */
    public static function activeAcceptorOnTicket(int $newsId): int
    {
        static $cache = [];
        if ($newsId < 1) {
            return 0;
        }
        if (array_key_exists($newsId, $cache)) {
            return $cache[$newsId];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID_ACCEPTOR FROM base_comments
             WHERE ID_OBJECT = ? AND PRICE_OFFER > 0 AND DT_OFFER_ACCEPT > 0 AND DT_WORK_ACCEPT = 0
             ORDER BY ID DESC LIMIT 1'
        );
        $st->execute([$newsId]);
        return $cache[$newsId] = (int)$st->fetchColumn();
    }

    public static function isAcceptor(int $userId): bool
    {
        static $cache = [];
        if ($userId < 1) {
            return false;
        }
        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }
        $u = UsersRepository::find($userId);
        return $cache[$userId] = $u ? !empty($u['IS_ACCEPTOR']) : false;
    }

    public static function hasPayment(int $commentId): bool
    {
        static $cache = [];
        if ($commentId < 1) {
            return false;
        }
        if (array_key_exists($commentId, $cache)) {
            return $cache[$commentId];
        }
        if (Database::tableExists('base_payments')) {
            $st = Database::pdo()->prepare('SELECT 1 FROM base_payments WHERE ID_COMMENT = ? LIMIT 1');
            $st->execute([$commentId]);
            if ($st->fetchColumn()) {
                return $cache[$commentId] = true;
            }
        }
        $c = CommentsRepository::find($commentId);
        return $cache[$commentId] = ($c && (int)($c['ID_PAYMENT'] ?? 0) > 0);
    }

    public static function ticketHasActiveContract(int $newsId): bool
    {
        return self::activeAcceptorOnTicket($newsId) > 0;
    }

    /** @return array{label:string,tone:string} */
    public static function statusInfo(array $comment): array
    {
        $price = (int)($comment['PRICE_OFFER'] ?? 0);
        if ($price < 1) {
            return ['label' => '', 'tone' => ''];
        }
        $offerAt = (int)($comment['DT_OFFER_ACCEPT'] ?? 0);
        $workAt = (int)($comment['DT_WORK_ACCEPT'] ?? 0);
        $failAt = (int)($comment['DT_FAIL'] ?? 0);
        $dl = (int)($comment['DT_DEADLINE'] ?? 0);

        if ($failAt > 0) {
            return ['label' => t('contracts.status.failed', ['date' => I18n::formatDate($failAt, 'date')]), 'tone' => 'bad'];
        }
        if ($workAt > 0) {
            if ((int)($comment['ID_PAYMENT'] ?? 0) > 0) {
                return ['label' => t('contracts.status.paid'), 'tone' => 'ok'];
            }
            return ['label' => t('contracts.status.awaiting_payment'), 'tone' => 'warn'];
        }
        if ($offerAt > 0) {
            $remain = $dl > 0 ? ($dl - time()) : 0;
            $days = round($remain / 86400, 1);
            $tone = $remain > 0 ? 'ok' : 'bad';
            $extra = $dl > 0 ? (' · ' . t('contracts.status.days_left', ['d' => $days])) : '';
            return ['label' => t('contracts.status.in_work') . $extra, 'tone' => $tone];
        }
        if ($dl > 0 && $dl < time()) {
            return ['label' => t('contracts.status.overdue'), 'tone' => 'bad'];
        }
        $remain = $dl > 0 ? round(($dl - time()) / 86400, 1) : null;
        $extra = $remain !== null ? (' · ' . t('contracts.status.days', ['d' => $remain])) : '';
        return ['label' => t('contracts.status.new_offer') . $extra, 'tone' => 'ok'];
    }

    /**
     * What the current user can do with this offer comment.
     * @return array{accept_offer:bool,accept_work:bool,refuse:bool,cancel_work:bool,warn_fail:bool,fail_pct:int}
     */
    public static function availableActions(array $comment, int $uid): array
    {
        $out = [
            'accept_offer' => false,
            'accept_work' => false,
            'refuse' => false,
            'cancel_work' => false,
            'warn_fail' => false,
            'fail_pct' => 0,
        ];
        $price = (int)($comment['PRICE_OFFER'] ?? 0);
        if ($price < 1 || $uid < 1) {
            return $out;
        }
        $author = (int)$comment['ID_USER'];
        $acceptor = (int)($comment['ID_ACCEPTOR'] ?? 0);
        $offerAt = (int)($comment['DT_OFFER_ACCEPT'] ?? 0);
        $workAt = (int)($comment['DT_WORK_ACCEPT'] ?? 0);
        $failAt = (int)($comment['DT_FAIL'] ?? 0);
        $dl = (int)($comment['DT_DEADLINE'] ?? 0);
        $newsId = (int)$comment['ID_OBJECT'];

        if ($author === $uid) {
            return $out;
        }

        $ticketAcceptor = self::activeAcceptorOnTicket($newsId);
        $canAcceptOffers = self::isAcceptor($uid);
        if ($ticketAcceptor > 0) {
            $canAcceptOffers = $ticketAcceptor === $uid;
        }

        if (!$offerAt) {
            $offerOk = $dl <= 0 || $dl > time();
            if ($canAcceptOffers && $offerOk && ($acceptor === 0 || $acceptor === $uid)) {
                $out['accept_offer'] = true;
            }
            return $out;
        }

        if ($acceptor !== $uid) {
            return $out;
        }

        if (!$workAt) {
            $out['accept_work'] = true;
            $out['refuse'] = !self::hasPayment((int)$comment['ID']);
            $pct = self::timeUsedPercent($comment);
            $out['fail_pct'] = $pct;
            $out['warn_fail'] = $pct > 40;
            return $out;
        }

        if ($failAt < 1 && !self::hasPayment((int)$comment['ID'])) {
            $out['cancel_work'] = true;
        }
        return $out;
    }

    public static function timeUsedPercent(array $comment): int
    {
        $offerAt = (int)($comment['DT_OFFER_ACCEPT'] ?? 0);
        $dl = (int)($comment['DT_DEADLINE'] ?? 0);
        if ($offerAt < 1 || $dl < 1 || $dl <= $offerAt) {
            return 0;
        }
        $endOffer = self::dayEnd($offerAt);
        $endDl = self::dayEnd($dl);
        $total = $endDl - $endOffer;
        if ($total <= 0) {
            return 0;
        }
        $workAt = (int)($comment['DT_WORK_ACCEPT'] ?? 0);
        $usedEnd = $workAt > 0 ? self::dayEnd($workAt) : time();
        $used = $usedEnd - $endOffer;
        return (int)round($used * 100 / $total);
    }

    private static function dayEnd(int $ts): int
    {
        return (int)mktime(23, 59, 59, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts));
    }

    public static function acceptOffer(int $commentId, int $uid): void
    {
        $c = CommentsRepository::find($commentId);
        if (!$c) {
            throw new RuntimeException(t('comments.err.not_found'));
        }
        $actions = self::availableActions($c, $uid);
        if (empty($actions['accept_offer'])) {
            throw new RuntimeException(t('contracts.err.deny_accept_offer'));
        }
        $newsId = (int)$c['ID_OBJECT'];
        $now = time();
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'UPDATE base_comments SET DT_OFFER_ACCEPT = ?, ID_ACCEPTOR = ? WHERE ID = ?'
        );
        $st->execute([$now, $uid, $commentId]);

        $news = NewsRepository::find($newsId);
        if ($news) {
            NewsRepository::update($newsId, ['ID_CURATOR' => $uid], $uid);
            $resp = NewsRepository::parseUserList((string)($news['USERS_RESP'] ?? ''));
            $executor = (int)$c['ID_USER'];
            if ($executor > 0 && !in_array($executor, $resp, true)) {
                $resp[] = $executor;
                $respStr = $resp ? '#' . implode('#,#', $resp) . '#' : '';
                NewsRepository::update($newsId, ['USERS_RESP' => $respStr], $uid);
            }
        }
    }

    public static function acceptWork(int $commentId, int $uid): void
    {
        $c = CommentsRepository::find($commentId);
        if (!$c) {
            throw new RuntimeException(t('comments.err.not_found'));
        }
        $actions = self::availableActions($c, $uid);
        if (empty($actions['accept_work'])) {
            throw new RuntimeException(t('contracts.err.deny_accept_work'));
        }
        $st = Database::pdo()->prepare('UPDATE base_comments SET DT_WORK_ACCEPT = ? WHERE ID = ?');
        $st->execute([time(), $commentId]);
    }

    public static function refuse(int $commentId, int $uid): void
    {
        $c = CommentsRepository::find($commentId);
        if (!$c) {
            throw new RuntimeException(t('comments.err.not_found'));
        }
        $actions = self::availableActions($c, $uid);
        if (empty($actions['refuse'])) {
            throw new RuntimeException(t('contracts.err.deny_refuse'));
        }
        if (self::hasPayment($commentId)) {
            throw new RuntimeException(t('contracts.err.paid_no_cancel'));
        }
        $pct = self::timeUsedPercent($c);
        if ($pct > 40) {
            $st = Database::pdo()->prepare(
                'UPDATE base_comments SET DT_FAIL = ?, DT_WORK_ACCEPT = ? WHERE ID = ?'
            );
            $now = time();
            $st->execute([$now, $now, $commentId]);
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_comments SET DT_OFFER_ACCEPT = 0, DT_WORK_ACCEPT = 0, ID_ACCEPTOR = 0 WHERE ID = ?'
        );
        $st->execute([$commentId]);
    }

    public static function cancelWorkAccept(int $commentId, int $uid): void
    {
        $c = CommentsRepository::find($commentId);
        if (!$c) {
            throw new RuntimeException(t('comments.err.not_found'));
        }
        $actions = self::availableActions($c, $uid);
        if (empty($actions['cancel_work'])) {
            throw new RuntimeException(t('contracts.err.deny_cancel_accept'));
        }
        if (self::hasPayment($commentId)) {
            throw new RuntimeException(t('contracts.err.paid_no_cancel'));
        }
        $st = Database::pdo()->prepare('UPDATE base_comments SET DT_WORK_ACCEPT = 0 WHERE ID = ?');
        $st->execute([$commentId]);
    }

    /** @return array<string,string> request sort key => SQL expression */
    public static function listSortMap(): array
    {
        return [
            'id' => 'c.ID',
            'price' => 'c.PRICE_OFFER',
            'deadline' => 'c.DT_DEADLINE',
            'executor' => 'c.ID_USER',
            'acceptor' => 'c.ID_ACCEPTOR',
            'news' => 'n.NAME',
        ];
    }

    /**
     * List contracts with ACL scope.
     * @param array{status?:int,executor?:int,acceptor?:int,limit?:int,offset?:int,order?:string} $opts
     * @return array{items:list<array>,total:int,scoped:bool}
     */
    public static function listForUser(int $uid, array $opts = []): array
    {
        if ($uid < 1 || !Database::tableExists('base_comments')) {
            return ['items' => [], 'total' => 0, 'scoped' => true];
        }
        $super = Auth::capability('CONTROL_ACCESS');
        $executor = (int)($opts['executor'] ?? 0);
        $acceptor = (int)($opts['acceptor'] ?? 0);
        $status = (int)($opts['status'] ?? 0);
        $limit = (int)($opts['limit'] ?? 50);
        if ($limit < 0) {
            $limit = 0;
        } elseif ($limit > 0) {
            $limit = min(3000, max(1, $limit));
        }
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'c.ID DESC';
        }

        if (!$super) {
            // Non-superadmin cannot browse other people's contracts via filters.
            if ($executor > 0 && $executor !== $uid) {
                $executor = $uid;
            }
            if ($acceptor > 0 && $acceptor !== $uid) {
                $acceptor = $uid;
            }
        }

        $where = ['c.PRICE_OFFER <> 0'];
        $params = [];

        if (!$super) {
            $where[] = '(c.ID_USER = ? OR c.ID_ACCEPTOR = ?)';
            $params[] = $uid;
            $params[] = $uid;
        }
        if ($executor > 0) {
            $where[] = 'c.ID_USER = ?';
            $params[] = $executor;
        }
        if ($acceptor > 0) {
            $where[] = 'c.ID_ACCEPTOR = ?';
            $params[] = $acceptor;
        }

        $now = time();
        if ($status === 2) {
            $where[] = '(c.DT_OFFER_ACCEPT = 0 AND c.DT_DEADLINE < ?)';
            $params[] = $now;
        } elseif ($status === 3) {
            $where[] = '(c.DT_OFFER_ACCEPT > 0 AND c.DT_WORK_ACCEPT = 0 AND c.DT_DEADLINE < ?)';
            $params[] = $now;
        } elseif ($status === 4) {
            $where[] = '(c.DT_OFFER_ACCEPT > 0 AND c.DT_WORK_ACCEPT = 0 AND c.DT_DEADLINE > ?)';
            $params[] = $now;
        } elseif ($status === 5) {
            $where[] = '(c.DT_OFFER_ACCEPT > 0 AND c.DT_WORK_ACCEPT > 0 AND c.DT_FAIL = 0)';
        } elseif ($status === 6) {
            // Accepted work, not failed, unpaid — use denormalized ID_PAYMENT (NOT EXISTS on
            // base_payments is multi-second without a covering plan).
            $where[] = '(c.DT_OFFER_ACCEPT > 0 AND c.DT_WORK_ACCEPT > 0 AND c.DT_FAIL = 0)';
            $where[] = '(c.ID_PAYMENT = 0 OR c.ID_PAYMENT IS NULL)';
        } elseif ($status === 9) {
            $where[] = 'c.DT_FAIL > 0';
        } elseif ($status === 10) {
            $where[] = '(c.DT_OFFER_ACCEPT = 0 AND c.DT_DEADLINE > ?)';
            $params[] = $now;
        } elseif ($status === 11) {
            $where[] = '(c.DT_OFFER_ACCEPT > 0 AND c.DT_WORK_ACCEPT = 0 AND c.DT_FAIL = 0)';
        } elseif ($status === 0) {
            // Default: hide overdue new offers
            $where[] = 'NOT (c.DT_OFFER_ACCEPT = 0 AND c.DT_DEADLINE > 0 AND c.DT_DEADLINE < ?)';
            $params[] = $now;
        }

        $sqlWhere = implode(' AND ', $where);
        $pdo = Database::pdo();
        $stCount = $pdo->prepare("SELECT COUNT(*) FROM base_comments c WHERE $sqlWhere");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();

        $st = $pdo->prepare(
            "SELECT c.*, n.NAME AS NEWS_NAME, n.TYPE AS NEWS_TYPE
             FROM base_comments c
             LEFT JOIN base_news n ON n.ID = c.ID_OBJECT
             WHERE $sqlWhere
             ORDER BY $order"
            . ($limit > 0 ? " LIMIT $limit OFFSET $offset" : '')
        );
        $st->execute($params);
        return [
            'items' => $st->fetchAll() ?: [],
            'total' => $total,
            'scoped' => !$super,
        ];
    }

    /**
     * Notice chips for top bar (legacy api.php contract notices).
     * ACL: personal chips always scoped; «новые» / «к оплате» — superadmin или IS_ACCEPTOR (глобально), иначе только свои.
     *
     * @return list<array{short:string,href:string,title:string,tone:string}>
     */
    public static function noticesForUser(int $uid): array
    {
        if ($uid < 1 || !Database::tableExists('base_comments')) {
            return [];
        }
        $out = [];
        $seeAll = Auth::capability('CONTROL_ACCESS');
        $acceptor = self::isAcceptor($uid);
        $now = time();

        // Мои контракты в работе (я исполнитель)
        $mine = self::countRaw(
            'PRICE_OFFER <> 0 AND DT_OFFER_ACCEPT > 0 AND DT_WORK_ACCEPT = 0 AND DT_FAIL = 0 AND ID_USER = ?',
            [$uid]
        );
        if ($mine > 0) {
            $out[] = [
                'short' => t('contracts.notice.mine_short', ['n' => $mine]),
                'href' => '/contracts?status=11&party=executor',
                'title' => t('contracts.notice.mine_title'),
                'tone' => 'warn',
            ];
        }

        // Контракты к приёмке (я приёмщик)
        $toAccept = self::countRaw(
            'PRICE_OFFER <> 0 AND DT_OFFER_ACCEPT > 0 AND DT_WORK_ACCEPT = 0 AND DT_FAIL = 0 AND ID_ACCEPTOR = ?',
            [$uid]
        );
        if ($toAccept > 0) {
            $out[] = [
                'short' => t('contracts.notice.accept_short', ['n' => $toAccept]),
                'href' => '/contracts?status=11&party=acceptor',
                'title' => t('contracts.notice.accept_title'),
                'tone' => 'warn',
            ];
        }

        // Новые предложения
        if ($seeAll || $acceptor) {
            $new = self::countRaw(
                'PRICE_OFFER <> 0 AND DT_OFFER_ACCEPT = 0 AND DT_DEADLINE > ?',
                [$now]
            );
            if ($new > 0) {
                $out[] = [
                    'short' => t('contracts.notice.new_short', ['n' => $new]),
                    'href' => '/contracts?status=10',
                    'title' => t('contracts.notice.new_title'),
                    'tone' => 'info',
                ];
            }
        } else {
            $newMine = self::countRaw(
                'PRICE_OFFER <> 0 AND DT_OFFER_ACCEPT = 0 AND DT_DEADLINE > ? AND ID_USER = ?',
                [$now, $uid]
            );
            if ($newMine > 0) {
                $out[] = [
                    'short' => t('contracts.notice.new_short', ['n' => $newMine]),
                    'href' => '/contracts?status=10&party=executor',
                    'title' => t('contracts.notice.new_mine_title'),
                    'tone' => 'info',
                ];
            }
        }

        // К оплате — только быстрый флаг ID_PAYMENT (без скана base_payments)
        if ($seeAll) {
            $unpaid = self::countUnpaidAccepted();
            if ($unpaid > 0) {
                $out[] = [
                    'short' => t('contracts.notice.pay_short', ['n' => $unpaid]),
                    'href' => '/contracts?status=6',
                    'title' => t('contracts.notice.pay_title'),
                    'tone' => 'danger',
                ];
            }
        } else {
            $unpaidMine = self::countUnpaidAccepted($uid);
            if ($unpaidMine > 0) {
                $out[] = [
                    'short' => t('contracts.notice.pay_short', ['n' => $unpaidMine]),
                    'href' => '/contracts?status=6&party=acceptor',
                    'title' => t('contracts.notice.pay_title'),
                    'tone' => 'danger',
                ];
            }
        }

        return $out;
    }

    public static function countRaw(string $where, array $params): int
    {
        $st = Database::pdo()->prepare("SELECT COUNT(*) FROM base_comments WHERE $where");
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    /** Accepted+done without payment. Prefer ID_PAYMENT column (indexed path); optional payments join. */
    public static function countUnpaidAccepted(?int $acceptorId = null): int
    {
        // Fast path: comment.ID_PAYMENT flag (legacy list status=6)
        $where = 'PRICE_OFFER <> 0 AND DT_OFFER_ACCEPT > 0 AND DT_WORK_ACCEPT > 0 AND DT_FAIL = 0
                  AND (ID_PAYMENT = 0 OR ID_PAYMENT IS NULL)';
        $params = [];
        if ($acceptorId !== null && $acceptorId > 0) {
            $where .= ' AND ID_ACCEPTOR = ?';
            $params[] = $acceptorId;
        }
        return self::countRaw($where, $params);
    }

    /**
     * One batched payment lookup for all PRICE_OFFER comments on a news page.
     *
     * @param list<array<string,mixed>> $comments
     */
    public static function warmPaymentMismatch(int $newsId, array $comments): void
    {
        self::$paymentWarnNewsId = $newsId;
        self::$paymentWarnByComment = [];
        if ($newsId < 1 || $comments === [] || !ModuleManager::isEnabled('payments')
            || !class_exists('PaymentsRepository', false)) {
            return;
        }
        $offerIds = [];
        $payIdByComment = [];
        foreach ($comments as $c) {
            if (!is_array($c)) {
                continue;
            }
            $cid = (int)($c['ID'] ?? 0);
            $price = (int)($c['PRICE_OFFER'] ?? 0);
            if ($cid < 1 || $price < 1) {
                continue;
            }
            $offerIds[] = $cid;
            $pid = (int)($c['ID_PAYMENT'] ?? 0);
            if ($pid > 0) {
                $payIdByComment[$cid] = $pid;
            }
        }
        if ($offerIds === []) {
            return;
        }
        $sums = PaymentsRepository::sumsByCommentIds($offerIds, $payIdByComment);
        foreach ($offerIds as $cid) {
            if (!isset($sums[$cid])) {
                continue;
            }
            $paySum = (float)$sums[$cid]['sum'];
            $offer = 0;
            foreach ($comments as $c) {
                if ((int)($c['ID'] ?? 0) === $cid) {
                    $offer = (int)($c['PRICE_OFFER'] ?? 0);
                    break;
                }
            }
            // Compare to current offer (not deadline-cut payout): flags stale settlements after Re-®.
            $mismatch = (int)round($paySum) !== $offer;
            self::$paymentWarnByComment[$cid] = [
                'payment_id' => (int)$sums[$cid]['payment_id'],
                'sum' => $paySum,
                'offer' => $offer,
                'mismatch' => $mismatch,
            ];
        }
    }

    /** HTML block for comment contract controls. */
    public static function renderCommentActionsHtml(array $comment, int $newsId, int $uid): string
    {
        $price = (int)($comment['PRICE_OFFER'] ?? 0);
        if ($price < 1) {
            return '';
        }
        $info = self::statusInfo($comment);
        $actions = self::availableActions($comment, $uid);
        $cid = (int)$comment['ID'];
        $html = '<div class="contract-box">';
        $meta = '';
        if ($info['label'] !== '') {
            $tone = $info['tone'] !== '' ? ' is-' . $info['tone'] : '';
            $meta .= '<span class="contract-status' . $tone . '">' . e($info['label']) . '</span>';
        }
        $warn = null;
        if ($newsId === self::$paymentWarnNewsId && isset(self::$paymentWarnByComment[$cid])) {
            $warn = self::$paymentWarnByComment[$cid];
        } elseif (ModuleManager::isEnabled('payments') && class_exists('PaymentsRepository', false)
            && ((int)($comment['ID_PAYMENT'] ?? 0) > 0 || $newsId < 1)) {
            // Fallback single lookup when tree warm skipped (e.g. isolated render).
            $pidFallback = (int)($comment['ID_PAYMENT'] ?? 0);
            $map = PaymentsRepository::sumsByCommentIds(
                [$cid],
                $pidFallback > 0 ? [$cid => $pidFallback] : []
            );
            if (isset($map[$cid])) {
                $paySum = (float)$map[$cid]['sum'];
                $warn = [
                    'payment_id' => (int)$map[$cid]['payment_id'],
                    'sum' => $paySum,
                    'offer' => $price,
                    'mismatch' => (int)round($paySum) !== $price,
                ];
            }
        }
        if (is_array($warn) && !empty($warn['mismatch'])) {
            $meta .= '<span class="contract-warn" role="alert">'
                . e(t('contracts.warn.payment_mismatch', [
                    'offer' => (string)$price,
                    'pay' => (string)(int)round((float)$warn['sum']),
                    'id' => (string)(int)$warn['payment_id'],
                ]))
                . '</span>';
        }
        $acceptor = (int)($comment['ID_ACCEPTOR'] ?? 0);
        if ($acceptor > 0) {
            $meta .= '<span class="contract-curator">'
                . '<span class="muted">' . te('contracts.actions.curator') . ':</span> '
                . UsersRepository::badgeHtml($acceptor, false)
                . '</span>';
        }
        if ($meta !== '') {
            $html .= '<div class="contract-meta">' . $meta . '</div>';
        }
        $forms = '';
        if (!empty($actions['accept_offer'])) {
            $forms .= self::actionForm($cid, $newsId, 'accept_offer', t('contracts.actions.accept_offer'), 'btn-primary');
            $forms .= ' <span class="muted">' . te('contracts.actions.accept_offer_hint') . '</span>';
        }
        if (!empty($actions['accept_work'])) {
            $forms .= self::actionForm($cid, $newsId, 'accept_work', t('contracts.actions.accept_work'), 'btn-primary');
        }
        if (!empty($actions['refuse'])) {
            $forms .= self::actionForm($cid, $newsId, 'refuse', t('contracts.actions.refuse'), 'btn-danger');
            if (!empty($actions['warn_fail'])) {
                $forms .= ' <b class="contract-warn">'
                    . e(t('contracts.actions.fail_warn', ['pct' => (int)$actions['fail_pct']])) . '</b>';
            }
        }
        if (!empty($actions['cancel_work'])) {
            $forms .= self::actionForm($cid, $newsId, 'cancel_work_accept', t('contracts.actions.cancel_accept'), 'btn-secondary');
        }
        $forms .= self::paymentCtaHtml($comment);
        if ($forms !== '') {
            $html .= '<div class="contract-actions">' . $forms . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Oper show_contracts: link to create/open settlement payment for accepted unpaid offer.
     */
    public static function paymentCtaHtml(array $comment): string
    {
        if (!ModuleManager::isEnabled('payments')) {
            return '';
        }
        $price = (int)($comment['PRICE_OFFER'] ?? 0);
        $cid = (int)($comment['ID'] ?? 0);
        $paymentId = (int)($comment['ID_PAYMENT'] ?? 0);
        $awaitingPay = (int)($comment['DT_WORK_ACCEPT'] ?? 0) > 0
            && (int)($comment['DT_FAIL'] ?? 0) < 1
            && $paymentId < 1
            && $price > 0
            && $cid > 0;
        if ($awaitingPay && Auth::can('payments.edit')) {
            return ' <a class="btn-primary" href="/payments/new?id_comment=' . $cid . '">'
                . e(t('payments.contract.pay')) . '</a>'
                . ' <span class="muted">' . e(t('contracts.list.pay_dps', ['id' => $cid])) . '</span>';
        }
        // Already paid: status line shows “оплачен”; no bulky button.
        return '';
    }

    private static function actionForm(int $cid, int $newsId, string $action, string $label, string $btnClass): string
    {
        return '<form method="post" action="/contracts/action" class="inline-form">'
            . '<input type="hidden" name="action" value="' . e($action) . '">'
            . '<input type="hidden" name="comment_id" value="' . $cid . '">'
            . '<input type="hidden" name="news_id" value="' . $newsId . '">'
            . '<button type="submit" class="' . e($btnClass) . '">' . e($label) . '</button>'
            . '</form>';
    }
}
