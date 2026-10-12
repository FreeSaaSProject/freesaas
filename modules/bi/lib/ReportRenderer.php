<?php
declare(strict_types=1);

/**
 * HTML helpers for KPI report result sections.
 */
final class ReportRenderer
{
    /**
     * @param array<string,mixed> $report
     */
    public static function sectionsHtml(array $report): string
    {
        $sections = is_array($report['sections'] ?? null) ? $report['sections'] : [];
        $html = '';
        foreach ($sections as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $title = (string)($sec['title'] ?? '');
            $type = (string)($sec['type'] ?? 'table');
            $html .= '<section class="bi-section">';
            if ($title !== '') {
                $html .= '<h2 class="bi-section-title">' . e($title) . '</h2>';
            }
            if ($type === 'metrics' && is_array($sec['metrics'] ?? null)) {
                $html .= '<dl class="bi-metrics">';
                foreach ($sec['metrics'] as $k => $v) {
                    $html .= '<div class="bi-metric"><dt>' . e((string)$k) . '</dt><dd>' . e((string)$v) . '</dd></div>';
                }
                $html .= '</dl>';
            } elseif ($type === 'text') {
                $body = (string)($sec['body'] ?? '');
                if ($body !== '') {
                    $html .= '<div class="bi-text">' . e_nl($body) . '</div>';
                }
            } elseif ($type === 'html') {
                // Legacy path — prefer type=text (escaped).
                $html .= '<div class="bi-html">' . e_nl((string)($sec['html'] ?? '')) . '</div>';
            } else {
                $html .= self::tableHtml(
                    is_array($sec['columns'] ?? null) ? $sec['columns'] : [],
                    is_array($sec['rows'] ?? null) ? $sec['rows'] : []
                );
            }
            $html .= '</section>';
        }
        return $html;
    }

    /**
     * @param list<array{id?:string,label?:string}> $columns
     * @param list<array<string,mixed>> $rows
     */
    public static function tableHtml(array $columns, array $rows): string
    {
        if ($columns === []) {
            return '<p class="muted">' . e(t('bi.empty.table')) . '</p>';
        }
        $html = '<div class="bi-table-wrap"><table class="bi-table"><thead><tr>';
        foreach ($columns as $col) {
            $html .= '<th>' . e((string)($col['label'] ?? $col['id'] ?? '')) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        if ($rows === []) {
            $html .= '<tr><td colspan="' . count($columns) . '" class="muted">' . e(t('bi.empty.rows')) . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $html .= '<tr>';
                foreach ($columns as $col) {
                    $id = (string)($col['id'] ?? '');
                    $html .= '<td>' . e((string)($row[$id] ?? '')) . '</td>';
                }
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table></div>';
        return $html;
    }
}
