<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/MailConfig.php';
require_once __DIR__ . '/lib/SmtpClient.php';
require_once __DIR__ . '/lib/MailSendsRepository.php';
require_once __DIR__ . '/lib/MailDrain.php';
require_once __DIR__ . '/lib/MailSend.php';
require_once __DIR__ . '/lib/MailAgentOps.php';

MailAgentOps::register();

ModuleManager::registerMenuGroup('mail.menu.group', 1110, '/mail/settings', '✉️');

$pages = [
    '/mail/settings' => [
        'permission' => 'mail.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
    '/mail/sends' => [
        'permission' => 'mail.view',
        'methods' => ['GET'],
        'file' => 'sends.php',
    ],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'mail',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/mail/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $route['capability'] = $def['capability'];
    }
    ModuleManager::registerRoute($path, $route);
}

Cron::register('mail.sends_drain', [
    'module' => 'mail',
    'label' => 'mail.cron.sends_drain',
    'every' => 5,
    'handler' => static function (): array {
        $r = MailDrain::run(40);
        $errors = (int)($r['errors'] ?? 0);
        $notConfigured = empty($r['configured']) && MailSendsRepository::countPending() > 0;
        return [
            'ok' => $errors === 0 && !$notConfigured,
            'processed' => (int)($r['processed'] ?? 0),
            'sent' => (int)($r['sent'] ?? 0),
            'skipped' => (int)($r['skipped'] ?? 0),
            'errors' => $errors,
            'message' => $notConfigured
                ? 'smtp not configured'
                : 'sent=' . (int)($r['sent'] ?? 0) . ' errors=' . $errors,
        ];
    },
]);

EventBus::on('mail.send', static function ($payload) {
    return MailSend::onSend($payload);
});

// Cabinet password reset: offer email when SMTP is ready and the client has mail.
EventBus::on('client_portal.password_reset.channels', static function ($payload) {
    if (!is_array($payload) || !MailConfig::isConfigured() || !MailSendsRepository::tableReady()) {
        return $payload;
    }
    $client = is_array($payload['client'] ?? null) ? $payload['client'] : null;
    $mail = '';
    if ($client !== null) {
        $mail = trim((string)($client['mail'] ?? $client['MAIL'] ?? ''));
    }
    $channels = is_array($payload['channels'] ?? null) ? $payload['channels'] : [];
    $channels[] = [
        'id' => 'email',
        'label' => t('mail.reset.channel.label'),
        'hint' => t('mail.reset.channel.hint'),
        'can_send' => $mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) !== false,
    ];
    $payload['channels'] = $channels;
    return $payload;
});

EventBus::on('client_portal.password_reset.notify', static function ($payload) {
    if (!is_array($payload) || !MailConfig::isConfigured() || !MailSendsRepository::tableReady()) {
        return $payload;
    }
    $channel = (string)($payload['channel'] ?? '');
    // Empty preferred channel = send on every available channel (same as UI "auto").
    if ($channel !== '' && $channel !== 'email' && $channel !== 'auto') {
        return $payload;
    }
    $client = is_array($payload['client'] ?? null) ? $payload['client'] : null;
    if ($client === null) {
        return $payload;
    }
    $to = trim((string)($client['mail'] ?? $client['MAIL'] ?? ''));
    if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        return $payload;
    }
    $url = (string)($payload['reset_url'] ?? '');
    if ($url === '') {
        return $payload;
    }
    $delivered = is_array($payload['delivered'] ?? null) ? $payload['delivered'] : [];
    if (in_array('email', $delivered, true)) {
        return $payload;
    }
    try {
        $qid = MailSend::enqueue([
            'to' => $to,
            'subject' => t('mail.reset.subject'),
            'text' => t('mail.reset.body', ['url' => $url]),
            'html' => nl2br(e(t('mail.reset.body', ['url' => $url])), false),
            'client_id' => (int)($client['id'] ?? $client['ID'] ?? 0),
            'dedupe_key' => 'cpr-mail:' . (int)($client['id'] ?? 0) . ':' . substr(md5($url), 0, 12),
            'meta' => ['source' => 'password_reset'],
        ]);
        if ($qid > 0) {
            $delivered[] = 'email';
            $payload['delivered'] = $delivered;
        }
    } catch (Throwable $e) {
        $payload['error'] = trim((string)($payload['error'] ?? '') . ' email: ' . $e->getMessage());
    }
    return $payload;
});
