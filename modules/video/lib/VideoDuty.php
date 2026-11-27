<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Who is responsible for a post at a moment: the on-shift staff (workweeks) among the duty roles
 * configured on the camera (settings.duty_status_ids), else on its post.
 */
final class VideoDuty
{
    /** @param array<string,mixed> $cam base_video_cameras row @return list<int> */
    public static function statusIds(array $cam): array
    {
        $own = json_decode((string)($cam['SETTINGS_JSON'] ?? ''), true);
        if (is_array($own) && !empty($own['duty_status_ids']) && is_array($own['duty_status_ids'])) {
            return self::ints($own['duty_status_ids']);
        }
        $postId = (int)($cam['POST_ID'] ?? 0);
        $post = $postId > 0 ? VideoPostsRepository::find($postId) : null;
        if ($post) {
            $ps = json_decode((string)($post['SETTINGS_JSON'] ?? ''), true);
            if (is_array($ps) && !empty($ps['duty_status_ids']) && is_array($ps['duty_status_ids'])) {
                return self::ints($ps['duty_status_ids']);
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $cam
     * @return array{id:int,name:string}|null null = nobody defined
     */
    public static function resolve(array $cam, int $ts): ?array
    {
        $ids = self::statusIds($cam);
        if ($ids === [] || !class_exists('WorkweeksRepository')) {
            return null;
        }
        $row = WorkweeksRepository::onDutyAt($ts, $ids);
        if ($row === null) {
            return null;
        }

        return ['id' => (int)$row['id'], 'name' => (string)$row['name']];
    }

    /** @param array<mixed> $raw @return list<int> */
    private static function ints(array $raw): array
    {
        $out = [];
        foreach ($raw as $v) {
            $v = (int)$v;
            if ($v > 0) {
                $out[$v] = $v;
            }
        }

        return array_values($out);
    }
}
