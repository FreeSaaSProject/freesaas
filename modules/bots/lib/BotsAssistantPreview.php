<?php
declare(strict_types=1);

/** Confirm draft lines for bots.message.send. */
final class BotsAssistantPreview
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onWritePreview(array $payload): array
    {
        $opId = (string)($payload['op_id'] ?? '');
        if ($opId !== 'bots.message.send') {
            return $payload;
        }
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        $text = trim((string)($args['text'] ?? ''));
        $userId = (int)($args['user_id'] ?? 0);
        $clientId = (int)($args['client_id'] ?? 0);
        $chatId = trim((string)($args['chat_id'] ?? ''));
        $to = '';
        if ($userId > 0 && class_exists('UsersRepository', false)) {
            $to = UsersRepository::nameById($userId);
        } elseif ($clientId > 0) {
            $to = '#' . $clientId;
            if (class_exists('ClientsRepository', false)) {
                $c = ClientsRepository::find($clientId);
                if (is_array($c)) {
                    $n = trim((string)($c['name'] ?? ''));
                    $tel = trim((string)($c['tel'] ?? ''));
                    $to = $n !== '' ? $n : ($tel !== '' ? $tel : $to);
                }
            }
        } elseif ($chatId !== '') {
            $to = $chatId;
            if (class_exists('BotRecipients', false)) {
                foreach (BotRecipients::listChats('', 200) as $c) {
                    if ((string)($c['chat_id'] ?? '') === $chatId) {
                        $title = trim((string)($c['title'] ?? ''));
                        $descr = trim((string)($c['description'] ?? ''));
                        if ($title !== '' && $descr !== '') {
                            $to = $title . ' — ' . $descr . ' (' . $chatId . ')';
                        } elseif ($title !== '') {
                            $to = $title . ' (' . $chatId . ')';
                        } elseif ($descr !== '') {
                            $to = $descr . ' (' . $chatId . ')';
                        }
                        break;
                    }
                }
            }
        }
        $snippet = $text;
        if ($snippet !== '' && function_exists('mb_strlen') && mb_strlen($snippet, 'UTF-8') > 160) {
            $snippet = mb_substr($snippet, 0, 157, 'UTF-8') . '…';
        } elseif (strlen($snippet) > 160) {
            $snippet = substr($snippet, 0, 157) . '…';
        }
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        $lines[] = t('bots.assistant.preview.to', ['v' => $to !== '' ? $to : '—']);
        if ($snippet !== '') {
            $lines[] = t('bots.assistant.preview.text', ['v' => $snippet]);
        }
        $payload['lines'] = $lines;
        $payload['handled'] = true;
        return $payload;
    }
}
