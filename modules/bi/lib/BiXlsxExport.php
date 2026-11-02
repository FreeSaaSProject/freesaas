<?php
declare(strict_types=1);

/**
 * BI report result → .xlsx (one sheet, sections stacked top to bottom).
 */
final class BiXlsxExport
{
    /**
     * Run the family and stream the .xlsx download (plain-text error on failure).
     *
     * @param array<string,mixed> $params
     */
    public static function runAndSend(string $familyId, array $params): void
    {
        ReportProgress::beginCollect();
        try {
            $report = ReportRegistry::run($familyId, $params);
            $bin = self::fromReport($report, is_array($report['params'] ?? null) ? $report['params'] : $params);
        } catch (Throwable $e) {
            http_response_code($e->getMessage() === 'family_not_ready' ? 422 : 500);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage() === 'family_not_ready' ? t('bi.err.not_ready') : t('bi.err.run');
            return;
        } finally {
            ReportProgress::reset();
        }
        self::send($bin, self::filename($familyId, $params));
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $params
     */
    public static function fromReport(array $report, array $params): string
    {
        $w = new BiXlsxWriter();
        $title = trim((string)($report['title'] ?? ''));
        $w->addRow([$title !== '' ? $title : (string)($report['family'] ?? '')], BiXlsxWriter::STYLE_TITLE);
        $w->addRow([self::periodLabel($params)]);
        $w->addRow([]);

        $sections = is_array($report['sections'] ?? null) ? $report['sections'] : [];
        foreach ($sections as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $secTitle = (string)($sec['title'] ?? '');
            if ($secTitle !== '') {
                $w->addRow([$secTitle], BiXlsxWriter::STYLE_BOLD);
            }
            $type = (string)($sec['type'] ?? 'table');
            if ($type === 'metrics' && is_array($sec['metrics'] ?? null)) {
                foreach ($sec['metrics'] as $label => $value) {
                    $w->addRow([(string)$label, self::cell($value)]);
                }
            } elseif ($type === 'text' || $type === 'html') {
                $body = (string)($sec['body'] ?? $sec['html'] ?? '');
                if ($body !== '') {
                    $w->addRow([['v' => BiSafeHtml::plainCell($body), 's' => BiXlsxWriter::STYLE_WRAP]]);
                }
            } else {
                self::table(
                    $w,
                    is_array($sec['columns'] ?? null) ? $sec['columns'] : [],
                    is_array($sec['rows'] ?? null) ? $sec['rows'] : []
                );
            }
            $w->addRow([]);
        }

        return $w->build($title !== '' ? $title : 'Report');
    }

    /**
     * @param array<string,mixed> $params
     */
    public static function filename(string $familyId, array $params): string
    {
        $id = preg_replace('/[^a-z0-9_]/', '', strtolower($familyId)) ?: 'report';
        return sprintf('%s_%04d-%02d.xlsx', $id, (int)($params['year'] ?? 0), (int)($params['month'] ?? 0));
    }

    public static function send(string $bin, string $filename): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
        header('Content-Length: ' . (string)strlen($bin));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $bin;
    }

    /**
     * @param list<array<string,mixed>> $columns
     * @param list<array<string,mixed>> $rows
     */
    private static function table(BiXlsxWriter $w, array $columns, array $rows): void
    {
        if ($columns === []) {
            return;
        }
        $head = [];
        foreach ($columns as $col) {
            $head[] = (string)($col['label'] ?? $col['id'] ?? '');
        }
        $w->addRow($head, BiXlsxWriter::STYLE_BOLD);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($columns as $col) {
                $id = (string)($col['id'] ?? '');
                if (strtolower((string)($col['format'] ?? '')) === 'user_badge') {
                    $userField = (string)($col['user_field'] ?? $id);
                    $text = BiSafeHtml::userName((int)($row[$userField] ?? 0));
                    $suffixField = (string)($col['suffix_field'] ?? '');
                    if ($suffixField !== '') {
                        $suffix = BiSafeHtml::plainCell($row[$suffixField] ?? '');
                        if ($suffix !== '') {
                            $text .= ' ' . $suffix;
                        }
                    }
                    $cells[] = $text;
                    continue;
                }
                $cells[] = self::cell($row[$id] ?? '');
            }
            $w->addRow($cells);
        }
    }

    /**
     * @param mixed $value
     * @return int|float|string
     */
    private static function cell($value)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value === null || is_array($value)) {
            return '';
        }
        $s = BiSafeHtml::plainCell($value);
        $t = trim($s);
        if ($t !== '' && preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $t)) {
            return strpos($t, '.') !== false ? (float)$t : (int)$t;
        }
        return $s;
    }

    /**
     * @param array<string,mixed> $params
     */
    private static function periodLabel(array $params): string
    {
        $month = (int)($params['month'] ?? 0);
        $year = (int)($params['year'] ?? 0);
        $label = $month >= 1 && $month <= 12 ? t('bi.month.' . $month) . ' ' . $year : (string)$year;
        return t('bi.run.period') . ': ' . $label;
    }
}
