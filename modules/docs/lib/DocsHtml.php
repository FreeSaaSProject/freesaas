<?php
declare(strict_types=1);

/**
 * Print-template sanitizer: wider than safe_html() (tables, inline layout style)
 * but still no scripts / forms / iframes / event handlers / js: URLs.
 */
final class DocsHtml
{
    /** @var array<string,list<string>> */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [],
        'small' => [], 'sup' => [], 'sub' => [], 'span' => [], 'div' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [],
        'ul' => [], 'ol' => ['start', 'type'], 'li' => [], 'blockquote' => [], 'pre' => [], 'code' => [], 'hr' => [],
        'center' => [],
        'table' => ['border', 'cellpadding', 'cellspacing', 'width'],
        'thead' => [], 'tbody' => [], 'tfoot' => [], 'caption' => [],
        'tr' => [], 'td' => ['colspan', 'rowspan', 'width', 'align', 'valign'],
        'th' => ['colspan', 'rowspan', 'width', 'align', 'valign'],
        'col' => ['width', 'span'], 'colgroup' => ['span'],
        'img' => ['src', 'alt', 'width', 'height'],
    ];

    /** Attributes allowed on every allowed tag. */
    private const COMMON = ['style', 'class', 'align'];

    public static function sanitizeTemplate(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        if (!class_exists('DOMDocument')) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        try {
            $ok = @$doc->loadHTML(
                '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>'
                . '<body><div id="docs-sanitize-root">' . $html . '</div></body></html>',
                LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        $root = $ok ? $doc->getElementById('docs-sanitize-root') : null;
        if (!$root instanceof DOMElement) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $xpath = new DOMXPath($doc);
        $nodes = [];
        foreach ($xpath->query('.//*', $root) ?: [] as $el) {
            if ($el instanceof DOMElement) {
                $nodes[] = $el;
            }
        }
        for ($i = count($nodes) - 1; $i >= 0; $i--) {
            $el = $nodes[$i];
            $parent = $el->parentNode;
            if (!$parent) {
                continue;
            }
            $tag = strtolower($el->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'template'], true)) {
                $parent->removeChild($el);
                continue;
            }
            if (!isset(self::ALLOWED[$tag])) {
                while ($el->firstChild) {
                    $parent->insertBefore($el->firstChild, $el);
                }
                $parent->removeChild($el);
                continue;
            }
            $allowed = array_merge(self::ALLOWED[$tag], self::COMMON);
            $drop = [];
            foreach ($el->attributes as $attr) {
                $name = strtolower($attr->name);
                $value = (string)$attr->value;
                if (!in_array($name, $allowed, true)) {
                    $drop[] = $attr->name;
                    continue;
                }
                if ($name === 'style') {
                    $clean = self::cleanStyle($value);
                    if ($clean === '') {
                        $drop[] = $attr->name;
                    } else {
                        $el->setAttribute('style', $clean);
                    }
                } elseif ($name === 'src' && !html_safe_url($value, false, true)) {
                    $drop[] = $attr->name;
                } elseif (in_array($name, ['align', 'valign'], true) && !preg_match('/^(left|right|center|justify|top|middle|bottom)$/i', $value)) {
                    $drop[] = $attr->name;
                } elseif ($name === 'class' && !preg_match('/^[a-zA-Z0-9_\- ]{0,120}$/', $value)) {
                    $drop[] = $attr->name;
                }
            }
            foreach ($drop as $name) {
                $el->removeAttribute($name);
            }
        }
        foreach ($xpath->query('.//comment()', $root) ?: [] as $c) {
            if ($c->parentNode) {
                $c->parentNode->removeChild($c);
            }
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    /** Layout-only CSS declarations (no url(), expression(), imports, positioning overlays). */
    private static function cleanStyle(string $style): string
    {
        $decoded = html_entity_decode($style, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/(url\s*\(|expression|javascript|@import|behavior|-moz-binding|position\s*:\s*(fixed|absolute))/i', $decoded)) {
            return '';
        }
        $out = [];
        foreach (explode(';', $decoded) as $decl) {
            $decl = trim($decl);
            if ($decl === '' || strpos($decl, ':') === false) {
                continue;
            }
            [$prop, $val] = array_map('trim', explode(':', $decl, 2));
            if (!preg_match('/^[a-z\-]{1,40}$/i', $prop) || !preg_match('/^[a-z0-9#%.,\-\s"\'()]{1,120}$/i', $val)) {
                continue;
            }
            $out[] = strtolower($prop) . ':' . $val;
        }
        return implode(';', $out);
    }
}
