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
                    $html .= '<div class="bi-metric"><dt>' . e((string)$k) . '</dt><dd>'
                        . BiSafeHtml::expandCell($v) . '</dd></div>';
                }
                $html .= '</dl>';
            } elseif ($type === 'chart') {
                $html .= self::chartHtml($sec);
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
     * Safe chart shell: canvas + JSON payload (Chart.js boots from bi.js after inject).
     *
     * @param array<string,mixed> $sec
     */
    public static function chartHtml(array $sec): string
    {
        $payload = [
            'chart_type' => (string)($sec['chart_type'] ?? 'line'),
            'labels' => is_array($sec['labels'] ?? null) ? array_values($sec['labels']) : [],
            'datasets' => is_array($sec['datasets'] ?? null) ? array_values($sec['datasets']) : [],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
        if ($json === false) {
            $json = '{"chart_type":"line","labels":[],"datasets":[]}';
        }
        return '<div class="bi-chart-wrap">'
            . '<canvas class="bi-chart-canvas" height="160" aria-label="' . e((string)($sec['title'] ?? 'Chart')) . '"></canvas>'
            . '<script type="application/json" class="bi-chart-data">' . $json . '</script>'
            . '</div>';
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
                    $fmt = strtolower((string)($col['format'] ?? ''));
                    if ($fmt === 'user_badge') {
                        $userField = (string)($col['user_field'] ?? $id);
                        $uid = (int)($row[$userField] ?? 0);
                        $badge = class_exists('UsersRepository', false)
                            ? UsersRepository::badgeHtml($uid, !empty($col['link']), true)
                            : e('[' . $uid . ']');
                        $suffixField = (string)($col['suffix_field'] ?? '');
                        $suffix = $suffixField !== '' ? BiSafeHtml::expandCell($row[$suffixField] ?? '') : '';
                        $html .= '<td>' . $badge . ($suffix !== '' ? ' ' . $suffix : '') . '</td>';
                        continue;
                    }
                    $html .= '<td>' . BiSafeHtml::expandCell($row[$id] ?? '') . '</td>';
                }
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table></div>';
        return $html;
    }
}
