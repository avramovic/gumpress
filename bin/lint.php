<?php

declare(strict_types=1);

/**
 * Portable `php -l` over one or more directories.
 *
 * Replaces `find … -print0 | xargs -0 -n1 php -l`, which needs a POSIX
 * shell and so left Windows contributors unable to run `composer lint` or
 * the build at all. PHP_BINARY is the absolute path to the interpreter
 * currently running, on every platform, so shelling out to it is portable
 * in a way that assuming a `php` on PATH is not.
 *
 * Usable two ways: run directly (`php bin/lint.php gumpress`), or require'd
 * by bin/build.php, which lints the source before building and the
 * generated artifacts afterwards.
 */

/** @return list<string> every *.php under $dir, recursively; $dir itself if it is a file */
function gumpress_php_files(string $dir): array
{
    if (is_file($dir)) {
        return str_ends_with($dir, '.php') ? [$dir] : [];
    }

    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files); // deterministic output, so failures are reported in a stable order

    return $files;
}

/**
 * @param list<string> $paths files or directories
 * @return int the number of files that failed to parse
 */
function gumpress_lint_paths(array $paths): int
{
    $failed = 0;
    $checked = 0;

    foreach ($paths as $path) {
        foreach (gumpress_php_files($path) as $file) {
            $checked++;

            $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file);
            exec($command . ' 2>&1', $output, $exitCode);

            if ($exitCode !== 0) {
                $failed++;
                fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
            }

            $output = [];
        }
    }

    if ($checked === 0) {
        fwrite(STDERR, "ERROR: no PHP files found to lint.\n");

        return 1;
    }

    return $failed;
}

// Only act when run directly — bin/build.php requires this file for the
// functions above and does its own reporting.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $targets = array_slice($argv, 1);

    if ($targets === []) {
        fwrite(STDERR, "Usage: php bin/lint.php <dir-or-file> [...]\n");
        exit(1);
    }

    $failures = gumpress_lint_paths($targets);

    if ($failures > 0) {
        fwrite(STDERR, "\n{$failures} file(s) failed to parse.\n");
        exit(1);
    }

    fwrite(STDOUT, "Syntax OK.\n");
}
