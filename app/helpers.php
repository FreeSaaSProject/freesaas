<?php
declare(strict_types=1);

function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Translate UI chrome key (see app/I18n.php). */
function t(string $key, array $replace = []): string {
    return I18n::t($key, $replace);
}

/** Escape translated string for HTML text/attrs. */
function te(string $key, array $replace = []): string {
    return e(I18n::t($key, $replace));
}

/** Translated string as JSON literal, HTML-escaped for inline JS attributes (e.g. onsubmit="return confirm(<?= tj('key') ?>)"). */
function tj(string $key, array $replace = []): string {
    return e((string)json_encode(I18n::t($key, $replace), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
}

/** Alias of t(). */
function tf(string $key, array $replace = []): string {
    return I18n::t($key, $replace);
}

/** Escape text but keep line breaks (comments / plain bodies). UTF-8 emoji preserved. */
function e_nl(string $s): string {
    return nl2br(e($s), false);
}

/**
 * Normalize clipboard / statement amounts: "1 203 323,23", NBSP, thin spaces → "1203323.23".
 * Empty input → "".
 */
function money_normalize_input(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = str_replace("\xc2\xa0", ' ', $value);
    $value = (string)preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $value);
    $value = (string)preg_replace('/[\p{Z}\s]+/u', '', $value);
    return str_replace(',', '.', $value);
}

/** Parse money paste to float; invalid / empty → 0.0. */
function money_parse(string $value): float
{
    $normalized = money_normalize_input($value);
    if ($normalized === '' || !is_numeric($normalized)) {
        return 0.0;
    }
    return round((float)$normalized, 2);
}

/**
 * Signed amount HTML: green "+N" for direction>0, red "-N" for direction<0 (oper show_account_moves).
 */
function money_signed_html($amount, int $direction = 0): string
{
    $n = round((float)$amount, 2);
    $formatted = number_format(abs($n), 2, '.', ' ');
    if ($direction > 0) {
        return '<strong class="money-signed money-in">+' . e($formatted) . '</strong>';
    }
    if ($direction < 0) {
        return '<strong class="money-signed money-out">−' . e($formatted) . '</strong>';
    }
    return '<strong class="money-signed">' . e(number_format($n, 2, '.', ' ')) . '</strong>';
}

/** Short label for long URLs (mobile-friendly). */
function short_url_label(string $url, int $max = 36): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $display = preg_replace('#^https?://#i', '', $url) ?? $url;
    $display = rtrim((string)$display, '/');
    if (mb_strlen($display, 'UTF-8') <= $max) {
        return $display;
    }
    return mb_substr($display, 0, $max - 1, 'UTF-8') . '…';
}

function linkify_text(string $text): string {
    $escaped = e($text);
    return (string)preg_replace_callback(
        '#(https?://[^\s<]+)#iu',
        static function (array $m): string {
            $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            $url = rtrim($url, '.,);]');
            return '<a href="' . e($url) . '" rel="noopener noreferrer" target="_blank" class="url-short" title="' . e($url) . '">'
                . e(short_url_label($url)) . '</a>';
        },
        $escaped
    );
}

function linkify_html(string $html): string {
    $html = (string)preg_replace_callback(
        '#<a\s+([^>]*href=["\']([^"\']+)["\'][^>]*)>(.*?)</a>#is',
        static function (array $m): string {
            $href = $m[2];
            $innerHtml = $m[3];
            $inner = trim(strip_tags($innerHtml));
            // Keep media-only anchors (e.g. 2GIS rating badge <a><img></a>).
            if ($inner === '' && preg_match('#<(img|svg|picture)\b#i', $innerHtml)) {
                return '<a ' . $m[1] . '>' . $innerHtml . '</a>';
            }
            $label = short_url_label($inner !== '' ? $inner : $href);
            $attrs = $m[1];
            if (stripos($attrs, 'class=') === false) {
                $attrs .= ' class="url-short"';
            }
            if (stripos($attrs, 'title=') === false) {
                $attrs .= ' title="' . e($href) . '"';
            }
            return '<a ' . $attrs . '>' . e($label) . '</a>';
        },
        $html
    );
    $parts = preg_split('/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) {
        return $html;
    }
    $out = '';
    foreach ($parts as $part) {
        if ($part === '' || ($part[0] ?? '') === '<') {
            $out .= $part;
            continue;
        }
        $out .= (string)preg_replace_callback(
            '#(https?://[^\s<]+)#iu',
            static function (array $m): string {
                $url = rtrim($m[1], '.,);]');
                return '<a href="' . e($url) . '" rel="noopener noreferrer" target="_blank" class="url-short" title="' . e($url) . '">'
                    . e(short_url_label($url)) . '</a>';
            },
            $part
        );
    }
    return $out;
}

/**
 * Soft HTML for news BIG (legacy Pell HTML). Keeps unicode emoji; strips scripts.
 */
function safe_html(string $html): string {
    $html = preg_replace('#<(script|iframe|object|embed|form)[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#</?(script|iframe|object|embed|form)[^>]*>#i', '', $html) ?? $html;
    $allowed = '<p><br><br/><b><strong><i><em><u><s><ul><ol><li><a><span><div><h1><h2><h3><h4><blockquote><code><pre><hr><img>';
    return linkify_html(strip_tags($html, $allowed));
}

/**
 * CMS article body: safe_html plus allowlisted review widgets (Yandex Maps / 2GIS).
 * Do not use for news or comments — those stay on safe_html.
 */
function page_html(string $html): string {
    $kept = [];
    $html = preg_replace_callback(
        '#<iframe\b([^>]*)>(.*?)</iframe>|<iframe\b([^>]*)/?>#is',
        static function (array $m) use (&$kept): string {
            $attrs = isset($m[1]) && $m[1] !== '' ? $m[1] : (string)($m[3] ?? '');
            $built = page_html_build_iframe($attrs);
            if ($built === null) {
                return '';
            }
            $i = count($kept);
            $kept[] = $built;
            return '{{PAGE_IFRAME_' . $i . '}}';
        },
        $html
    ) ?? $html;
    $out = safe_html($html);
    foreach ($kept as $i => $iframe) {
        $out = str_replace('{{PAGE_IFRAME_' . $i . '}}', $iframe, $out);
    }
    return $out;
}

/** @return non-empty-string|null */
function page_html_build_iframe(string $attrBlob): ?string {
    if (!preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $attrBlob, $mm, PREG_SET_ORDER)) {
        return null;
    }
    $attrs = [];
    foreach ($mm as $row) {
        $name = strtolower($row[1]);
        if (preg_match('/=\s*"/', $row[0])) {
            $val = $row[2];
        } elseif (preg_match("/=\s*'/", $row[0])) {
            $val = $row[3];
        } else {
            $val = $row[4] ?? '';
        }
        $attrs[$name] = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $src = trim((string)($attrs['src'] ?? ''));
    if ($src === '' || !page_html_iframe_src_allowed($src)) {
        return null;
    }
    $allowedKeys = ['src', 'width', 'height', 'title', 'loading', 'style', 'frameborder', 'allowfullscreen', 'referrerpolicy'];
    $parts = [];
    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $attrs)) {
            continue;
        }
        $val = trim((string)$attrs[$key]);
        if ($val === '') {
            continue;
        }
        if ($key === 'src') {
            $val = $src;
        }
        if ($key === 'allowfullscreen' && !preg_match('/^(1|true|allowfullscreen)$/i', $val)) {
            $val = 'true';
        }
        if ($key === 'style' && preg_match('/expression|javascript|url\s*\(\s*[\'"]?\s*javascript/i', $val)) {
            continue;
        }
        $parts[] = $key . '="' . htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }
    if ($parts === []) {
        return null;
    }
    return '<iframe ' . implode(' ', $parts) . '></iframe>';
}

function page_html_iframe_src_allowed(string $src): bool {
    if (preg_match('#^\s*javascript:#i', $src) || preg_match('#^\s*data:#i', $src)) {
        return false;
    }
    $p = parse_url($src);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        return false;
    }
    $scheme = strtolower((string)$p['scheme']);
    if ($scheme !== 'https' && $scheme !== 'http') {
        return false;
    }
    $host = strtolower((string)$p['host']);
    $path = (string)($p['path'] ?? '');
    if (in_array($host, ['yandex.ru', 'www.yandex.ru', 'yandex.com', 'www.yandex.com'], true)
        && preg_match('#^/maps-reviews-widget/#', $path)) {
        return true;
    }
    if ($host === 'widget.2gis.ru') {
        return true;
    }
    return false;
}

/**
 * Comment body: keep simple HTML if present; otherwise escape + newlines + linkify.
 */
function comment_html(string $text): string {
    $plain = trim(strip_tags($text));
    $raw = trim($text);
    if ($raw === '' || $raw === $plain) {
        return nl2br(linkify_text($text), false);
    }
    return safe_html($text);
}

/** Normalize captured "new value" from legacy status brackets. */
function status_change_value(string $raw): string {
    $v = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
    return trim($v);
}

/**
 * Split status-update comment into compact summary + body.
 * Legacy: "[ОЛ изменены с: … на X][Куратор…][Упр.контр.…] [Обоснование]:text"
 * New:    "ОЛ → X; Куратор → Y\ntext"
 *
 * @return array{summary:string,body:string}
 */
function status_comment_parts(string $text): array {
    $summary = [];
    $body = $text;

    // Obsolete managing-contract line — drop silently.
    $body = preg_replace('/\[Упр\.?\s*контр\.?[^\]]*\]/iu', '', $body) ?? $body;

    // Stored comment text stays in canonical Russian (operational parser); labels are translated for display.
    $rules = [
        ['re' => '/\[ОЛ изменены с:\s*.+?\s+на\s+(.+?)\]/isu', 'label' => 'helpers.meta.resp'],
        ['re' => '/\[Куратор заявки изменен с:\s*.+?\s+на\s+(.+?)\]/isu', 'label' => 'helpers.meta.curator'],
        ['re' => '/\[Статус изменен с\s*.+?\s+на\s+(.+?)\]/isu', 'label' => 'helpers.meta.status'],
        ['re' => '/\[Статус изменён на:\s*(.+?)\]/isu', 'label' => 'helpers.meta.status'],
        ['re' => '/\[Статус\s*→\s*(.+?)\]/isu', 'label' => 'helpers.meta.status'],
        ['re' => '/\[Дедлайн(?:\/ИНИТ)? изменен с\s*.+?\s+на\s+(.+?)\]/isu', 'label' => 'helpers.meta.deadline'],
        ['re' => '/\[Дедлайн\s*→\s*(.+?)\]/isu', 'label' => 'helpers.meta.deadline'],
    ];

    /** @var list<array{pos:int,len:int,label:string,value:string}> $hits */
    $hits = [];
    foreach ($rules as $rule) {
        if (!preg_match_all($rule['re'], $body, $mm, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($mm[0] as $i => $full) {
            $val = status_change_value((string)($mm[1][$i][0] ?? ''));
            if ($val === '') {
                continue;
            }
            $hits[] = [
                'pos' => (int)$full[1],
                'len' => strlen($full[0]),
                'label' => $rule['label'],
                'value' => $val,
            ];
        }
    }

    if ($hits) {
        usort($hits, static fn($a, $b) => $a['pos'] <=> $b['pos']);
        $ordered = $hits;
        for ($i = count($hits) - 1; $i >= 0; $i--) {
            $h = $hits[$i];
            $body = substr_replace($body, '', $h['pos'], $h['len']);
        }
        foreach ($ordered as $h) {
            $summary[] = t($h['label']) . ' → ' . $h['value'];
        }
    }

    // Drop leftover auto/system brackets we don't surface.
    $body = preg_replace(
        '/\[(?:Дедлайн\/ИНИТ автоматически|Статус автоматически|Изменения статусов блокированы)[^\]]*\]/iu',
        '',
        $body
    ) ?? $body;

    $body = preg_replace('/\s*\[Обоснование\]\s*:?\s*/iu', '', $body) ?? $body;
    $body = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $body) ?? trim($body);
    $body = preg_replace('/\n{3,}/u', "\n\n", $body) ?? $body;

    if (!$summary) {
        $lines = preg_split("/\r\n|\n|\r/", $body, 2) ?: [''];
        $first = trim((string)$lines[0]);
        if ($first !== '' && preg_match('/^(?:ОЛ|Куратор|Статус|ДЛ)\s*→/u', $first)) {
            $labelKeys = [
                'ОЛ' => 'helpers.meta.resp',
                'Куратор' => 'helpers.meta.curator',
                'Статус' => 'helpers.meta.status',
                'ДЛ' => 'helpers.meta.deadline',
            ];
            $segments = [];
            foreach (explode(';', $first) as $seg) {
                if (preg_match('/^\s*(ОЛ|Куратор|Статус|ДЛ)\s*→\s*(.*)$/su', $seg, $sm)) {
                    $val = trim($sm[2]);
                    if ($val === 'откл.') {
                        $val = t('helpers.meta.off');
                    }
                    $segments[] = t($labelKeys[$sm[1]]) . ' → ' . $val;
                } else {
                    $segments[] = trim($seg);
                }
            }
            return [
                'summary' => implode('; ', $segments),
                'body' => trim((string)($lines[1] ?? '')),
            ];
        }
    }

    return [
        'summary' => implode('; ', $summary),
        'body' => trim($body),
    ];
}

/** Deadline chip HTML for tickets. */
function deadline_chip(int $ts, bool $compact = false): string {
    if ($ts <= 0) {
        return '';
    }
    $date = $compact ? I18n::formatDate($ts, 'daymonth') : I18n::formatDate($ts, 'date');
    $label = t('helpers.deadline_chip', ['date' => $date]);
    $cls = 'deadline-chip';
    $end = mktime(23, 59, 59, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts));
    if ($end < time()) {
        $cls .= ' is-overdue';
    } elseif ($end < time() + 2 * 86400) {
        $cls .= ' is-soon';
    }
    return '<span class="' . $cls . '" title="' . e(I18n::formatDate($ts, 'datetime')) . '">' . e($label) . '</span>';
}

/** Colored status pill from base_news_statuses row. */
function status_pill(?array $status, string $fallback = ''): string {
    if (!$status) {
        return $fallback !== '' ? '<span class="pill status-pill">' . e($fallback) . '</span>' : '';
    }
    $name = (string)($status['NAME'] ?? '');
    $color = trim((string)($status['COLOR'] ?? ''));
    if ($color === '' || strtolower($color) === 'white' || $color === '#fff' || $color === '#ffffff') {
        return '<span class="pill status-pill">' . e($name) . '</span>';
    }
    return '<span class="pill status-pill" style="background:' . e($color) . '33;color:' . e($color) . '">' . e($name) . '</span>';
}

/**
 * One-shot PRG flash (session). Type: ok|warn|error.
 */
function flash_set(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['freesaas_flash'] = ['type' => $type, 'message' => $message];
}

/** @return array{type:string,message:string}|null */
function flash_take(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['freesaas_flash'])) {
        return null;
    }
    $flash = $_SESSION['freesaas_flash'];
    unset($_SESSION['freesaas_flash']);
    return is_array($flash) ? $flash : null;
}

function redirect(string $url): void {
    if (class_exists('NavContext', false)) {
        $url = NavContext::redirectUrl($url);
    }
    header('Location: ' . $url);
    exit;
}

function request_path(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';
    if ($path !== '/' && substr($path, -1) === '/') {
        $path = rtrim($path, '/');
    }
    return $path === '' ? '/' : $path;
}

function base_path(string $rel = ''): string {
    return dirname(__DIR__) . ($rel !== '' ? '/' . ltrim($rel, '/') : '');
}
