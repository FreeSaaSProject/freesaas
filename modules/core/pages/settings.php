<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/SystemRepository.php');
require_once base_path('modules/core/lib/SystemLogo.php');

/** All IANA zones, sorted by current UTC offset then id. @return list<string> */
$coreTimezoneIds = static function (): array {
    $ids = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
    if (!is_array($ids) || $ids === []) {
        return ['UTC'];
    }
    $now = time();
    $offsets = [];
    foreach ($ids as $id) {
        try {
            $offsets[$id] = (new DateTimeImmutable('@' . $now))
                ->setTimezone(new DateTimeZone($id))
                ->getOffset();
        } catch (Throwable $e) {
            $offsets[$id] = 0;
        }
    }
    usort($ids, static function (string $a, string $b) use ($offsets): int {
        $oa = $offsets[$a] ?? 0;
        $ob = $offsets[$b] ?? 0;
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }
        return strcmp($a, $b);
    });
    return $ids;
};

/**
 * @return list<array{id:string,label:string,group:string}>
 */
$coreTimezoneOptions = static function (string $current) use ($coreTimezoneIds): array {
    $ids = $coreTimezoneIds();
    if ($current !== '' && !in_array($current, $ids, true)) {
        array_unshift($ids, $current);
    }
    $now = time();
    $out = [];
    foreach ($ids as $id) {
        $label = $id;
        $group = 'Other';
        $slash = strpos($id, '/');
        if ($slash !== false) {
            $group = substr($id, 0, $slash);
        } elseif ($id === 'UTC' || strncmp($id, 'Etc/', 4) === 0) {
            $group = 'UTC';
        }
        try {
            $offset = (new DateTimeImmutable('@' . $now))
                ->setTimezone(new DateTimeZone($id))
                ->format('P');
            $label = $id . ' (UTC' . $offset . ')';
        } catch (Throwable $e) {
            // keep bare id
        }
        $out[] = ['id' => $id, 'label' => $label, 'group' => $group];
    }
    return $out;
};

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$row = SystemRepository::basics();
$agentTokenPlain = '';
$agentTokenJustGenerated = false;
$timezone = (string)Config::get('app', 'timezone', 'UTC');
if ($timezone === '') {
    $timezone = 'UTC';
}
$currency = I18n::currencyCode();
$currencyChoices = I18n::currencyChoices();

if ($wantsJson && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $body = json_decode($raw, true);
        if (is_array($body)) {
            foreach ($body as $k => $v) {
                $key = (string)$k;
                if ($key === '' || array_key_exists($key, $_POST)) {
                    continue;
                }
                if (is_bool($v)) {
                    $_POST[$key] = $v ? '1' : '';
                } elseif (is_scalar($v) || $v === null) {
                    $_POST[$key] = (string)($v ?? '');
                }
            }
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'agent_token_generate') {
            if ($wantsJson) {
                AgentHttp::error('agent_token_generate is UI-only', 403);
            }
            if (!Auth::capability('SUPERADMIN_ACCESS')) {
                throw new RuntimeException(t('core.settings.agent_token.forbidden'));
            }
            $token = bin2hex(random_bytes(32));
            Config::set('app', 'agent_token', $token);
            $agentTokenPlain = $token;
            $agentTokenJustGenerated = true;
            $message = t('core.settings.agent_token.generated');
        } else {
            // HTML always posts timezone; JSON may omit to keep current.
            if (!$wantsJson || array_key_exists('timezone', $_POST)) {
                $tz = trim((string)($_POST['timezone'] ?? ''));
                if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) {
                    throw new RuntimeException(t('core.settings.timezone.invalid'));
                }
                Config::set('app', 'timezone', $tz);
                @date_default_timezone_set($tz);
                Database::reset();
                $timezone = $tz;
            }

            if (!$wantsJson || array_key_exists('currency', $_POST)) {
                $cur = strtoupper(trim((string)($_POST['currency'] ?? '')));
                if ($cur === '' || !in_array($cur, I18n::currencyChoices(), true)) {
                    throw new RuntimeException(t('core.settings.currency.invalid'));
                }
                Config::set('app', 'currency', $cur);
                $currency = $cur;
            }

            // HTML always posts company; JSON may omit to keep current.
            if (!$wantsJson || array_key_exists('company', $_POST)) {
                SystemRepository::updateBasics([
                    'company' => (string)($_POST['company'] ?? ''),
                ]);
            }

            if (!empty($_POST['logo_remove'])) {
                SystemLogo::remove();
            }

            $upload = $_FILES['logo'] ?? null;
            if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                SystemLogo::saveFromUpload($upload);
            }

            // HTML checkbox: absent when unchecked. JSON may omit to keep current.
            if (!$wantsJson || array_key_exists('agent_allow_delete', $_POST)) {
                $allowDel = !empty($_POST['agent_allow_delete']) ? '1' : '0';
                Config::set('app', 'agent_allow_delete', $allowDel);
            }

            EventBus::emit('core.settings.save', [
                'post' => $_POST,
                'files' => $_FILES,
                'row' => SystemRepository::basics(),
            ]);

            $message = t('core.common.saved');
            $row = SystemRepository::basics();
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'action' => 'save',
                    'message' => $message,
                    'company' => (string)($row['company'] ?? ''),
                    'timezone' => $timezone,
                    'currency' => $currency,
                    'currency_symbol' => I18n::currencySymbol($currency),
                    'agent_allow_delete' => AgentHttp::allowsDelete(),
                ]);
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $agentTokenJustGenerated = false;
        $row = SystemRepository::basics();
        $timezone = (string)Config::get('app', 'timezone', 'UTC');
        $currency = I18n::currencyCode();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
        }
    }
}

if ($wantsJson && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    AgentHttp::emit([
        'ok' => true,
        'action' => 'status',
        'company' => (string)($row['company'] ?? ''),
        'timezone' => $timezone,
        'currency' => $currency,
        'currency_symbol' => I18n::currencySymbol($currency),
        'currencies' => $currencyChoices,
        'timezones' => $coreTimezoneIds(),
        'has_logo' => SystemLogo::has(),
        'agent_token_configured' => AgentAuth::isConfigured(),
        'agent_allow_delete' => AgentHttp::allowsDelete(),
    ]);
}

$sections = EventBus::emit('core.settings.sections', [
    'html' => '',
    'agent_html' => '',
    'row' => $row,
]);
$extraHtml = is_array($sections) ? (string)($sections['html'] ?? '') : '';
$agentHtml = is_array($sections) ? (string)($sections['agent_html'] ?? '') : '';

$agentConfigured = AgentAuth::isConfigured();
$canManageAgentToken = Auth::capability('SUPERADMIN_ACCESS');
// Never expose the plaintext token to non-superadmins (Copy / flash after generate).
if (!$canManageAgentToken) {
    $agentTokenPlain = '';
    $agentTokenJustGenerated = false;
} elseif ($agentTokenPlain === '' && $agentConfigured) {
    $agentTokenPlain = AgentAuth::configuredToken();
}
$agentTokenMasked = '';
if ($agentConfigured && $canManageAgentToken && $agentTokenPlain !== '') {
    $len = strlen($agentTokenPlain);
    $agentTokenMasked = $len <= 12
        ? str_repeat('•', $len)
        : (substr($agentTokenPlain, 0, 4) . '…' . substr($agentTokenPlain, -4));
}

View::render('settings', [
    'title' => t('core.menu.settings'),
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'logoUrl' => SystemLogo::url(SystemLogo::VARIANT_AUTH),
    'hasLogo' => SystemLogo::has(),
    'extraHtml' => $extraHtml,
    'agentHtml' => $agentHtml,
    'timezone' => $timezone,
    'timezoneOptions' => $coreTimezoneOptions($timezone),
    'currency' => $currency,
    'currencyChoices' => $currencyChoices,
    'agentTokenConfigured' => $agentConfigured,
    'agentTokenMasked' => $agentTokenMasked,
    'agentTokenPlain' => $agentTokenPlain,
    'agentTokenJustGenerated' => $agentTokenJustGenerated,
    'canManageAgentToken' => $canManageAgentToken,
    'agentAllowDelete' => AgentHttp::allowsDelete(),
]);
