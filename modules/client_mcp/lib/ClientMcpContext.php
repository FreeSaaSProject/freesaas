<?php
declare(strict_types=1);

/**
 * Public API for client MCP tool handlers (own and other modules'):
 * current storefront host, client session by client_token, order ownership, payment block.
 */
final class ClientMcpContext
{
    /** @return array{id:int,host:string,name:string,base:string,settings:array<string,mixed>} */
    public static function host(): array
    {
        $h = ClientMcpHost::currentEnabled();
        if ($h === null) {
            throw new ClientMcpToolError('not_available', t('client_mcp.err.disabled'));
        }
        return $h;
    }

    /**
     * Live session from args.client_token for the current host; throws invalid_token.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function session(array $args): array
    {
        $s = self::optionalSession($args);
        if ($s === null) {
            throw new ClientMcpToolError('invalid_token', t('client_mcp.err.token'));
        }
        return $s;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    public static function optionalSession(array $args): ?array
    {
        $token = trim((string)($args['client_token'] ?? ''));
        if ($token === '') {
            return null;
        }
        return ClientMcpSessionsRepository::findByToken($token, self::host()['id']);
    }

    /** Session with proven client identity (verifier subscribed and code accepted). */
    public static function isVerified(array $session): bool
    {
        return !empty($session['verified']) && (int)($session['client_id'] ?? 0) > 0;
    }

    /** Record an order created through this session (enables my_bookings / cancel for it). */
    public static function rememberOrder(array $session, int $orderId, int $clientId): void
    {
        ClientMcpSessionsRepository::addOrder((int)$session['id'], $orderId, $clientId);
    }

    /**
     * Session may see / change this order: created in the session, or verified owner.
     *
     * @param array<string,mixed> $session
     * @param array<string,mixed> $order row with id + id_user
     */
    public static function ownsOrder(array $session, array $order): bool
    {
        $orderId = (int)($order['id'] ?? 0);
        if ($orderId > 0 && in_array($orderId, (array)($session['order_ids'] ?? []), true)) {
            return true;
        }
        return self::isVerified($session) && (int)($order['id_user'] ?? 0) === (int)$session['client_id'];
    }

    /**
     * Payment block for an order; acquiring modules fill url/qr/expires via EventBus client_mcp.payment.
     *
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    public static function payment(array $order, float $amount, bool $paid): array
    {
        $payment = self::paymentPolicy() + [
            'status' => $paid ? 'paid' : 'unpaid',
            'amount' => round($amount, 2),
            'currency' => ClientMcpConfig::currency(),
            'url' => '',
            'qr' => '',
            'expires_at' => '',
        ];
        $hook = EventBus::emit('client_mcp.payment', [
            'order' => $order,
            'payment' => $payment,
            'site_host_id' => self::host()['id'],
        ]);
        return is_array($hook['payment'] ?? null) ? $hook['payment'] : $payment;
    }

    /** @return array{required:bool,mode:string,instructions:string} */
    public static function paymentPolicy(): array
    {
        return [
            'required' => false,
            'mode' => 'pay_on_site',
            'instructions' => t('client_mcp.payment.on_site'),
        ];
    }

    public static function businessPhone(): string
    {
        return (string)(SitesFront::siteMeta()['tel'] ?? '');
    }

    public static function absoluteUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '' || preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return self::host()['base'] . '/' . ltrim($path, '/');
    }

    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');
        return mb_substr($name, 0, 120);
    }

    public static function clientIp(): string
    {
        return mb_substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
    }
}
