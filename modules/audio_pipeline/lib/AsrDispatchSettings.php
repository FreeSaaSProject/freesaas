<?php
declare(strict_types=1);

/**
 * /audio/settings tab: pull/push switches + push worker registry (superadmin).
 */
final class AsrDispatchSettings
{
    private const ONCE_KEY = 'audio_pipeline_asr_push_once';

    /** @return array{id:string,title:string,weight:int,html:string} */
    public static function section(): array
    {
        $once = $_SESSION[self::ONCE_KEY] ?? null;
        unset($_SESSION[self::ONCE_KEY]);
        $ready = AsrLease::available() && AsrPushWorkers::available();
        $html = View::capture('_settings_asr_dispatch', [
            'tablesReady' => $ready,
            'pullEnabled' => AsrLease::pullEnabled(),
            'pushEnabled' => AsrLease::pushEnabled(),
            'pushEveryMin' => AsrLease::pushEveryMin(),
            'counts' => $ready ? AsrLease::counts() : ['active_pull' => 0, 'active_push' => 0],
            'pending' => $ready ? AsrLease::pendingCount() : 0,
            'workers' => $ready ? AsrPushWorkers::all() : [],
            'once' => is_array($once) ? $once : null,
        ], 'audio_pipeline');

        return ['id' => 'asr_dispatch', 'title' => 'audio_pipeline.asr.tab', 'weight' => 85, 'html' => $html];
    }

    /**
     * @param array<string,mixed> $post
     * @param list<string> $warnings
     * @return list<string>
     */
    public static function save(array $post, array $warnings): array
    {
        if (empty($post['asr_dispatch_save'])) {
            return $warnings;
        }
        $pull = !empty($post['asr_pull_enabled']);
        $push = !empty($post['asr_push_enabled']);
        Config::set('audio', 'asr_pull_enabled', $pull ? '1' : '0');
        Config::set('audio', 'asr_push_enabled', $push ? '1' : '0');
        if (isset($post['asr_push_every_min'])) {
            Config::set('audio', 'asr_push_every_min', (string)max(1, min(60, (int)$post['asr_push_every_min'])));
        }
        if (!$pull && !$push) {
            $warnings[] = t('audio_pipeline.asr.warn_both_off');
        }
        if (!AsrPushWorkers::available()) {
            return $warnings;
        }

        $rows = is_array($post['asr_workers'] ?? null) ? $post['asr_workers'] : [];
        foreach ($rows as $id => $row) {
            $id = (int)$id;
            if (!is_array($row) || AsrPushWorkers::find($id) === null) {
                continue;
            }
            $url = trim((string)($row['base_url'] ?? ''));
            $pin = trim((string)($row['pin'] ?? ''));
            $enabled = !empty($row['enabled']);
            if ($enabled && (!AsrPushClient::validBaseUrl($url) || !AsrPushClient::validPin($pin))) {
                $enabled = false;
                $warnings[] = t('audio_pipeline.asr.warn_cannot_enable', ['name' => (string)($row['name'] ?? $id)]);
            }
            AsrPushWorkers::update($id, (string)($row['name'] ?? ''), $url, $pin, (int)($row['max_inflight'] ?? 20), $enabled);
        }

        $action = (string)($post['asr_push_action'] ?? '');
        if ($action === '') {
            return $warnings;
        }
        [$verb, $rawId] = array_pad(explode(':', $action, 2), 2, '0');
        $id = (int)$rawId;

        if ($verb === 'add') {
            $new = is_array($post['asr_new'] ?? null) ? $post['asr_new'] : [];
            $url = trim((string)($new['base_url'] ?? ''));
            $pin = trim((string)($new['pin'] ?? ''));
            $name = trim((string)($new['name'] ?? ''));
            if ($name === '' || !AsrPushClient::validBaseUrl($url)) {
                $warnings[] = t('audio_pipeline.asr.warn_bad_url');
                return $warnings;
            }
            if ($pin !== '' && !AsrPushClient::validPin($pin)) {
                $warnings[] = t('audio_pipeline.asr.warn_bad_pin');
                $pin = '';
            }
            $created = AsrPushWorkers::create($name, $url, $pin, (int)($new['max_inflight'] ?? 20));
            self::once(['kind' => 'secret', 'id' => $created['id'], 'name' => $name, 'secret' => $created['secret'], 'field' => 'secret']);
            return $warnings;
        }

        $w = AsrPushWorkers::find($id);
        if ($w === null) {
            return $warnings;
        }
        switch ($verb) {
            case 'test':
                $r = AsrPushDispatcher::probe($w);
                self::once([
                    'kind' => $r['ok'] ? 'ok' : 'error',
                    'text' => $r['ok']
                        ? t('audio_pipeline.asr.test_ok', ['name' => (string)$w['NAME'], 'detail' => $r['detail']])
                        : t('audio_pipeline.asr.test_fail', ['name' => (string)$w['NAME'], 'detail' => $r['detail']]),
                ]);
                break;
            case 'sync':
                $r = AsrPushDispatcher::syncWorker($w, true);
                self::once([
                    'kind' => $r['ok'] ? 'ok' : 'error',
                    'text' => t('audio_pipeline.asr.sync_done', [
                        'name' => (string)$w['NAME'],
                        'saved' => (int)$r['saved'],
                        'sent' => (int)$r['sent'],
                        'error' => $r['error'] !== '' ? $r['error'] : ($r['skipped'] !== '' ? $r['skipped'] : '—'),
                    ]),
                ]);
                break;
            case 'rotate':
                self::once([
                    'kind' => 'secret',
                    'id' => $id,
                    'name' => (string)$w['NAME'],
                    'secret' => AsrPushWorkers::rotateNext($id),
                    'field' => 'secret_next',
                ]);
                break;
            case 'promote':
                $ok = AsrPushWorkers::promoteNext($id);
                self::once([
                    'kind' => $ok ? 'ok' : 'error',
                    'text' => t($ok ? 'audio_pipeline.asr.promote_ok' : 'audio_pipeline.asr.promote_none'),
                ]);
                break;
            case 'delete':
                AsrPushWorkers::delete($id);
                break;
        }

        return $warnings;
    }

    /** @param array<string,mixed> $data */
    private static function once(array $data): void
    {
        $_SESSION[self::ONCE_KEY] = $data;
    }
}
