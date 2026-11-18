<?php
declare(strict_types=1);

/**
 * /audio/settings tab → pointer to /edge-hosts (SoT for hosts + audio pull/push).
 * Channel switches are saved from the Edge hosts page.
 */
final class AsrDispatchSettings
{
    /** @return array{id:string,title:string,weight:int,html:string} */
    public static function section(): array
    {
        $ready = AsrLease::available() && AsrPushWorkers::available();
        $html = View::capture('_settings_asr_dispatch', [
            'tablesReady' => $ready,
            'pullEnabled' => AsrLease::pullEnabled(),
            'pushEnabled' => AsrLease::pushEnabled(),
            'counts' => $ready ? AsrLease::counts() : ['active_pull' => 0, 'active_push' => 0],
            'pending' => $ready ? AsrLease::pendingCount() : 0,
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
        // Hosts + channel toggles are edited on /edge-hosts only.
        return $warnings;
    }

    /** HTML block + save for /edge-hosts (EventBus). */
    public static function edgeHostsSectionHtml(): string
    {
        $ready = AsrLease::available() && EdgeHosts::available();
        return View::capture('_edge_hosts_audio', [
            'tablesReady' => $ready,
            'pullEnabled' => AsrLease::pullEnabled(),
            'pushEnabled' => AsrLease::pushEnabled(),
            'pushEveryMin' => AsrLease::pushEveryMin(),
            'counts' => $ready && AsrLease::available()
                ? AsrLease::counts()
                : ['active_pull' => 0, 'active_push' => 0],
            'pending' => $ready && AsrLease::available() ? AsrLease::pendingCount() : 0,
        ], 'audio_pipeline');
    }

    /**
     * @param array<string,mixed> $post
     * @param list<string> $warnings
     * @return list<string>
     */
    public static function saveFromEdgeHosts(array $post, array $warnings): array
    {
        if (empty($post['edge_audio_dispatch_save'])) {
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
        return $warnings;
    }
}
