<?php
declare(strict_types=1);

final class CoursesConfig
{
    public static function storageRoot(): string
    {
        $root = trim((string)Config::get('courses', 'storage_root', 'var/course_media'));
        if ($root === '') {
            $root = 'var/course_media';
        }
        if ($root[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $root)) {
            $root = base_path($root);
        }
        return rtrim($root, '/');
    }

    public static function mediaTtl(): int
    {
        return max(60, min(86400, (int)Config::get('courses', 'media_ttl', 900)));
    }

    /** @param array<string,mixed> $post */
    public static function saveFromPost(array $post): void
    {
        Config::set('courses', 'storage_root', trim((string)($post['storage_root'] ?? 'var/course_media')));
        Config::set('courses', 'media_ttl', (string)max(60, (int)($post['media_ttl'] ?? 900)));
    }
}
