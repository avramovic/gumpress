<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Two different plugins/themes on one WordPress site can each bundle the
 * exact same GumPress version — support for that is a hard requirement,
 * not a nice-to-have. The gumpress/ folder form has always been safe
 * (src/load.php's class_exists guard skips re-declaring the shared engine
 * classes). The single-file form had no such guard at all: a second plugin
 * bundling the identical dist/GumPress.php build would fatal trying to
 * redeclare every class in it. Caught by hand against a real WordPress
 * install with two such plugins active at once, before bin/build.php grew
 * the same class_exists(__NAMESPACE__.'\Engine') guard the folder form uses.
 */
final class BuildTest extends TestCase
{
    private static string $distDir;

    private static string $singleFilePath;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__);
        $relativeDist = 'dist-buildtest-tmp';
        self::$distDir = $root.'/'.$relativeDist;

        if (is_dir(self::$distDir)) {
            self::removeDirectory(self::$distDir);
        }

        $command = sprintf(
            'cd %s && %s %s %s %s %s 2>&1',
            escapeshellarg($root),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root.'/bin/build.php'),
            escapeshellarg('9.9.9-buildtest'),
            escapeshellarg('vBuildTest'),
            escapeshellarg($relativeDist),
        );

        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            self::fail("bin/build.php failed:\n".implode("\n", $output));
        }

        self::$singleFilePath = self::$distDir.'/GumPress.php';
    }

    public static function tearDownAfterClass(): void
    {
        self::removeDirectory(self::$distDir);
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

    public function test_two_plugins_bundling_the_identical_single_file_build_do_not_collide(): void
    {
        $pluginA = sys_get_temp_dir().'/gumpress-buildtest-a-'.uniqid();
        $pluginB = sys_get_temp_dir().'/gumpress-buildtest-b-'.uniqid();
        mkdir($pluginA, 0777, true);
        mkdir($pluginB, 0777, true);
        copy(self::$singleFilePath, $pluginA.'/GumPress.php');
        copy(self::$singleFilePath, $pluginB.'/GumPress.php');

        $runnerPath = sys_get_temp_dir().'/gumpress-buildtest-runner-'.uniqid().'.php';
        file_put_contents($runnerPath, $this->runnerScript($pluginA, $pluginB));

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($runnerPath).' 2>&1', $output, $exitCode);

        unlink($runnerPath);
        self::removeDirectory($pluginA);
        self::removeDirectory($pluginB);

        $this->assertSame(0, $exitCode, "Loading two identical single-file builds fatal'd:\n".implode("\n", $output));
        $this->assertSame('2', trim(implode('', $output)), 'both copies should register their own source directory');
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

        require '{$pluginA}/GumPress.php';
        require '{$pluginB}/GumPress.php';

        echo count(\$GLOBALS['gumpress']['sources']);
        PHP;
    }
}
