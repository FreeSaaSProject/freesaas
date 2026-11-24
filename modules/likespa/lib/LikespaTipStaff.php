<?php
declare(strict_types=1);

/**
 * Staff who can receive tips on the storefront /m page.
 * Source: enabled staff (UsersRepository) with a https tip link in base_users.link_ok.
 */
final class LikespaTipStaff
{
    /** @return list<array{id:int,name:string,photo:string,tips:string}> */
    public static function list(): array
    {
        if (!Database::tableExists('base_users') || !Database::columnExists('base_users', 'link_ok')) {
            return [];
        }
        $ids = [];
        foreach (UsersRepository::listEnabledStaff() as $u) {
            $id = (int)($u['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $hasHidden = Database::columnExists('base_users', 'IS_HIDDEN');
        $hasPhoto = Database::columnExists('base_users', 'photo');
        $sql = 'SELECT id, name, link_ok'
            . ($hasPhoto ? ', photo' : '')
            . ($hasHidden ? ', IS_HIDDEN' : '')
            . " FROM base_users WHERE id IN ($in) ORDER BY name ASC, id ASC";
        $st = Database::pdo()->prepare($sql);
        $st->execute($ids);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if ($hasHidden && !empty($r['IS_HIDDEN'])) {
                continue;
            }
            $tips = trim((string)($r['link_ok'] ?? ''));
            if (!self::isHttpsUrl($tips)) {
                continue;
            }
            $out[] = [
                'id' => (int)$r['id'],
                'name' => trim((string)($r['name'] ?? '')),
                'photo' => $hasPhoto ? self::photoUrl((string)($r['photo'] ?? '')) : '',
                'tips' => $tips,
            ];
        }
        return $out;
    }

    private static function isHttpsUrl(string $url): bool
    {
        return $url !== ''
            && preg_match('#^https://#i', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** Absolute https URL or a root-relative path served by this storefront host. */
    private static function photoUrl(string $photo): string
    {
        $photo = trim($photo);
        if ($photo === '') {
            return '';
        }
        if (preg_match('#^https://#i', $photo) === 1) {
            return $photo;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $photo) === 1 || str_contains($photo, '..')) {
            return '';
        }
        return '/' . ltrim($photo, '/');
    }
}
