<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression test for a bug in GumPress::resolve_caller() (gumpress.php):
 * debug_backtrace()'s frame 0 describes the call to resolve_caller() itself,
 * made from __callStatic() inside gumpress.php — not the developer's own
 * call site. The old code took that first non-empty 'file' frame unchecked,
 * so every bare GumPress::x() call resolved against wherever gumpress.php
 * itself lives (which happens to prefix-match its own bundling module's
 * owned_dirs()), never the actual caller. Invisible on a single-module site
 * (the sole answer is also the right one by luck); silently wrong the
 * moment a second module registers.
 *
 * This has to run out-of-process (like BuildTest): the bug is inherently
 * about *which file on disk* a call originates from, so it needs two real,
 * separate plugin directories each bundling their own copy of gumpress/ —
 * not something a single in-process PHPUnit run using one shared class
 * table can exercise.
 */
final class ResolveCallerTest extends TestCase
{
    private static string $gumpressSrcDir;

    public static function setUpBeforeClass(): void
    {
        self::$gumpressSrcDir = dirname(__DIR__).'/gumpress';
    }

    private static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = "{$dir}/{$item}";
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private static function copyDirectory(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (array_diff(scandir($from), ['.', '..']) as $item) {
            $src = "{$from}/{$item}";
            $dst = "{$to}/{$item}";
            is_dir($src) ? self::copyDirectory($src, $dst) : copy($src, $dst);
        }
    }

    /**
     * Two real plugin directories, each bundling its OWN copy of gumpress/
     * (the conventional {module}/gumpress/ layout owning_source_dir() and
     * resolve_caller() both key off), each registering a distinct product,
     * each calling a bare GumPress::product_id() from a function defined in
     * its own plugin file. Only the first copy's gumpress.php actually
     * declares the shared `GumPress` class (the second's load is a no-op
     * past the class_exists() guard) — exactly the "two plugins bundle the
     * same version" case the facade's own docblock calls out as supported.
     */
    public function test_bare_calls_from_two_registered_modules_resolve_to_their_own_module(): void
    {
        $pluginA = sys_get_temp_dir().'/gumpress-resolvetest-a-'.uniqid();
        $pluginB = sys_get_temp_dir().'/gumpress-resolvetest-b-'.uniqid();

        self::copyDirectory(self::$gumpressSrcDir, $pluginA.'/gumpress');
        self::copyDirectory(self::$gumpressSrcDir, $pluginB.'/gumpress');

        file_put_contents($pluginA.'/plugin-a.php', $this->pluginFile('product-a'));
        file_put_contents($pluginB.'/plugin-b.php', $this->pluginFile('product-b'));

        $runnerPath = sys_get_temp_dir().'/gumpress-resolvetest-runner-'.uniqid().'.php';
        file_put_contents($runnerPath, $this->runnerScript($pluginA, $pluginB));

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($runnerPath).' 2>&1', $output, $exitCode);

        unlink($runnerPath);
        self::removeDirectory($pluginA);
        self::removeDirectory($pluginB);

        $this->assertSame(0, $exitCode, "Runner failed:\n".implode("\n", $output));
        $this->assertSame('product-a|product-b', trim(implode('', $output)));
    }

    private function pluginFile(string $product): string
    {
        return <<<PHP
        <?php
        require __DIR__ . '/gumpress/gumpress.php';
        \\GumPress::register(__FILE__, '{$product}');

        function call_bare_from_{$this->safeSuffix($product)}() {
            return \\GumPress::product_id();
        }
        PHP;
    }

    private function safeSuffix(string $product): string
    {
        return str_replace('-', '_', $product);
    }

    private function runnerScript(string $pluginA, string $pluginB): string
    {
        return <<<PHP
        <?php
        define('ABSPATH', '/tmp/');
        function home_url(\$p = '/') { return 'https://example.com' . \$p; }
        function wp_get_environment_type() { return 'production'; }
        function wp_normalize_path(\$p) { return str_replace('\\\\', '/', (string) \$p); }
        function __(\$t, \$d = null) { return \$t; }
        function _n(\$s, \$p, \$n, \$d = null) { return \$n == 1 ? \$s : \$p; }
        function esc_html(\$t) { return htmlspecialchars((string) \$t, ENT_QUOTES); }
        function add_action(...\$a) { return true; }
        function did_action(\$h) { return false; }
        function is_multisite() { return false; }

        require '{$pluginA}/plugin-a.php';
        require '{$pluginB}/plugin-b.php';

        echo call_bare_from_product_a() . '|' . call_bare_from_product_b();
        PHP;
    }
}
