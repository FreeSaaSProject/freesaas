<?php
declare(strict_types=1);

/**
 * Public site SEO / contact fields on shared base_system (UI: /sites/seo).
 */
final class SystemSite
{
    /**
     * @param array{name?:string,title?:string,keywords?:string,tel?:string} $data
     */
    public static function updateBasics(array $data): void
    {
        $row = SystemRepository::get();
        if (!$row) {
            throw new RuntimeException('base_system is empty — run data migrate');
        }
        $cols = ['name = ?', 'title = ?', 'tel = ?'];
        $params = [
            (string)($data['name'] ?? ''),
            (string)($data['title'] ?? ''),
            (string)($data['tel'] ?? ''),
        ];
        if (Database::columnExists('base_system', 'keywords')) {
            $cols[] = 'keywords = ?';
            $params[] = (string)($data['keywords'] ?? '');
        }
        $params[] = (int)$row['id'];
        Database::pdo()->prepare(
            'UPDATE base_system SET ' . implode(', ', $cols) . ' WHERE id = ?'
        )->execute($params);
    }
}
