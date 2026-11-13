#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Regression checks for html_sanitize / safe_html (stored XSS).
 * Exit: 0 ok, 1 fail
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$failed = 0;
$assert = static function (bool $ok, string $msg) use (&$failed): void {
    if ($ok) {
        echo "OK  $msg\n";
        return;
    }
    echo "FAIL $msg\n";
    $failed++;
};

$neg = [
    '<img src=x onerror="alert(document.cookie)">',
    '<a href="javascript:alert(1)">click</a>',
    '<a href="JavaScript:alert(1)">click</a>',
    '<a href="&#106;avascript:alert(1)">click</a>',
    '<div style="position:fixed;top:0;left:0;width:100%;height:100%">overlay</div>',
    '<a onclick="alert(1)">x</a>',
    '<img src=x onerror=alert(1)//>',
    '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
    '<math><mtext><option><FAKEFAKE><option></option><mglyph><svg><mtext><img src=x onerror=alert(1)>',
    '<style><!--x--></style>',
];

foreach ($neg as $i => $in) {
    $out = safe_html($in);
    $bad = preg_match('/\bon[a-z]+\s*=/i', $out)
        || preg_match('/javascript\s*:/i', $out)
        || preg_match('/\sstyle\s*=/i', $out)
        || preg_match('/data\s*:\s*text\/html/i', $out)
        || strpos($out, '<!--') !== false;
    $assert(!$bad, "negative#$i stripped handlers/js/style/comments");
}

$pos = [
    '<b>ok</b>' => 'ok',
    '<i>x</i>' => 'x',
    '<a href="https://example.com/path">link</a>' => 'example.com',
    '<img src="https://example.com/a.png" alt="a">' => 'example.com/a.png',
    '<ul><li>one</li></ul>' => '<li>',
    '<img src="data:image/png;base64,iVBORw0KGgo=" alt="x">' => 'data:image/png;base64,',
    '<a href="/users">staff</a>' => 'href="/users"',
];
foreach ($pos as $in => $needle) {
    $out = safe_html($in);
    $assert(strpos($out, $needle) !== false, "positive keeps formatting: $needle");
}

$svgData = safe_html('<img src="data:image/svg+xml;base64,PHN2Zy4uLg==" alt="x">');
$assert(strpos($svgData, 'data:image/svg') === false, 'reject data:image/svg+xml');
$huge = 'data:image/png;base64,' . str_repeat('A', 50 * 1024);
$assert(strpos(safe_html('<img src="' . $huge . '" alt="x">'), 'data:image/png') === false, 'reject oversized data URI');

$internal = safe_html('<a href="/users">staff</a>');
$assert(strpos($internal, 'target=') === false, 'internal link no target=_blank');

$plain = comment_html("hello\nhttps://example.com/x");
$assert(strpos($plain, '<br') !== false || strpos($plain, '<br>') !== false, 'comment_html plain nl2br');
$assert(strpos($plain, 'href="https://example.com/x"') !== false, 'comment_html plain linkify');

$sample = '<p><b>Hi</b> <a href="https://ex.com">ex</a></p>';
$once = html_sanitize($sample);
$twice = html_sanitize($once);
$assert($once === $twice, 'sanitize idempotent');

$assert(html_ugc_reject_reason('<img src=x onerror=1>') === 'event_handler', 'ugc reject onerror in tag');
$assert(html_ugc_reject_reason('JavaScript: The Good Parts') === null, 'ugc allow book title');
$assert(html_ugc_reject_reason('see ?online=1 in URL') === null, 'ugc allow prose online=');
$assert(html_ugc_reject_reason('<b>ok</b>') === null, 'ugc allow bold');

// Rich storefront articles (theme.json rich_html): same negatives must stay stripped.
foreach ($neg as $i => $in) {
    $out = page_html($in, true);
    $bad = preg_match('/\bon[a-z]+\s*=/i', $out)
        || preg_match('/javascript\s*:/i', $out)
        || preg_match('/\sstyle\s*=/i', $out)
        || preg_match('/data\s*:\s*text\/html/i', $out)
        || strpos($out, '<!--') !== false;
    $assert(!$bad, "rich negative#$i stripped handlers/js/style/comments");
}
$rich = page_html('<section class="case-card" id="case-1"><figure><img src="/a.png" alt="a" loading="lazy"></figure>'
    . '<table><tr><th scope="col">A</th></tr></table><a href="/solutions/">in</a></section>', true);
$assert(strpos($rich, '<section class="case-card" id="case-1">') !== false, 'rich keeps section class/id');
$assert(strpos($rich, '<figure>') !== false && strpos($rich, 'loading="lazy"') !== false, 'rich keeps figure + img loading');
$assert(strpos($rich, '<th scope="col">') !== false, 'rich keeps table');
$assert(strpos($rich, 'nofollow') === false, 'rich internal link without nofollow');
$assert(strpos(page_html('<p class="x" onclick="1" id="1bad">t</p>', true), 'id=') === false, 'rich rejects bad id');
$assert(strpos(page_html('<section class="x">t</section>', false), 'class=') === false, 'non-rich still strips class');
$btn = page_html('<button type="submit" class="b" data-x="1" onclick="1">x</button><button type="button" aria-label="Copy">y</button>', true);
$assert(strpos($btn, 'type="submit"') === false && strpos($btn, 'data-x') === false, 'rich button: no submit type, no data-*');
$assert(strpos($btn, 'type="button" aria-label="Copy"') !== false, 'rich keeps type=button + aria-label');

exit($failed > 0 ? 1 : 0);
