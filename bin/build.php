<?php

declare(strict_types=1);

/**
 * The whole build. Run it with `composer build`, or directly:
 *
 *   php bin/build.php [version] [ns-suffix] [dist-dir]
 *
 * All three are optional and default to VERSION / `v` + that with dots as
 * underscores / `dist`. They exist so tests/BuildTest.php can build a
 * throwaway copy under its own version and directory.
 *
 * Deliberately pure PHP, no shell: this replaced a build.sh whose find /
 * xargs / rm -rf left Windows contributors unable to build at all.
 *
 * Produces two artifacts:
 *
 *   dist/gumpress/      A copy of the gumpress/ drop-in folder, with the
 *                        dev-time `GumPress\V2` namespace rewritten to a
 *                        version-suffixed one (e.g. GumPress\v2_0_0). This
 *                        is what makes it safe for several plugins on one
 *                        site to each bundle a different GumPress release:
 *                        every copy's engine lives in its own namespace, so
 *                        none of them collide.
 *
 *   dist/GumPress.php   The same code concatenated into a single file, for
 *                        anyone who prefers v1's one-file shape.
 */

require __DIR__ . '/lint.php';

$root = dirname(__DIR__);
$srcRoot = $root . '/gumpress';

$version = $argv[1] ?? '';
if ($version === '') {
    // Matches build.sh's `tr -d '[:space:]'` — strips all whitespace, not
    // just the trailing newline.
    $version = preg_replace('/\s+/', '', (string) file_get_contents($root . '/VERSION'));
}

$nsSuffix = $argv[2] ?? '';
if ($nsSuffix === '') {
    $nsSuffix = 'v' . str_replace('.', '_', $version);
}

$distRelative = $argv[3] ?? 'dist';

if ($version === '' || $nsSuffix === '') {
    fwrite(STDERR, "ERROR: could not determine a version to build.\n");
    exit(1);
}

$distRoot = $root . '/' . $distRelative;
$distModule = $distRoot . '/gumpress';

/**
 * Collapses `.` and `..` segments and normalises separators, WITHOUT
 * realpath() — which returns false for a directory that doesn't exist yet,
 * and so would silently defeat every check below on a first build.
 *
 * Windows drive letters end up prefixed with `/` ("C:/x" -> "/C:/x"). That's
 * not a real path, but both sides of every comparison go through this same
 * function, so it doesn't affect the result.
 */
function gumpress_canonical(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $absolute = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1;

    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);

            continue;
        }
        $segments[] = $segment;
    }

    return ($absolute ? '/' : '') . implode('/', $segments);
}

/**
 * This script recursively deletes $distRoot, and $distRelative now has a
 * default — precisely the shape that once resolved onto the source tree and
 * deleted gumpress/ outright. The rule is therefore positive rather than a
 * blocklist: the target must sit strictly INSIDE the repo, and must not
 * touch the source tree in either direction. Anything else is refused.
 *
 * An earlier version of this guard compared unresolved strings and let `.`
 * through, which would have deleted the repo root.
 */
$canonicalDist = gumpress_canonical($distRoot);
$canonicalSrc = gumpress_canonical($srcRoot);
$canonicalRoot = gumpress_canonical($root);

$insideRepo = str_starts_with($canonicalDist . '/', $canonicalRoot . '/')
    && $canonicalDist !== $canonicalRoot;

$clearOfSource = $canonicalDist !== $canonicalSrc
    && !str_starts_with($canonicalDist . '/', $canonicalSrc . '/')
    && !str_starts_with($canonicalSrc . '/', $canonicalDist . '/');

$isAbsoluteInput = str_starts_with(str_replace('\\', '/', $distRelative), '/')
    || preg_match('#^[A-Za-z]:#', $distRelative) === 1;

if ($distRelative === '' || $isAbsoluteInput || !$insideRepo || !$clearOfSource) {
    fwrite(STDERR, "ERROR: refusing to build — <dist-dir> must be a relative path inside the\n");
    fwrite(STDERR, "       repository that does not overlap the repo root or gumpress/.\n");
    fwrite(STDERR, "       Got: '{$distRelative}'\n");
    exit(1);
}

fwrite(STDOUT, "Building GumPress {$version} (namespace suffix: GumPress\\{$nsSuffix})\n");

fwrite(STDOUT, "==> Linting source\n");
if (gumpress_lint_paths([$srcRoot]) > 0) {
    fwrite(STDERR, "ERROR: source failed to lint; not building.\n");
    exit(1);
}

function gumpress_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? gumpress_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * Stamps $version into a `'version' => '...'` literal, wherever it currently
 * points — not a hardcoded '2.0.0' match. The old code searched for the
 * literal string '2.0.0' specifically, which would silently stop matching
 * (0 replacements, stale version shipped) the moment gumpress.php's own
 * source-of-truth literal is ever bumped to anything else. Fails loudly
 * instead of silently no-op'ing if the pattern isn't found at all.
 */
function gumpress_stamp_version(string $contents, string $version, string $context): string
{
    $count = 0;
    $stamped = preg_replace(
        '/(\'version\'\s*=>\s*)\'[^\']*\'/',
        '$1\'' . $version . '\'',
        $contents,
        1,
        $count
    );

    if ($count !== 1) {
        fwrite(STDERR, "ERROR: could not find \"'version' => '...'\" to stamp in {$context}.\n");
        exit(1);
    }

    return $stamped;
}

function gumpress_copy_rewriting(string $from, string $to, string $nsSuffix): void
{
    if (is_dir($from)) {
        if (!is_dir($to)) {
            mkdir($to, 0777, true);
        }
        foreach (scandir($from) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            gumpress_copy_rewriting("$from/$entry", "$to/$entry", $nsSuffix);
        }

        return;
    }

    $contents = file_get_contents($from);
    if (str_ends_with($from, '.php')) {
        $contents = str_replace('GumPress\\V2', 'GumPress\\' . $nsSuffix, $contents);
    }
    file_put_contents($to, $contents);
}

// --- 1. Drop-in folder, namespace rewritten. ---

// Clears the whole dist dir, not just dist/gumpress — build.sh's `rm -rf
// dist` is what used to guarantee that, and without it a renamed or
// removed artifact would linger from an earlier build.
fwrite(STDOUT, "==> Preparing {$distRelative}/\n");
gumpress_rrmdir($distRoot);
mkdir($distRoot, 0777, true);

gumpress_copy_rewriting($srcRoot, $distModule, $nsSuffix);

$bootstrap_path = $distModule . '/gumpress.php';
$bootstrap = file_get_contents($bootstrap_path);
$bootstrap = gumpress_stamp_version($bootstrap, $version, $bootstrap_path);
file_put_contents($bootstrap_path, $bootstrap);

// --- 2. Single concatenated file. ---

$order = [
    'src/Data.php',
    'src/Notices.php',
    'src/Config.php',
    'src/Vault.php',
    'src/Env.php',
    'src/License.php',
    'src/Status.php',
    'src/Validator.php',
    'src/Strings.php',
    'src/Overrides.php',
    'src/Api.php',
    'src/Module.php',
    'src/NullModule.php',
    'src/Admin.php',
    'src/Updater.php',
    'src/Engine.php',
];

$out = "<?php\n";
$out .= "/**\n";
$out .= " * GumPress {$version} — single-file build.\n";
$out .= " *\n";
$out .= " * Generated by bin/build.php. Do not edit directly — edit the source under\n";
$out .= " * gumpress/ and rebuild. See README.md / MIGRATION.md for integration.\n";
$out .= " */\n\n";
$out .= "declare(strict_types=1);\n\n";
$out .= "namespace GumPress\\{$nsSuffix} {\n\n";

// Two different plugins/themes on one site can each bundle a single-file
// build of the exact same GumPress version — unlike the gumpress/ folder
// form, a plain concatenation has no include-guard, so the second one to
// load would fatal trying to redeclare every class here. Guard the whole
// block the same way src/load.php guards the folder form; the code AFTER
// this namespace block (the facade's own $GLOBALS['gumpress']['sources']
// registration) still runs on every copy regardless — only re-declaring
// the shared engine classes is skipped.
$out .= "if (!class_exists(__NAMESPACE__ . '\\\\Engine', false)) {\n\n";

foreach ($order as $rel) {
    $body = file_get_contents("$srcRoot/$rel");
    $body = preg_replace('/^<\?php\s*/', '', $body, 1);
    $body = preg_replace('/^declare\(strict_types=1\);\s*/', '', $body, 1);
    $body = preg_replace('/^namespace\s+GumPress\\\\V2;\s*/', '', $body, 1);
    $out .= trim($body) . "\n\n";
}

$out .= "}\n\n"; // end class_exists guard
$out .= "}\n\n"; // end namespace block

$facade = file_get_contents($srcRoot . '/gumpress.php');
$facade = preg_replace('/^<\?php\s*/', '', $facade, 1);
$facade = preg_replace('/^.*require_once __DIR__ \. \'\/src\/load\.php\';\s*\n/m', '', $facade, 1);
$facade = gumpress_stamp_version($facade, $version, $srcRoot . '/gumpress.php');
$facade = str_replace('GumPress\\V2\\', "GumPress\\{$nsSuffix}\\", $facade);

$out .= "namespace {\n\n" . trim($facade) . "\n\n}\n";

file_put_contents($distRoot . '/GumPress.php', $out);

fwrite(STDOUT, "Wrote {$distModule} and {$distRoot}/GumPress.php\n");

// The concatenation above rewrites namespaces and strips headers with
// regexes, so linting the result is a real guard, not a formality.
fwrite(STDOUT, "==> Linting build output\n");
if (gumpress_lint_paths([$distRoot]) > 0) {
    fwrite(STDERR, "ERROR: the generated build does not parse.\n");
    exit(1);
}

fwrite(STDOUT, "\nDone.\n");
fwrite(STDOUT, "  Drop-in folder: {$distRelative}/gumpress/\n");
fwrite(STDOUT, "  Single file:    {$distRelative}/GumPress.php\n");
