<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Surface A (/agent/audio/video-effects) — consequences of video absence alerts for cabin records, via agent_token.
 */
final class AudioAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp('GET', '/agent/audio/video-effects', 'audio.video_effects.get', [self::class, 'get'], [
            'description' => 'Audio consequences of video absence alerts: enabled + before/after windows (cfg/modules/audio.ini)',
        ]);
        AgentOps::registerModuleOp('POST', '/agent/audio/video-effects', 'audio.video_effects.save', [self::class, 'save'], [
            'description' => 'Set video_effects_enabled=0|1, video_effects_before_min, video_effects_after_min (0..120). Omitted keys keep current values',
            'body' => ['video_effects_enabled', 'video_effects_before_min', 'video_effects_after_min'],
        ]);
        ModuleManager::registerRoute('/agent/audio/video-effects', [
            'module' => 'audio',
            'public' => true,
            'csrf' => false,
            'methods' => ['GET', 'POST'],
            'handler' => static function (): void {
                require_once base_path('app/agent_http.php');
            },
        ]);
    }

    /** @return array<string,mixed> */
    public static function get(): array
    {
        return ['ok' => true, 'settings' => AudioVideoEffects::config(), 'table_ready' => AudioVideoEffects::tableReady()];
    }

    /** @return array<string,mixed> */
    public static function save(): array
    {
        $in = AgentOps::requestInput();
        $cur = AudioVideoEffects::config();
        $post = [
            'video_effects_enabled' => array_key_exists('video_effects_enabled', $in)
                ? (in_array(strtolower(trim((string)$in['video_effects_enabled'])), ['1', 'true', 'yes', 'on'], true) ? '1' : '')
                : ($cur['enabled'] ? '1' : ''),
            'video_effects_before_min' => $in['video_effects_before_min'] ?? $cur['before_min'],
            'video_effects_after_min' => $in['video_effects_after_min'] ?? $cur['after_min'],
        ];
        AudioVideoEffects::save($post);

        return self::get();
    }
}
