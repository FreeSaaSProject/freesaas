<?php
declare(strict_types=1);

final class MediaUpload
{
    /**
     * @param array<string,mixed> $file $_FILES element
     * @return array<string,mixed> created attachment row
     */
    public static function fromUpload(string $ownerTable, int $ownerId, string $kind, array $file): array
    {
        if (!in_array($ownerTable, MediaConfig::ownerTables(), true) || $ownerId < 1) {
            throw new InvalidArgumentException(t('media.err.bad_owner'));
        }
        if (!in_array($kind, MediaConfig::kinds(), true)) {
            throw new InvalidArgumentException(t('media.err.bad_kind'));
        }
        if (!MediaAcl::canUploadOwner($ownerTable, $ownerId)) {
            throw new RuntimeException(t('media.err.forbidden'));
        }
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(t('media.err.upload_failed', ['code' => (string)$err]));
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $name = (string)($file['name'] ?? 'upload.jpg');
        $processed = MediaImage::processUpload($tmp, $name);
        try {
            $store = MediaStore::make();
            $paths = $store->putProcessed($ownerTable, $ownerId, $processed);
            $id = MediaAttachmentsRepository::create([
                'id_author' => Auth::userId(),
                'owner_table' => $ownerTable,
                'owner_id' => $ownerId,
                'kind' => $kind,
                'storage_path' => $paths['storage_path'],
                'thumb_path' => $paths['thumb_path'],
                'mime' => $processed['mime'],
                'bytes' => $processed['bytes'],
                'width' => $processed['width'],
                'height' => $processed['height'],
                'original_name' => $processed['original_name'] ?? $name,
            ]);
            $row = MediaAttachmentsRepository::find($id);
            if ($row === null) {
                throw new RuntimeException(t('media.err.create_failed'));
            }
            return $row;
        } finally {
            MediaImage::cleanupTemp($processed);
        }
    }
}
