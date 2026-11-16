<?php
declare(strict_types=1);

final class CourseMedia
{
    public static function sign(int $assetId, string $subjectType, int $subjectId, int $ttl = 0): string
    {
        $ttl = $ttl > 0 ? $ttl : CoursesConfig::mediaTtl();
        $exp = time() + $ttl;
        $payload = $assetId . '|' . $subjectType . '|' . $subjectId . '|' . $exp;
        $sig = hash_hmac('sha256', $payload, self::secret());
        return 'exp=' . $exp . '&sig=' . rawurlencode($sig) . '&st=' . rawurlencode($subjectType) . '&sid=' . $subjectId;
    }

    public static function verify(int $assetId, string $subjectType, int $subjectId, int $exp, string $sig): bool
    {
        if ($exp < time() || $assetId < 1 || $subjectId < 1) {
            return false;
        }
        $payload = $assetId . '|' . $subjectType . '|' . $subjectId . '|' . $exp;
        $expect = hash_hmac('sha256', $payload, self::secret());
        return hash_equals($expect, $sig);
    }

    public static function url(int $assetId, string $subjectType, int $subjectId): string
    {
        return '/courses/media/' . $assetId . '?' . self::sign($assetId, $subjectType, $subjectId);
    }

    /** @return array<string,mixed>|null */
    public static function findAsset(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_course_assets')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, LESSON_ID AS lesson_id, KIND AS kind, TITLE AS title,
                    STORAGE_PATH AS storage_path, MIME AS mime, BYTES AS bytes
             FROM base_course_assets WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['lesson_id'] = (int)$row['lesson_id'];
        $row['bytes'] = (int)$row['bytes'];
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public static function listForLesson(int $lessonId): array
    {
        if ($lessonId < 1 || !Database::tableExists('base_course_assets')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, LESSON_ID AS lesson_id, KIND AS kind, TITLE AS title,
                    STORAGE_PATH AS storage_path, MIME AS mime, BYTES AS bytes
             FROM base_course_assets WHERE LESSON_ID = ? ORDER BY ID ASC'
        );
        $st->execute([$lessonId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $row['id'] = (int)$row['id'];
            $row['lesson_id'] = (int)$row['lesson_id'];
            $row['bytes'] = (int)$row['bytes'];
            $out[] = $row;
        }
        return $out;
    }

    public static function absolutePath(string $storagePath): string
    {
        $storagePath = ltrim(str_replace('\\', '/', $storagePath), '/');
        return CoursesConfig::storageRoot() . '/' . $storagePath;
    }

    public static function logHit(int $assetId, string $subjectType, int $subjectId): void
    {
        if (!Database::tableExists('base_course_media_hits')) {
            return;
        }
        Database::pdo()->prepare(
            'INSERT INTO base_course_media_hits (ASSET_ID, SUBJECT_TYPE, SUBJECT_ID, IP, DT_HIT) VALUES (?,?,?,?,?)'
        )->execute([
            $assetId,
            $subjectType,
            $subjectId,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            time(),
        ]);
    }

    private static function secret(): string
    {
        $s = trim((string)Config::get('salts', 'site', ''));
        if ($s === '') {
            $s = trim((string)Config::get('app', 'agent_token', 'courses-media'));
        }
        return $s !== '' ? $s : 'courses-media-dev';
    }
}
