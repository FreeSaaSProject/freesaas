<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$fallback = '/banks';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if ($wantsJson) {
        AgentHttp::error(t('banks.import.err.method'), 405);
    }
    flash_set('error', t('banks.import.err.method'));
    redirect(NavContext::backOr($fallback));
}

$action = trim((string)($_POST['action'] ?? 'bind'));

try {
    if ($action === 'unbind') {
        if (!Auth::can('banks.connect')) {
            throw new RuntimeException(t('banks.err.link'));
        }
        $linkId = (int)($_POST['link_id'] ?? 0);
        $link = BankAccountLinksRepository::find($linkId);
        if (!$link) {
            throw new InvalidArgumentException(t('banks.err.link'));
        }
        $conn = BankConnectionsRepository::find((int)$link['connection_id'], true);
        if (!$conn || !BanksConfig::isFileProvider((string)$conn['provider'])) {
            throw new InvalidArgumentException(t('banks.err.link'));
        }
        // Drop link (+ orphan empty file connection if last link).
        $connId = (int)$link['connection_id'];
        BankAccountLinksRepository::delete($linkId);
        $left = BankAccountLinksRepository::forConnection($connId);
        if ($left === []) {
            BankConnectionsRepository::deleteWithDeps($connId);
        }
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'action' => 'unbind', 'link_id' => $linkId]);
        }
        flash_set('ok', t('banks.import.unbound'));
        redirect(NavContext::backOr($fallback));
    }

    if ($action !== 'bind') {
        throw new InvalidArgumentException(t('banks.err.link'));
    }
    if (!Auth::can('banks.connect')) {
        throw new RuntimeException(t('banks.err.link'));
    }

    $accountId = (int)($_POST['account_id'] ?? 0);
    $provider = BanksConfig::normalizeProvider((string)($_POST['provider'] ?? 'psb_card'));
    $accountNumber = trim((string)($_POST['account_number'] ?? ''));
    if ($accountId < 1 || !BanksConfig::isFileProvider($provider)) {
        throw new InvalidArgumentException(t('banks.err.provider'));
    }

    $link = BankSyncService::ensureFileLink($accountId, $provider, $accountNumber);
    if ($wantsJson) {
        AgentHttp::emit([
            'ok' => true,
            'action' => 'bind',
            'link' => $link,
            'account_id' => $accountId,
            'provider' => $provider,
        ]);
    }
    flash_set('ok', t('banks.import.bound_saved', [
        'provider' => BanksConfig::providerLabel($provider),
        'account_id' => $accountId,
    ]));
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
    }
    flash_set('error', $e->getMessage());
}

redirect(NavContext::backOr($fallback));
