<?php
declare(strict_types=1);

final class MediaAcl
{
    public static function canAccessOwner(string $ownerTable, int $ownerId): bool
    {
        if ($ownerId < 1 || !in_array($ownerTable, MediaConfig::ownerTables(), true)) {
            return false;
        }
        if (!Auth::check()) {
            return false;
        }
        if (Auth::capability('SUPERADMIN_ACCESS')) {
            return true;
        }
        $payload = EventBus::emit('media.owner.acl', [
            'owner_table' => $ownerTable,
            'owner_id' => $ownerId,
            'user_id' => Auth::userId(),
            'allowed' => false,
        ]);
        return !empty($payload['allowed']);
    }

    public static function canAccessAttachment(array $row): bool
    {
        return self::canAccessOwner(
            (string)($row['owner_table'] ?? ''),
            (int)($row['owner_id'] ?? 0)
        );
    }

    public static function canUploadOwner(string $ownerTable, int $ownerId): bool
    {
        if (!self::canAccessOwner($ownerTable, $ownerId)) {
            return false;
        }
        if (Auth::capability('SUPERADMIN_ACCESS')) {
            return true;
        }
        if ($ownerTable === 'base_procedures') {
            return Auth::can('procedures.edit');
        }
        return false;
    }
}
