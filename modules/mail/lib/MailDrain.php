<?php
declare(strict_types=1);

/** Drain pending base_mail_sends through SmtpClient. */
final class MailDrain
{
    /**
     * @return array{processed:int,sent:int,skipped:int,errors:int,configured:bool,items:list<array<string,mixed>>}
     */
    public static function run(int $limit = 20): array
    {
        $out = [
            'processed' => 0,
            'sent' => 0,
            'skipped' => 0,
            'errors' => 0,
            'configured' => MailConfig::isConfigured(),
            'items' => [],
        ];
        // Not configured: leave rows pending so they go out once SMTP is set up.
        if (!$out['configured']) {
            return $out;
        }
        foreach (MailSendsRepository::claimPending($limit) as $row) {
            self::tally($out, self::processRow($row));
        }
        return $out;
    }

    /**
     * Send one pending row right now (test send / UI "send now").
     *
     * @return array<string,mixed> result item; status=error with reason when not sendable
     */
    public static function runOne(int $id): array
    {
        if (!MailConfig::isConfigured()) {
            return ['id' => $id, 'status' => 'error', 'reason' => t('mail.err.not_configured')];
        }
        $row = MailSendsRepository::claimById($id);
        if ($row === null) {
            return ['id' => $id, 'status' => 'error', 'reason' => t('mail.err.not_pending')];
        }
        return self::processRow($row);
    }

    /**
     * @param array<string,mixed> $out
     * @param array<string,mixed> $item
     */
    private static function tally(array &$out, array $item): void
    {
        $out['processed']++;
        $out['items'][] = $item;
        $status = (string)($item['status'] ?? '');
        if ($status === 'sent') {
            $out['sent']++;
        } elseif ($status === 'skipped') {
            $out['skipped']++;
        } else {
            $out['errors']++;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function processRow(array $row): array
    {
        $id = (int)($row['ID'] ?? 0);
        $to = (string)($row['TO_ADDR'] ?? '');

        if (SmtpClient::cleanAddr($to) === '') {
            MailSendsRepository::mark($id, MailSendsRepository::STATUS_SKIPPED, t('mail.send.skipped_recipient'));
            return ['id' => $id, 'to' => $to, 'status' => 'skipped', 'reason' => 'invalid_recipient'];
        }

        $cfg = MailConfig::all();
        $from = trim((string)($row['FROM_ADDR'] ?? ''));
        if ($from === '') {
            $from = $cfg['from_email'];
        }
        $replyTo = trim((string)($row['REPLY_TO'] ?? ''));
        if ($replyTo === '') {
            $replyTo = $cfg['reply_to'];
        }

        try {
            $res = SmtpClient::send([
                'host' => $cfg['smtp_host'],
                'port' => (int)$cfg['smtp_port'],
                'encryption' => $cfg['smtp_encryption'],
                'user' => $cfg['smtp_user'],
                'pass' => $cfg['smtp_pass'],
                'from' => $from,
                'from_name' => $cfg['from_name'],
                'reply_to' => $replyTo,
                'to' => $to,
                'to_name' => (string)($row['TO_NAME'] ?? ''),
                'subject' => (string)($row['SUBJECT'] ?? ''),
                'text' => (string)($row['BODY_TEXT'] ?? ''),
                'html' => (string)($row['BODY_HTML'] ?? ''),
            ]);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage(), 'message_id' => ''];
        }

        if (!empty($res['ok'])) {
            MailSendsRepository::mark(
                $id,
                MailSendsRepository::STATUS_SENT,
                t('mail.send.sent') . ((string)($res['message_id'] ?? '') !== '' ? ' ' . $res['message_id'] : '')
            );
            return ['id' => $id, 'to' => $to, 'status' => 'sent', 'message_id' => (string)($res['message_id'] ?? '')];
        }
        $err = (string)($res['error'] ?? '');
        if ($err === '') {
            $err = t('mail.send.err_send');
        }
        MailSendsRepository::mark($id, MailSendsRepository::STATUS_ERROR, t('mail.send.err_send'), $err);
        return ['id' => $id, 'to' => $to, 'status' => 'error', 'reason' => $err];
    }
}
