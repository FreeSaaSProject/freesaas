<?php
declare(strict_types=1);

/** Outbound email queue (base_mail_sends). */
final class MailSendsRepository
{
    public const STATUS_PENDING = 1;
    public const STATUS_SENT = 2;
    public const STATUS_ERROR = 3;
    public const STATUS_SKIPPED = 100;

    /** Seconds a claimed row is hidden from other workers (crash → retried afterwards). */
    public const CLAIM_LEASE = 600;

    public static function tableReady(): bool
    {
        return Database::tableExists('base_mail_sends');
    }

    /**
     * @param array{
     *   to:string,to_name?:string,subject:string,text?:string,html?:string,
     *   from?:string,reply_to?:string,dedupe_key?:string,client_id?:int,
     *   id_operator?:int,dtsend?:int,meta?:array<string,mixed>|null
     * } $opts
     */
    public static function enqueue(array $opts): int
    {
        if (!self::tableReady()) {
            throw new RuntimeException(t('mail.err.table'));
        }
        $to = SmtpClient::cleanAddr((string)($opts['to'] ?? ''));
        if ($to === '') {
            throw new InvalidArgumentException(t('mail.err.recipient'));
        }
        $subject = trim(preg_replace('/\s*[\r\n]+\s*/', ' ', (string)($opts['subject'] ?? '')) ?? '');
        $text = (string)($opts['text'] ?? '');
        $html = (string)($opts['html'] ?? '');
        if ($subject === '' || (trim($text) === '' && trim($html) === '')) {
            throw new InvalidArgumentException(t('mail.err.enqueue_args'));
        }
        $from = trim((string)($opts['from'] ?? ''));
        if ($from !== '' && SmtpClient::cleanAddr($from) === '') {
            throw new InvalidArgumentException(t('mail.err.email', ['field' => 'from']));
        }
        $replyTo = trim((string)($opts['reply_to'] ?? ''));
        if ($replyTo !== '' && SmtpClient::cleanAddr($replyTo) === '') {
            throw new InvalidArgumentException(t('mail.err.email', ['field' => 'reply_to']));
        }

        $dedupe = substr(trim((string)($opts['dedupe_key'] ?? '')), 0, 64);
        if ($dedupe !== '') {
            $st = Database::pdo()->prepare(
                'SELECT ID FROM base_mail_sends WHERE DEDUPE_KEY = ? AND TO_ADDR = ? AND STATUS = ? LIMIT 1'
            );
            $st->execute([$dedupe, $to, self::STATUS_PENDING]);
            $existing = (int)$st->fetchColumn();
            if ($existing > 0) {
                return $existing;
            }
        }

        $meta = $opts['meta'] ?? null;
        $metaJson = null;
        if (is_array($meta) && $meta !== []) {
            $enc = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $metaJson = $enc === false ? null : $enc;
        }

        $now = time();
        $dtsend = (int)($opts['dtsend'] ?? $now);
        if ($dtsend <= 0) {
            $dtsend = $now;
        }
        $toName = trim(preg_replace('/\s*[\r\n]+\s*/', ' ', (string)($opts['to_name'] ?? '')) ?? '');

        $st = Database::pdo()->prepare(
            'INSERT INTO base_mail_sends
                (DTCREATE, DTSEND, STATUS, TO_ADDR, TO_NAME, SUBJECT, BODY_TEXT, BODY_HTML, FROM_ADDR, REPLY_TO,
                 DEDUPE_KEY, ID_OPERATOR, CLIENT_ID, META_JSON)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $now,
            $dtsend,
            self::STATUS_PENDING,
            $to,
            $toName !== '' ? substr($toName, 0, 255) : null,
            substr($subject, 0, 500),
            $text !== '' ? $text : null,
            $html !== '' ? $html : null,
            $from !== '' ? $from : null,
            $replyTo !== '' ? $replyTo : null,
            $dedupe !== '' ? $dedupe : null,
            (int)($opts['id_operator'] ?? 0),
            (int)($opts['client_id'] ?? 0),
            $metaJson,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_mail_sends WHERE ID = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array{status?:int,q?:string,limit?:int,offset?:int,order?:string} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $where = ['1=1'];
        $params = [];
        if (isset($opts['status']) && (int)$opts['status'] >= 0) {
            $where[] = 'STATUS = ?';
            $params[] = (int)$opts['status'];
        }
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(TO_ADDR LIKE ? OR SUBJECT LIKE ? OR CAST(ID AS CHAR) = ? OR CAST(CLIENT_ID AS CHAR) = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
            $params[] = $q;
        }
        $sqlWhere = implode(' AND ', $where);
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_mail_sends WHERE ' . $sqlWhere);
        $st->execute($params);
        $total = (int)$st->fetchColumn();

        $limit = (int)($opts['limit'] ?? 50);
        if ($limit < 0) {
            $limit = 0;
        } elseif ($limit > 0) {
            $limit = min(3000, max(1, $limit));
        }
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $order = trim((string)($opts['order'] ?? '')) ?: 'ID DESC';
        // Bodies are not needed for the list (can be large).
        $sql = 'SELECT ID, DTCREATE, DTSEND, STATUS, TO_ADDR, TO_NAME, SUBJECT, FROM_ADDR, REPLY_TO, ERROR,
                       STATUS_DESCR, DEDUPE_KEY, ID_OPERATOR, CLIENT_ID
                FROM base_mail_sends WHERE ' . $sqlWhere . ' ORDER BY ' . $order;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'status' => 'STATUS',
            'dtsend' => 'DTSEND',
            'to' => 'TO_ADDR',
        ];
    }

    public static function countPending(): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT COUNT(*) FROM base_mail_sends WHERE STATUS = ? AND DTSEND <= ?');
        $st->execute([self::STATUS_PENDING, time()]);
        return (int)$st->fetchColumn();
    }

    /**
     * Claim due pending rows (lease via DTSEND bump so parallel drains never double-send).
     *
     * @return list<array<string,mixed>>
     */
    public static function claimPending(int $limit): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $limit = min(100, max(1, $limit));
        $st = Database::pdo()->prepare(
            'SELECT ID, DTSEND FROM base_mail_sends WHERE STATUS = ? AND DTSEND <= ? ORDER BY DTSEND ASC, ID ASC LIMIT ' . $limit
        );
        $st->execute([self::STATUS_PENDING, time()]);
        $cand = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($cand as $c) {
            $row = self::claimRow((int)$c['ID'], (int)$c['DTSEND']);
            if ($row !== null) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** Claim one pending row by id (immediate send), or null when not pending / already claimed. */
    public static function claimById(int $id): ?array
    {
        $row = self::find($id);
        if ($row === null || (int)$row['STATUS'] !== self::STATUS_PENDING) {
            return null;
        }
        return self::claimRow($id, (int)$row['DTSEND']);
    }

    /** @return array<string,mixed>|null */
    private static function claimRow(int $id, int $dtsend): ?array
    {
        $up = Database::pdo()->prepare(
            'UPDATE base_mail_sends SET DTSEND = ? WHERE ID = ? AND STATUS = ? AND DTSEND = ?'
        );
        $up->execute([time() + self::CLAIM_LEASE, $id, self::STATUS_PENDING, $dtsend]);
        if ($up->rowCount() < 1) {
            return null;
        }
        return self::find($id);
    }

    public static function mark(int $id, int $status, string $descr = '', string $error = ''): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_mail_sends SET STATUS = ?, DTSEND = ?, STATUS_DESCR = ?, ERROR = ? WHERE ID = ?'
        );
        $st->execute([
            $status,
            time(),
            $descr !== '' ? substr($descr, 0, 255) : null,
            $error !== '' ? substr($error, 0, 512) : null,
            $id,
        ]);
    }
}
