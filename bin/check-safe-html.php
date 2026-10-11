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

exit($failed > 0 ? 1 : 0);
