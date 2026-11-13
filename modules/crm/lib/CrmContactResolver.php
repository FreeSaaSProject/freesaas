<?php
declare(strict_types=1);

/** Resolve a contact (base_clients) by id or phone; create it when allowed. Phone is the client identity. */
final class CrmContactResolver
{
    public static function normalizePhone(string $tel): string
    {
        $digits = preg_replace('/\D+/', '', $tel) ?? '';
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        return $digits;
    }

    /**
     * @param array<string,mixed> $in client_id | tel (+ name, mail)
     * @return array{client_id:int,created:bool}
     */
    public static function resolve(array $in): array
    {
        $clientId = (int)($in['client_id'] ?? 0);
        if ($clientId > 0) {
            if (ClientsRepository::find($clientId) === null) {
                throw new InvalidArgumentException(t('crm.err.contact_not_found'));
            }
            return ['client_id' => $clientId, 'created' => false];
        }
        $telRaw = trim((string)($in['tel'] ?? ''));
        if ($telRaw === '') {
            throw new InvalidArgumentException(t('crm.err.contact_phone_required'));
        }
        $found = ClientsRepository::findByPhoneDigits($telRaw);
        if ($found !== null) {
            return ['client_id' => (int)$found['id'], 'created' => false];
        }
        if (!Auth::can('clients.edit')) {
            throw new RuntimeException(t('crm.err.contact_create_forbidden'));
        }
        $tel = self::normalizePhone($telRaw);
        if (strlen($tel) < 10) {
            throw new InvalidArgumentException(t('crm.err.contact_phone_invalid'));
        }
        $data = ClientsRepository::dataFromPost([
            'name' => trim((string)($in['name'] ?? '')),
            'tel' => $tel,
            'mail' => trim((string)($in['mail'] ?? '')),
            'enabled' => '1',
        ]);
        return ['client_id' => ClientsRepository::create($data), 'created' => true];
    }
}
