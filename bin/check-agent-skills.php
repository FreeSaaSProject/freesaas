#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Validate Agent Skills under .agents/skills/ (agentskills.io frontmatter + local refs).
 * Exit: 0 ok, 1 fail
 */
$root = dirname(__DIR__);
$skillsRoot = $root . '/.agents/skills';
$failed = 0;

/** @return array{ok:bool, meta?:array<string,mixed>, body?:string, error?:string} */
function parse_skill_md(string $path): array
{
    $raw = (string)file_get_contents($path);
    if (!preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n(.*)\z/s', $raw, $m)) {
        return ['ok' => false, 'error' => 'missing YAML frontmatter delimiters'];
    }
    $yaml = $m[1];
    $body = $m[2];
    $meta = [];
    $lines = preg_split('/\r?\n/', $yaml) ?: [];
    $key = null;
    $buf = '';
    $folded = false;
    foreach ($lines as $line) {
        if ($folded && $key !== null) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                $buf .= ($buf === '' ? '' : ' ') . trim($line);
                continue;
            }
            $meta[$key] = trim($buf);
            $folded = false;
            $key = null;
            $buf = '';
        }
        if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $mm)) {
            $k = $mm[1];
            $v = $mm[2];
            if (preg_match('/^[>|][-+]?$/', $v)) {
                $key = $k;
                $buf = '';
                $folded = true;
                continue;
            }
            if ($v === '') {
                // nested block (metadata:) — shallow accept; values filled by indented keys
                $meta[$k] = $meta[$k] ?? [];
                $key = $k;
                continue;
            }
            $meta[$k] = trim($v, " \t\"'");
            $key = null;
            continue;
        }
        if ($key !== null && is_array($meta[$key] ?? null) && preg_match('/^\s+([A-Za-z0-9_-]+):\s*(.*)$/', $line, $mm)) {
            /** @var array<string,string> $nest */
            $nest = $meta[$key];
            $nest[$mm[1]] = trim($mm[2], " \t\"'");
            $meta[$key] = $nest;
            continue;
        }
    }
    if ($folded && $key !== null) {
        $meta[$key] = trim($buf);
    }
    return ['ok' => true, 'meta' => $meta, 'body' => $body];
}

function fail(string $msg): void
{
    global $failed;
    echo "FAIL {$msg}\n";
    $failed++;
}

function ok(string $msg): void
{
    echo "OK   {$msg}\n";
}

if (!is_dir($skillsRoot)) {
    fail('.agents/skills missing');
    fwrite(STDERR, "agent-skills check failed ({$failed})\n");
    exit(1);
}

$dirs = glob($skillsRoot . '/*', GLOB_ONLYDIR) ?: [];
if ($dirs === []) {
    fail('no skill directories under .agents/skills');
}

$nameRe = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

foreach ($dirs as $dir) {
    $id = basename($dir);
    $skillFile = $dir . '/SKILL.md';
    if (!is_file($skillFile)) {
        fail("{$id}: missing SKILL.md");
        continue;
    }
    $parsed = parse_skill_md($skillFile);
    if (!$parsed['ok']) {
        fail("{$id}: {$parsed['error']}");
        continue;
    }
    /** @var array<string,mixed> $meta */
    $meta = $parsed['meta'] ?? [];
    $name = (string)($meta['name'] ?? '');
    $desc = (string)($meta['description'] ?? '');
    if ($name === '' || $desc === '') {
        fail("{$id}: name and description are required");
        continue;
    }
    if ($name !== $id) {
        fail("{$id}: frontmatter name \"{$name}\" must match directory");
    }
    if (strlen($name) > 64 || !preg_match($nameRe, $name)) {
        fail("{$id}: invalid name \"{$name}\" (lowercase, digits, single hyphens, max 64)");
    }
    if (strlen($desc) > 1024 || trim($desc) === '') {
        fail("{$id}: description must be 1–1024 characters");
    }
    if (isset($meta['compatibility']) && strlen((string)$meta['compatibility']) > 500) {
        fail("{$id}: compatibility exceeds 500 characters");
    }

    // Relative refs one level deep from skill root
    $body = (string)($parsed['body'] ?? '');
    if (preg_match_all('/\]\(([^)#]+\.(?:md|sh|php|json))\)/', $body, $refs)) {
        foreach ($refs[1] as $rel) {
            if (preg_match('#^https?://#', $rel) || str_starts_with($rel, '`')) {
                continue;
            }
            if (str_contains($rel, '..')) {
                fail("{$id}: avoid .. in skill-local links ({$rel})");
                continue;
            }
            $target = $dir . '/' . $rel;
            if (!is_file($target)) {
                fail("{$id}: broken relative link {$rel}");
            }
        }
    }

    ok("{$id} name={$name} desc_len=" . strlen($desc));
}

// Secret scan: skills must not embed private tokens
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($skillsRoot));
foreach ($iter as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile()) {
        continue;
    }
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['md', 'sh', 'php', 'json'], true)) {
        continue;
    }
    $text = (string)file_get_contents($file->getPathname());
    if (preg_match('/(api[_-]?key|secret|password)\s*[:=]\s*[\'\"][^\'\"]{8,}/i', $text)
        && !preg_match('/change-me|example|…|\.\.\./i', $text)) {
        $rel = substr($file->getPathname(), strlen($root) + 1);
        fail("possible secret in {$rel}");
    }
}

if ($failed > 0) {
    fwrite(STDERR, "agent-skills check failed ({$failed})\n");
    exit(1);
}
echo "agent-skills check ok (" . count($dirs) . " skills)\n";
exit(0);
