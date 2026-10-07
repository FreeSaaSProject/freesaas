<?php
declare(strict_types=1);

/** Tickets + report comments for staff quality reports. */
final class TicketWriter
{
    public static function robotId(): int
    {
        $id = (int)Config::get('connect', 'robot_id', 0);
        if ($id > 0) {
            return $id;
        }
        return self::firstDirectorId();
    }

    public static function firstDirectorId(): int
    {
        if (!Database::tableExists('base_users') || !Database::tableExists('base_admin_statuses')) {
            return 0;
        }
        $st = Database::pdo()->query(
            "SELECT u.id FROM base_users u
             INNER JOIN base_admin_statuses s ON s.ID = u.ID_STATUS
             WHERE u.enabled = 1 AND s.SUPERADMIN_ACCESS > 0
             ORDER BY u.id ASC LIMIT 1"
        );
        return $st ? (int)$st->fetchColumn() : 0;
    }

    public static function ticketTitle(int $employeeId, array $config): string
    {
        $prefix = (string)($config['ticket_title_prefix'] ?? '[Отчеты:');
        $name = self::employeeName($employeeId);
        return $prefix . $employeeId . '] ' . $name;
    }

    public static function employeeName(int $employeeId): string
    {
        if ($employeeId < 1 || !Database::tableExists('base_users')) {
            return '#' . $employeeId;
        }
        $st = Database::pdo()->prepare('SELECT name FROM base_users WHERE id = ? LIMIT 1');
        $st->execute([$employeeId]);
        $name = trim((string)$st->fetchColumn());
        return $name !== '' ? $name : ('#' . $employeeId);
    }

    public static function findTicketId(int $employeeId, array $config): int
    {
        if ($employeeId < 1 || !Database::tableExists('base_news')) {
            return 0;
        }
        $title = self::ticketTitle($employeeId, $config);
        $st = Database::pdo()->prepare(
            "SELECT ID FROM base_news WHERE NAME = ? AND TYPE = '2' AND DISABLED = '0' LIMIT 1"
        );
        $st->execute([$title]);
        return (int)$st->fetchColumn();
    }

    public static function findOrCreateTicket(int $employeeId, array $config): int
    {
        $id = self::findTicketId($employeeId, $config);
        if ($id > 0) {
            self::ensureTicketMeta($id, $config);
            return $id;
        }
        return self::createTicket($employeeId, $config);
    }

    public static function createTicket(int $employeeId, array $config): int
    {
        if ($employeeId < 1 || !Database::tableExists('base_news')) {
            return 0;
        }
        $authorId = self::robotId();
        if ($authorId < 1) {
            $authorId = $employeeId;
        }
        $curator = self::firstDirectorId();
        if ($curator < 1) {
            $curator = $authorId;
        }
        $body = (string)($config['ticket_default_body'] ?? 'Здесь формируются отчёты по работе');
        $id = NewsRepository::create([
            'NAME' => self::ticketTitle($employeeId, $config),
            'BIG' => nl2br($body),
            'SHORT' => '',
            'TYPE' => 2,
            'STATUS' => max(0, (int)($config['ticket_status_id'] ?? 4)),
            'ID_CURATOR' => $curator,
            'DT_DEADLINE' => 0,
            'USERS_RESP' => '#' . $employeeId . '#',
            'ACCESS_USER' => '#' . $employeeId . '#',
            'PID_CONTRACT' => max(0, (int)($config['managing_contract_id'] ?? 0)),
            'COMMENTS_LOCKED' => 0,
            'HAS_CHILDREN' => 0,
            'PID' => 0,
        ], $authorId);
        // Author of ticket should be the employee (legacy parity) when robot created it.
        if ($id > 0 && Database::tableExists('base_news')) {
            Database::pdo()->prepare('UPDATE base_news SET ID_USER = ? WHERE ID = ?')->execute([$employeeId, $id]);
        }
        return $id;
    }

    public static function ensureTicketMeta(int $ticketId, array $config): void
    {
        if ($ticketId < 1 || !Database::tableExists('base_news')) {
            return;
        }
        $statusId = max(0, (int)($config['ticket_status_id'] ?? 4));
        $contractId = max(0, (int)($config['managing_contract_id'] ?? 0));
        $data = [];
        if ($statusId > 0) {
            $data['STATUS'] = $statusId;
            $data['DT_DEADLINE'] = 0;
        }
        if ($contractId > 0) {
            $data['PID_CONTRACT'] = $contractId;
        }
        if ($data !== []) {
            NewsRepository::update($ticketId, $data, self::robotId() ?: 1);
        }
    }

    public static function findCommentId(int $ticketId, string $marker): int
    {
        if ($ticketId < 1 || $marker === '' || !Database::tableExists('base_comments')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_comments
             WHERE ID_OBJECT = ? AND TEXT LIKE ?
             ORDER BY ID ASC LIMIT 1'
        );
        $st->execute([$ticketId, '%' . $marker . '%']);
        return (int)$st->fetchColumn();
    }

    /**
     * @return array{comment_id:int,changed:bool}
     */
    public static function upsertReportComment(int $ticketId, int $authorId, string $marker, string $text): array
    {
        $text = trim($text);
        if ($ticketId < 1 || $authorId < 1 || $text === '') {
            return ['comment_id' => 0, 'changed' => false];
        }
        // Store with newlines → br for legacy HTML comments.
        $stored = nl2br($text);
        $existing = self::findCommentId($ticketId, $marker);
        if ($existing > 0) {
            $row = CommentsRepository::find($existing);
            $cur = $row ? (string)($row['TEXT'] ?? '') : '';
            if ($cur === $stored) {
                return ['comment_id' => $existing, 'changed' => false];
            }
            CommentsRepository::updateText($existing, $stored);
            return ['comment_id' => $existing, 'changed' => true];
        }
        $id = CommentsRepository::add($ticketId, $authorId, $stored);
        return ['comment_id' => $id, 'changed' => $id > 0];
    }

    /**
     * Month reward totals from published report comments (exclude current day marker).
     * @return array{earned:int,missed:int,positive_count:int,negative_count:int}
     */
    public static function monthRewardTotals(int $ticketId, int $dayStart, bool $currentGranted, int $currentSum): array
    {
        $totals = [
            'earned' => 0,
            'missed' => 0,
            'positive_count' => 0,
            'negative_count' => 0,
        ];
        if ($ticketId < 1 || $dayStart < 1 || !Database::tableExists('base_comments')) {
            if ($currentGranted) {
                $totals['earned'] += $currentSum;
                $totals['positive_count']++;
            } else {
                $totals['missed'] += $currentSum;
                $totals['negative_count']++;
            }
            return $totals;
        }
        $y = (int)date('Y', $dayStart);
        $m = (int)date('n', $dayStart);
        $targetDay = date('d.m.Y', $dayStart);
        $byDay = [];
        $st = Database::pdo()->prepare(
            "SELECT ID, TEXT FROM base_comments
             WHERE ID_OBJECT = ? AND (DISABLED IS NULL OR DISABLED < 1) AND TEXT LIKE '%Отчёт за %'
             ORDER BY ID ASC"
        );
        $st->execute([$ticketId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $plain = html_entity_decode(strip_tags((string)$row['TEXT']), ENT_QUOTES, 'UTF-8');
            if (!preg_match('/Отчёт за (\d{2})\.(\d{2})\.(\d{4})/u', $plain, $dm)) {
                continue;
            }
            $rd = (int)$dm[1];
            $rm = (int)$dm[2];
            $ry = (int)$dm[3];
            if ($ry !== $y || $rm !== $m) {
                continue;
            }
            $code = sprintf('%02d.%02d.%04d', $rd, $rm, $ry);
            if ($code === $targetDay) {
                continue;
            }
            if (!preg_match('/Вознаграждение за \d{2}\.\d{2}\.\d{4} в (\d+)\s*[^\n]*-\s*(НАЧИСЛЯЕТСЯ|НЕ\s+начисляется)/u', $plain, $rmatch)) {
                continue;
            }
            $byDay[$code] = [
                'sum' => (int)$rmatch[1],
                'granted' => ($rmatch[2] === 'НАЧИСЛЯЕТСЯ'),
            ];
        }
        foreach ($byDay as $rep) {
            if (!empty($rep['granted'])) {
                $totals['earned'] += (int)$rep['sum'];
                $totals['positive_count']++;
            } else {
                $totals['missed'] += (int)$rep['sum'];
                $totals['negative_count']++;
            }
        }
        if ($currentGranted) {
            $totals['earned'] += $currentSum;
            $totals['positive_count']++;
        } else {
            $totals['missed'] += $currentSum;
            $totals['negative_count']++;
        }
        return $totals;
    }
}
