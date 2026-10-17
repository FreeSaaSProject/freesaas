<?php
declare(strict_types=1);

/** Core client MCP tools: business card + client identity (phone → client_token). */
final class ClientMcpCoreTools
{
    /**
     * EventBus client_mcp.tools subscriber.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onTools($payload)
    {
        if (!is_array($payload)) {
            return $payload;
        }
        $write = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
        $token = ['type' => 'string', 'description' => 'client_token returned by identify_client.'];

        $payload['tools'][] = [
            'name' => 'business_info',
            'title' => 'Business info and rules',
            'description' => 'Name, phone, website, timezone, currency, payment policy, consent/offer link and the '
                . 'registration package (fields other tools need). Call first.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'handler' => [self::class, 'businessInfo'],
            'module' => 'client_mcp',
            'core' => true,
            'order' => 0,
            'instructions' => 'Call business_info first. Never invent prices, times or services; use tools. '
                . 'Ask the user before every write. Keep client_token private.',
        ];
        $payload['tools'][] = [
            'name' => 'identify_client',
            'title' => 'Identify client by phone',
            'description' => 'Start a client session with the phone number the user gave you (and name for new clients). '
                . 'Returns client_token and required_fields still missing. Ask the user; never guess the phone.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'phone' => ['type' => 'string', 'description' => 'Client mobile phone in international format.'],
                    'client_name' => ['type' => 'string', 'description' => 'Client name (required for new clients).'],
                    'consent' => ['type' => 'boolean', 'description' => 'True only after the user accepted consent_url from business_info.'],
                ],
                'required' => ['phone'],
                'additionalProperties' => false,
            ],
            'annotations' => $write,
            'handler' => [self::class, 'identifyClient'],
            'module' => 'client_mcp',
            'core' => true,
            'order' => 50,
        ];
        $payload['tools'][] = [
            'name' => 'verify_code',
            'title' => 'Confirm verification code',
            'description' => 'Only when identify_client returned verification.mode other than none: submit the code the user received.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'client_token' => $token,
                    'code' => ['type' => 'string', 'description' => 'Code the user received.'],
                ],
                'required' => ['client_token', 'code'],
                'additionalProperties' => false,
            ],
            'annotations' => $write,
            'handler' => [self::class, 'verifyCode'],
            'module' => 'client_mcp',
            'core' => true,
            'order' => 51,
        ];
        return $payload;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function businessInfo(array $args): array
    {
        $host = ClientMcpContext::host();
        $meta = SitesFront::siteMeta();
        $consentUrl = ClientMcpHost::consentUrl($host);
        $fields = [
            ['name' => 'phone', 'tool' => 'identify_client', 'required' => true, 'note' => 'Mobile phone of the person who will attend.'],
            [
                'name' => 'client_name',
                'tool' => 'identify_client',
                'required' => ClientMcpConfig::requireNameForNew() ? 'for_new_clients' : false,
                'note' => 'Name for the client card.',
            ],
        ];
        if ($consentUrl !== '') {
            $fields[] = ['name' => 'consent', 'tool' => 'identify_client', 'required' => true, 'note' => 'User must accept consent_url.'];
        }
        $info = [
            'ok' => true,
            'name' => ClientMcpHost::serverName($host),
            'description' => ClientMcpHost::description($host),
            'phone' => (string)($meta['tel'] ?? ''),
            'website' => $host['base'],
            'timezone' => date_default_timezone_get(),
            'utc_offset' => date('P'),
            'now' => date('c'),
            'currency' => ClientMcpConfig::currency(),
            'payment' => ClientMcpContext::paymentPolicy(),
            'consent_url' => $consentUrl,
            'registration' => [
                'verification' => 'none',
                'fields' => $fields,
            ],
            'tools' => array_keys(ClientMcpTools::availableFor($host)),
        ];
        $hook = EventBus::emit('client_mcp.business_info', ['info' => $info, 'host' => $host]);
        return is_array($hook['info'] ?? null) ? $hook['info'] : $info;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function identifyClient(array $args): array
    {
        $host = ClientMcpContext::host();
        $phone = SitesGuest::normalizePhone((string)($args['phone'] ?? ''));
        if ($phone === '') {
            throw new ClientMcpToolError('invalid_phone', t('client_mcp.err.phone'));
        }
        $ip = ClientMcpContext::clientIp();
        if (ClientMcpSessionsRepository::countByIpSince($ip, time() - 3600) >= ClientMcpConfig::sessionsPerIpHour()) {
            throw new ClientMcpToolError('rate_limited', t('client_mcp.err.rate_limited'));
        }

        $client = ClientsRepository::findByPhoneDigits($phone);
        if ($client !== null && !ClientsRepository::canBookOnline($client)) {
            throw new ClientMcpToolError('blocked_online', t('client_mcp.err.blocked_online'), [
                'phone' => ClientMcpContext::businessPhone(),
            ]);
        }
        $known = $client !== null;
        $name = ClientMcpContext::cleanName((string)($args['client_name'] ?? ''));
        $consent = !empty($args['consent']);

        $hook = EventBus::emit('client_mcp.identify', [
            'phone' => $phone,
            'client_id' => $known ? (int)$client['id'] : 0,
            'known' => $known,
            'client_name' => $name,
            'ip' => $ip,
            'site_host_id' => $host['id'],
            'verification' => ['mode' => 'none', 'hint' => ''],
            'error' => '',
        ]);
        if (trim((string)($hook['error'] ?? '')) !== '') {
            throw new ClientMcpToolError('identify_rejected', (string)$hook['error']);
        }
        $verification = is_array($hook['verification'] ?? null) ? $hook['verification'] : [];
        $mode = trim((string)($verification['mode'] ?? 'none'));
        if ($mode === '') {
            $mode = 'none';
        }

        $session = ClientMcpSessionsRepository::create([
            'site_host_id' => $host['id'],
            'phone' => $phone,
            'client_id' => $known ? (int)$client['id'] : 0,
            'client_name' => $name,
            'known' => $known,
            'verify_mode' => $mode,
            'consent' => $consent,
            'ip' => $ip,
            'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ], ClientMcpConfig::tokenTtlHours() * 3600);

        return [
            'ok' => true,
            'client_token' => $session['token'],
            'expires_at' => date('c', $session['expires_at']),
            'known_client' => $known,
            'required_fields' => self::missingFields([
                'known' => $known,
                'client_name' => $name,
                'consent_at' => $consent ? time() : 0,
                'verify_mode' => $mode,
                'verified' => false,
            ]),
            'verification' => [
                'mode' => $mode,
                'hint' => (string)($verification['hint'] ?? ''),
            ],
            'consent_url' => ClientMcpHost::consentUrl($host),
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function verifyCode(array $args): array
    {
        $session = ClientMcpContext::session($args);
        if ($session['verify_mode'] === 'none') {
            return ['ok' => true, 'status' => 'not_required', 'verified' => false];
        }
        if ($session['verified']) {
            return ['ok' => true, 'status' => 'already_verified', 'verified' => true];
        }
        $code = trim((string)($args['code'] ?? ''));
        if ($code === '') {
            throw new ClientMcpToolError('invalid_code', t('client_mcp.err.code'));
        }
        $hook = EventBus::emit('client_mcp.verify', [
            'session' => $session,
            'code' => $code,
            'verified' => false,
            'client_id' => 0,
            'error' => '',
        ]);
        if (empty($hook['verified'])) {
            $msg = trim((string)($hook['error'] ?? ''));
            throw new ClientMcpToolError('invalid_code', $msg !== '' ? $msg : t('client_mcp.err.code'));
        }
        $fields = ['verified' => true];
        $clientId = (int)($hook['client_id'] ?? 0);
        if ($clientId > 0) {
            $fields['client_id'] = $clientId;
        }
        ClientMcpSessionsRepository::update((int)$session['id'], $fields);
        return ['ok' => true, 'status' => 'verified', 'verified' => true];
    }

    /**
     * Identity fields still missing before a write (name for new clients, consent, verification).
     *
     * @param array{known:bool,client_name:string,consent_at:int,verify_mode:string,verified:bool} $s
     * @return list<string>
     */
    public static function missingFields(array $s): array
    {
        $out = [];
        if (!$s['known'] && ClientMcpConfig::requireNameForNew() && trim($s['client_name']) === '') {
            $out[] = 'client_name';
        }
        if (ClientMcpHost::consentUrl(ClientMcpContext::host()) !== '' && $s['consent_at'] < 1) {
            $out[] = 'consent';
        }
        if ($s['verify_mode'] !== 'none' && !$s['verified']) {
            $out[] = 'verification_code';
        }
        return $out;
    }
}
