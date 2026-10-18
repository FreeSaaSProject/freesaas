<?php
declare(strict_types=1);

/**
 * Safe HTML tokens for BI cell values (never raw HTML from recipes).
 *
 * Expr `badge` emits a token; ReportRenderer expands it via UsersRepository::badgeHtml.
 */
final class BiSafeHtml
{
    /** Record-separator wrapped token: \x1Ebi_badge:123\x1E */
    private const RS = "\x1E";

    public static function badgeToken(int $userId): string
    {
        if ($userId < 1) {
            return self::RS . 'bi_badge:0' . self::RS;
        }
        return self::RS . 'bi_badge:' . $userId . self::RS;
    }

    /**
     * Escape plain text; expand known badge tokens to trusted badge HTML.
     */
    public static function expandCell($value): string
    {
        $s = (string)$value;
        if ($s === '') {
            return '';
        }
        if (strpos($s, self::RS . 'bi_badge:') === false) {
            return e($s);
        }
        $parts = preg_split(
            '/' . preg_quote(self::RS, '/') . 'bi_badge:(\d+)' . preg_quote(self::RS, '/') . '/',
            $s,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        if ($parts === false) {
            return e($s);
        }
        $html = '';
        $n = count($parts);
        for ($i = 0; $i < $n; $i++) {
            if (($i % 2) === 0) {
                if ($parts[$i] !== '') {
                    $html .= e($parts[$i]);
                }
            } else {
                $uid = (int)$parts[$i];
                if (class_exists('UsersRepository', false)) {
                    $html .= UsersRepository::badgeHtml($uid, false, true);
                } else {
                    $html .= e('[' . $uid . ']');
                }
            }
        }
        return $html;
    }
}
