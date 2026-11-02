<?php
declare(strict_types=1);

/**
 * Minimal single-sheet .xlsx writer (SpreadsheetML in a stored ZIP).
 * Pure PHP — does not require ext-zip.
 */
final class BiXlsxWriter
{
    public const STYLE_NORMAL = 0;
    public const STYLE_BOLD = 1;
    public const STYLE_TITLE = 2;
    public const STYLE_WRAP = 3;

    /** @var list<list<array{v:mixed,s:int}>> */
    private $rows = [];

    /** @var array<int,int> column index => max text length */
    private $widths = [];

    /**
     * @param list<mixed> $cells scalar values or ['v' => value, 's' => style]
     */
    public function addRow(array $cells = [], int $style = self::STYLE_NORMAL): void
    {
        $row = [];
        foreach (array_values($cells) as $i => $cell) {
            if (is_array($cell) && array_key_exists('v', $cell)) {
                $v = $cell['v'];
                $s = (int)($cell['s'] ?? $style);
            } else {
                $v = $cell;
                $s = $style;
            }
            $row[] = ['v' => $v, 's' => $s];
            if ($s !== self::STYLE_TITLE && $s !== self::STYLE_WRAP) {
                $len = mb_strlen(is_scalar($v) ? (string)$v : '', 'UTF-8');
                $this->widths[$i] = max($this->widths[$i] ?? 0, $len);
            }
        }
        $this->rows[] = $row;
    }

    public function build(string $sheetName = 'Report'): string
    {
        $files = [
            '[Content_Types].xml' => self::contentTypes(),
            '_rels/.rels' => self::rootRels(),
            'xl/workbook.xml' => self::workbook($sheetName),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/styles.xml' => self::styles(),
            'xl/worksheets/sheet1.xml' => $this->sheet(),
        ];
        return self::zip($files);
    }

    private function sheet(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($this->widths !== []) {
            $xml .= '<cols>';
            ksort($this->widths);
            foreach ($this->widths as $i => $len) {
                $w = min(60, max(10, $len + 2));
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach ($this->rows as $r => $row) {
            $rn = $r + 1;
            $xml .= '<row r="' . $rn . '">';
            foreach ($row as $c => $cell) {
                $ref = self::colName($c) . $rn;
                $v = $cell['v'];
                $s = $cell['s'] > 0 ? ' s="' . $cell['s'] . '"' : '';
                if ($v === null || $v === '') {
                    if ($s !== '') {
                        $xml .= '<c r="' . $ref . '"' . $s . '/>';
                    }
                    continue;
                }
                if (is_int($v) || is_float($v)) {
                    if (is_float($v) && (is_nan($v) || is_infinite($v))) {
                        $xml .= '<c r="' . $ref . '"' . $s . '/>';
                        continue;
                    }
                    $num = is_float($v) ? rtrim(rtrim(sprintf('%.10F', $v), '0'), '.') : (string)$v;
                    $xml .= '<c r="' . $ref . '"' . $s . '><v>' . ($num === '' || $num === '-0' ? '0' : $num) . '</v></c>';
                    continue;
                }
                $xml .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
                    . self::xmlText((string)$v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private static function colName(int $index): string
    {
        $name = '';
        $n = $index + 1;
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $name = chr(65 + $m) . $name;
            $n = intdiv($n - 1, 26);
        }
        return $name;
    }

    private static function xmlText(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        $name = preg_replace('/[\[\]\*\?\/\\\\:]/u', ' ', $sheetName) ?? 'Report';
        $name = trim(mb_substr($name, 0, 31, 'UTF-8'));
        if ($name === '') {
            $name = 'Report';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xmlText($name) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /**
     * @param array<string,string> $files
     */
    private static function zip(array $files): string
    {
        $data = '';
        $central = '';
        $count = 0;
        $dosTime = 0;
        $dosDate = (1 << 5) | 1; // 1980-01-01
        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $size = strlen($content);
            $nameLen = strlen($name);
            $offset = strlen($data);
            $data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLen, 0)
                . $name . $content;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset)
                . $name;
            $count++;
        }
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($data), 0);
        return $data . $central . $end;
    }
}
