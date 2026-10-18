<?php
declare(strict_types=1);

/**
 * Long-poll Max updates for the dedicated assistant_groups bot token.
 * Used when the group bot is not the same as bots staff_max (no shared webhook).
 */
final class AssistantGroupsPoller
{
    private const MARKER_FILE = 'var/assistant_groups/poll_marker.txt';

    /**
     * @return array{ok:bool,processed:int,error:string}
     */
    public static function tick(int $timeoutSec = 5): array
    {
        if (!AssistantGroupsConfig::isEnabled()) {
            return ['ok' => true, 'processed' => 0, 'error' => 'disabled'];
        }
        if (!AssistantGroupsConfig::hasOwnToken()) {
            return ['ok' => true, 'processed' => 0, 'error' => 'no_own_token'];
        }
        // Max: webhook and long-poll are mutually exclusive.
        if (AssistantGroupsConfig::webhookSubscribedFlag()
            || (class_exists('AssistantGroupsWebhook', false) && AssistantGroupsWebhook::hasActiveSubscription())
        ) {
            return ['ok' => true, 'processed' => 0, 'error' => 'webhook_active'];
        }
        $token = AssistantGroupsConfig::maxToken();
        if ($token === '') {
            return ['ok' => false, 'processed' => 0, 'error' => 'empty token'];
        }

        $marker = self::readMarker();
        $query = ['timeout' => max(1, min(30, $timeoutSec)), 'limit' => 100];
        if ($marker > 0) {
            $query['marker'] = $marker;
        }
        $res = MaxGroupSpike::request($token, 'GET', '/updates', $query);
        if (!$res['ok']) {
            return ['ok' => false, 'processed' => 0, 'error' => $res['error']];
        }
        $data = is_array($res['data']) ? $res['data'] : [];
        if (isset($data['marker'])) {
            self::writeMarker((int)$data['marker']);
        }
        $updates = is_array($data['updates'] ?? null) ? $data['updates'] : [];
        $n = 0;
        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }
            try {
                AssistantGroupsInbound::handle($token, $update);
                $n++;
            } catch (Throwable $e) {
                error_log('[assistant_groups.poll] ' . $e->getMessage());
            }
        }
        return ['ok' => true, 'processed' => $n, 'error' => ''];
    }

    private static function readMarker(): int
    {
        $path = base_path(self::MARKER_FILE);
        if (!is_readable($path)) {
            return 0;
        }
        return (int)trim((string)file_get_contents($path));
    }

    private static function writeMarker(int $marker): void
    {
        $path = base_path(self::MARKER_FILE);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($path, (string)$marker);
    }
}
