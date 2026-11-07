<?php
declare(strict_types=1);

final class BitrixWebhookApply
{
    /** @param array<string,mixed> $row */
    public static function apply(string $portal, string $entity, array $row): string
    {
        return match ($entity) {
            'contact' => BitrixImporter::upsertContact($portal, $row),
            'company' => BitrixImporter::upsertCompany($portal, $row),
            'deal' => BitrixImporter::upsertDeal($portal, $row, 'sale'),
            'lead' => BitrixImporter::upsertDeal($portal, $row, 'lead'),
            default => 'skipped',
        };
    }
}
