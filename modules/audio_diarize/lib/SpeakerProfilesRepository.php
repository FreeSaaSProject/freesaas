<?php
declare(strict_types=1);

/**
 * Staff voiceprint profiles (base_records_speaker_profiles).
 */
final class SpeakerProfilesRepository
{
    public static function available(): bool
    {
        return Database::tableExists('base_records_speaker_profiles');
    }

    /**
     * @return list<array{
     *   id:int,id_admin:int,embedding_dim:int,sample_count:int,talk_sec_total:float,
     *   quality:int,source:string,dt_create:int,dt_update:int,admin_name:string
     * }>
     */
    public static function listAll(): array
    {
        if (!self::available()) {
            return [];
        }
        $sql = 'SELECT p.ID AS id, p.ID_ADMIN AS id_admin, p.EMBEDDING_DIM AS embedding_dim,
                       p.SAMPLE_COUNT AS sample_count, p.TALK_SEC_TOTAL AS talk_sec_total,
                       p.QUALITY AS quality, p.SOURCE AS source,
                       p.DT_CREATE AS dt_create, p.DT_UPDATE AS dt_update,
                       COALESCE(u.NAME, \'\') AS admin_name
                FROM base_records_speaker_profiles p
                LEFT JOIN base_users u ON u.ID = p.ID_ADMIN
                ORDER BY p.DT_UPDATE DESC, p.ID DESC';
        $st = Database::pdo()->query($sql);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'id' => (int)$row['id'],
                'id_admin' => (int)$row['id_admin'],
                'embedding_dim' => (int)$row['embedding_dim'],
                'sample_count' => (int)$row['sample_count'],
                'talk_sec_total' => (float)$row['talk_sec_total'],
                'quality' => (int)$row['quality'],
                'source' => (string)$row['source'],
                'dt_create' => (int)$row['dt_create'],
                'dt_update' => (int)$row['dt_update'],
                'admin_name' => (string)$row['admin_name'],
            ];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function findByAdmin(int $adminId): ?array
    {
        if ($adminId < 1 || !self::available()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_ADMIN AS id_admin, EMBEDDING_DIM AS embedding_dim,
                    SAMPLE_COUNT AS sample_count, TALK_SEC_TOTAL AS talk_sec_total,
                    QUALITY AS quality, SOURCE AS source,
                    DT_CREATE AS dt_create, DT_UPDATE AS dt_update
             FROM base_records_speaker_profiles WHERE ID_ADMIN = ? LIMIT 1'
        );
        $st->execute([$adminId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? [
            'id' => (int)$row['id'],
            'id_admin' => (int)$row['id_admin'],
            'embedding_dim' => (int)$row['embedding_dim'],
            'sample_count' => (int)$row['sample_count'],
            'talk_sec_total' => (float)$row['talk_sec_total'],
            'quality' => (int)$row['quality'],
            'source' => (string)$row['source'],
            'dt_create' => (int)$row['dt_create'],
            'dt_update' => (int)$row['dt_update'],
        ] : null;
    }

    public static function deleteByAdmin(int $adminId): bool
    {
        if ($adminId < 1 || !self::available()) {
            return false;
        }
        $st = Database::pdo()->prepare('DELETE FROM base_records_speaker_profiles WHERE ID_ADMIN = ?');
        return $st->execute([$adminId]);
    }
}
