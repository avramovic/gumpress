<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every class under gumpress/src/ has to be listed in TWO places by hand,
 * because a drop-in has no autoloader: src/load.php requires them for the
 * folder form, and bin/build.php's $order concatenates them for the
 * single-file form. Miss either and the class simply isn't there at
 * runtime.
 *
 * That is exactly what happened with Vault.php: it was added, wired into
 * Api and Updater, covered by unit tests — and shipped in neither
 * distribution form, because the test bootstrap requires each class
 * explicitly and so never exercised load.php. `php -l` can't catch it and
 * BuildTest didn't either, since it only asserts two copies can co-exist.
 *
 * This test is the missing invariant. It doesn't care what the classes do,
 * only that nothing under src/ is silently left out of what ships.
 */
final class SourceManifestTest extends TestCase
{
    /** load.php bootstraps the list; it can't require itself. */
    private const NOT_SHIPPED_AS_A_CLASS = ['load.php'];

    /** @return list<string> bare filenames, e.g. ['Api.php', 'Config.php'] */
    private static function sourceFiles(): array
    {
        $files = glob(dirname(__DIR__).'/gumpress/src/*.php');
        self::assertNotEmpty($files, 'no source files found — is the path still right?');

        $names = array_map('basename', $files);

        return array_values(array_diff($names, self::NOT_SHIPPED_AS_A_CLASS));
    }

    public function test_load_php_requires_every_source_file(): void
    {
        $loadPhp = file_get_contents(dirname(__DIR__).'/gumpress/src/load.php');

        foreach (self::sourceFiles() as $file) {
            // assertTrue rather than assertStringContainsString: the latter
            // dumps the entire haystack file into the failure output.
            $this->assertTrue(
                str_contains($loadPhp, "require __DIR__ . '/{$file}';"),
                "{$file} is missing from src/load.php, so the drop-in folder build "
                .'will fatal with a class-not-found the first time it is used'
            );
        }
    }

    public function test_the_build_order_includes_every_source_file(): void
    {
        $buildPhp = file_get_contents(dirname(__DIR__).'/bin/build.php');

        foreach (self::sourceFiles() as $file) {
            $this->assertTrue(
                str_contains($buildPhp, "'src/{$file}'"),
                "{$file} is missing from bin/build.php's \$order, so it will be absent "
                .'from the single-file dist/GumPress.php build'
            );
        }
    }

    /**
     * Both lists are hand-maintained and must not drift apart either — a
     * class present in one form and not the other is the same bug, just
     * harder to notice.
     */
    public function test_the_two_manifests_agree(): void
    {
        $loadPhp = file_get_contents(dirname(__DIR__).'/gumpress/src/load.php');
        $buildPhp = file_get_contents(dirname(__DIR__).'/bin/build.php');

        preg_match_all("#require __DIR__ \. '/([A-Za-z0-9_]+\.php)';#", $loadPhp, $required);
        preg_match_all("#'src/([A-Za-z0-9_]+\.php)'#", $buildPhp, $ordered);

        sort($required[1]);
        sort($ordered[1]);

        $this->assertSame($required[1], $ordered[1]);
    }
}
