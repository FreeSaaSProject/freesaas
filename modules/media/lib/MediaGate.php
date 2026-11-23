<?php
declare(strict_types=1);

final class MediaGate
{
    /**
     * @param array<string,mixed> $procedure
     * @return list<array{kind:string,min_count:int,have:int,label:string}>
     */
    public static function unmetForProcedure(array $procedure): array
    {
        $id = (int)($procedure['id'] ?? 0);
        $reqs = MediaRequirementsRepository::resolveForProcedure($procedure);
        if ($reqs === []) {
            return [];
        }
        $counts = $id > 0
            ? MediaAttachmentsRepository::countsByKind('base_procedures', $id)
            : [];
        $unmet = [];
        foreach ($reqs as $r) {
            $kind = $r['kind'];
            $have = (int)($counts[$kind] ?? 0);
            $min = (int)$r['min_count'];
            if ($have < $min) {
                $unmet[] = [
                    'kind' => $kind,
                    'min_count' => $min,
                    'have' => $have,
                    'label' => (string)($r['label'] ?? ''),
                ];
            }
        }
        return $unmet;
    }
}
