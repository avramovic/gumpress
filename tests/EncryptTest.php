<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use GumPress\V2\Config;
use PHPUnit\Framework\TestCase;

/**
 * Shells out to the real bin/encrypt.php, the same way BuildTest.php shells
 * out to bin/build.php. ConfigTest covers gumpress_seal() itself (requiring
 * the file directly); this covers the parts only a real process exercises:
 * argv parsing, exit codes, and which stream each line lands on — all of
 * which changed in the move from the repo-root encrypt.php.
 */
final class EncryptTest extends TestCase
{
    private const PRODUCT = 'acme-plugin';

    /**
     * @param list<string> $args
     * @return array{exit: int, lines: list<string>}
     */
    private static function run_cli(array $args): array
    {
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/encrypt.php');
        foreach ($args as $arg) {
            $command .= ' '.escapeshellarg($arg);
        }

        exec($command.' 2>&1', $lines, $exit);

        return ['exit' => $exit, 'lines' => $lines];
    }

    public function test_the_sealed_blob_decodes_back_to_the_config(): void
    {
        $result = self::run_cli([self::PRODUCT, '{"max_uses":2,"payment_grace":3}']);

        $this->assertSame(0, $result['exit']);
        $this->assertSame(
            ['max_uses' => 2, 'payment_grace' => 3],
            Config::decode_encrypted($result['lines'][0], self::PRODUCT)
        );
    }

    public function test_it_prints_the_blob_and_nothing_else(): void
    {
        $result = self::run_cli([self::PRODUCT, '{"max_uses":2}']);

        $this->assertCount(1, $result['lines']);
        $this->assertStringStartsWith('gp1', $result['lines'][0]);
    }

    public function test_sealing_is_deterministic(): void
    {
        $args = [self::PRODUCT, '{"max_uses":2,"label":"a"}'];

        $this->assertSame(self::run_cli($args)['lines'], self::run_cli($args)['lines']);
    }

    public function test_a_blob_sealed_for_another_product_does_not_decode(): void
    {
        $blob = self::run_cli([self::PRODUCT, '{"max_uses":1}'])['lines'][0];

        $this->assertNull(Config::decode_encrypted($blob, 'a-different-product'));
    }

    /** @return array<string, array{list<string>}> */
    public static function missing_argument_cases(): array
    {
        return [
            'no arguments' => [[]],
            'only the product id' => [[self::PRODUCT]],
        ];
    }

    /**
     * @dataProvider missing_argument_cases
     * @param list<string> $args
     */
    public function test_missing_arguments_exit_non_zero_with_usage(array $args): void
    {
        $result = self::run_cli($args);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Usage: php bin/encrypt.php', implode("\n", $result['lines']));
    }

    public function test_unparseable_json_exits_non_zero(): void
    {
        $result = self::run_cli([self::PRODUCT, '{not json']);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('ERROR:', implode("\n", $result['lines']));
    }

    /** @return array<string, array{string}> */
    public static function non_object_json_cases(): array
    {
        return [
            'a bare number' => ['42'],
            'a bare string' => ['"a string"'],
            'null' => ['null'],
            'true' => ['true'],
        ];
    }

    /** @dataProvider non_object_json_cases */
    public function test_non_object_json_exits_non_zero(string $json): void
    {
        $result = self::run_cli([self::PRODUCT, $json]);

        $this->assertSame(1, $result['exit']);
    }

    public function test_composer_exposes_the_encrypt_script(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true);

        $this->assertSame('php bin/encrypt.php', $composer['scripts']['encrypt']);
        $this->assertFileExists(dirname(__DIR__).'/bin/encrypt.php');
    }
}
