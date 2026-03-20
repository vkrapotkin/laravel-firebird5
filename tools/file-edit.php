<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/file-edit.php <spec.json|->\n");
    exit(1);
}

$input = $argv[1] === '-'
    ? stream_get_contents(STDIN)
    : file_get_contents($argv[1]);

if ($input === false) {
    fwrite(STDERR, "Unable to read edit specification.\n");
    exit(1);
}

$spec = json_decode($input, true);

if (! is_array($spec)) {
    fwrite(STDERR, "Edit specification must be valid JSON.\n");
    exit(1);
}

$op = $spec['op'] ?? null;
$path = $spec['path'] ?? null;

if (! is_string($op) || ! is_string($path) || $path === '') {
    fwrite(STDERR, "Specification requires string fields: op, path.\n");
    exit(1);
}

switch ($op) {
    case 'write':
        if (! array_key_exists('content', $spec) || ! is_string($spec['content'])) {
            fwrite(STDERR, "Write operation requires string field: content.\n");
            exit(1);
        }

        ensureDirectory(dirname($path));
        writeFile($path, $spec['content']);
        break;

    case 'replace':
        if (! is_file($path)) {
            fwrite(STDERR, "Target file not found: {$path}\n");
            exit(1);
        }

        $search = $spec['search'] ?? null;
        $replace = $spec['replace'] ?? null;
        $all = (bool) ($spec['all'] ?? false);

        if (! is_string($search) || ! is_string($replace)) {
            fwrite(STDERR, "Replace operation requires string fields: search, replace.\n");
            exit(1);
        }

        $content = file_get_contents($path);

        if ($content === false) {
            fwrite(STDERR, "Unable to read target file: {$path}\n");
            exit(1);
        }

        if (! str_contains($content, $search)) {
            fwrite(STDERR, "Search text not found in target file.\n");
            exit(1);
        }

        $updated = $all
            ? str_replace($search, $replace, $content)
            : replaceFirst($content, $search, $replace);

        writeFile($path, $updated);
        break;

    default:
        fwrite(STDERR, "Unsupported operation: {$op}\n");
        exit(1);
}

function ensureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        fwrite(STDERR, "Unable to create directory: {$directory}\n");
        exit(1);
    }
}

function writeFile(string $path, string $content): void
{
    if (file_put_contents($path, $content) === false) {
        fwrite(STDERR, "Unable to write file: {$path}\n");
        exit(1);
    }
}

function replaceFirst(string $content, string $search, string $replace): string
{
    $position = strpos($content, $search);

    if ($position === false) {
        return $content;
    }

    return substr($content, 0, $position).$replace.substr($content, $position + strlen($search));
}
