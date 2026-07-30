<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use GumPress\V2\Module;
use PHPUnit\Framework\TestCase;

/**
 * Module::label()/read_headers() back the bootstrap notices (see Engine and
 * gumpress.php) that need to identify a plugin/theme before — or without —
 * a Module ever being constructed, from nothing but a file path and an
 * opaque Gumroad product_id.
 */
final class ModuleLabelTest extends TestCase
{
    public function test_label_uses_the_plugin_name_when_the_header_is_readable(): void
    {
        $label = Module::label(__DIR__ . '/fixtures/acme-plugin/acme-plugin.php', 'acme-plugin');

        $this->assertSame('"Acme Pro"', $label);
    }

    public function test_label_falls_back_to_the_bare_id_when_the_name_header_is_empty(): void
    {
        $label = Module::label(__DIR__ . '/fixtures/no-name-plugin/no-name-plugin.php', 'acme-plugin');

        $this->assertSame('"acme-plugin"', $label);
    }

    public function test_label_falls_back_to_the_bare_id_for_a_nonexistent_file(): void
    {
        $label = Module::label('/nonexistent/path/does-not-exist.php', 'acme-plugin');

        $this->assertSame('"acme-plugin"', $label);
    }

    public function test_label_honours_an_explicit_type_hint(): void
    {
        $label = Module::label(__DIR__ . '/fixtures/acme-plugin/acme-plugin.php', 'acme-plugin', 'plugin');

        $this->assertSame('"Acme Pro"', $label);
    }

    public function test_read_headers_memoizes_per_file_and_type(): void
    {
        $file = __DIR__ . '/fixtures/acme-plugin/acme-plugin.php';

        $first = Module::read_headers($file, 'plugin');
        $second = Module::read_headers($file, 'plugin');

        $this->assertSame($first, $second);
        $this->assertSame('Acme Pro', $first['Name']);
    }
}
