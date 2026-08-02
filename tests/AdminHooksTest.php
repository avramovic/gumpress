<?php

declare(strict_types=1);

namespace GumPress\V2\Tests;

use GumPress\V2\Admin;
use GumPress\V2\Config;
use GumPress\V2\Module;
use GumPress\V2\Vault;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the WordPress-hook contract that replaced the old
 * callbacks.license_page_top/license_page_bottom config keys (see
 * Admin::render_page()). Tests the CONTRACT, not the markup — hook names,
 * firing order, argument shape, and the buy-button filter's replace/
 * never-fatal guarantees — because a full-page HTML snapshot would be
 * brittle and wouldn't actually exercise anything this refactor promises.
 * Admin.php was previously excluded from the test bootstrap entirely
 * ("needs the full admin/hook API and is still uncovered"); it's now a
 * public extension surface and earns coverage same as anything else.
 */
final class AdminHooksTest extends TestCase
{
    private const PRODUCT = 'test-product-id';

    private const KEY = 'AAAA-BBBB-CCCC-DDDD';

    protected function setUp(): void
    {
        gumpress_test_reset_store();
    }

    private function module(?string $permalink = null): Module
    {
        return Module::create('/tmp/fake-plugin/fake-plugin.php', self::PRODUCT, new Config(array_filter([
            'type' => 'plugin',
            'permalink' => $permalink,
        ], static fn ($v) => $v !== null)));
    }

    /** A module whose status() resolves to VALID, so the default Buy button never renders for it. */
    private function validModule(): Module
    {
        $module = $this->module('acme');
        $module->set_license_key(self::KEY);

        $GLOBALS['__gumpress_test_options'][$module->option_name('state')] = Vault::seal([
            'status' => 'ok',
            'valid_at' => time(),
            'payload' => [
                'success' => true,
                'uses' => 1,
                'purchase' => [
                    'email' => 'buyer@example.com',
                    'test' => false,
                    'refunded' => false,
                    'disputed' => false,
                    'dispute_won' => false,
                    'chargebacked' => false,
                    'recurrence' => null,
                    'subscription_id' => null,
                    'variants' => '',
                    'custom_fields' => [],
                ],
                'license_key' => self::KEY,
            ],
        ]);

        return $module;
    }

    private function render(Module $module): string
    {
        $method = new ReflectionMethod(Admin::class, 'render_page');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, $module);

        return (string) ob_get_clean();
    }

    public function test_top_hooks_fire_once_each_with_the_product_id(): void
    {
        $seen = [];
        add_action('gumpress_license_page_top', static function ($product) use (&$seen) {
            $seen[] = ['global', $product];
        });
        add_action('gumpress_license_page_top_' . self::PRODUCT, static function ($product) use (&$seen) {
            $seen[] = ['suffixed', $product];
        });

        $this->render($this->module());

        $this->assertSame([
            ['global', self::PRODUCT],
            ['suffixed', self::PRODUCT],
        ], $seen);
    }

    public function test_bottom_hooks_fire_once_each_with_the_product_id(): void
    {
        $seen = [];
        add_action('gumpress_license_page_bottom', static function ($product) use (&$seen) {
            $seen[] = ['global', $product];
        });
        add_action('gumpress_license_page_bottom_' . self::PRODUCT, static function ($product) use (&$seen) {
            $seen[] = ['suffixed', $product];
        });

        $this->render($this->module());

        $this->assertSame([
            ['global', self::PRODUCT],
            ['suffixed', self::PRODUCT],
        ], $seen);
    }

    /** The global hook is documented to fire before the per-product one — assert the guarantee, not an accident. */
    public function test_global_hook_fires_before_the_suffixed_hook(): void
    {
        $order = [];
        add_action('gumpress_license_page_bottom', static function () use (&$order) {
            $order[] = 'global';
        });
        add_action('gumpress_license_page_bottom_' . self::PRODUCT, static function () use (&$order) {
            $order[] = 'suffixed';
        });

        $this->render($this->module());

        $this->assertSame(['global', 'suffixed'], $order);
    }

    public function test_top_output_precedes_the_license_form_and_bottom_output_follows_the_status_table(): void
    {
        add_action('gumpress_license_page_top', static function () {
            echo 'TOP-MARKER';
        });
        add_action('gumpress_license_page_bottom', static function () {
            echo 'BOTTOM-MARKER';
        });

        $html = $this->render($this->module());

        $topPos = strpos($html, 'TOP-MARKER');
        $formPos = strpos($html, '<form');
        $tablePos = strrpos($html, '</table>');
        $bottomPos = strpos($html, 'BOTTOM-MARKER');

        $this->assertNotFalse($topPos);
        $this->assertNotFalse($formPos);
        $this->assertNotFalse($tablePos);
        $this->assertNotFalse($bottomPos);
        $this->assertLessThan($formPos, $topPos, 'top hook output should render before the license form');
        $this->assertGreaterThan($tablePos, $bottomPos, 'bottom hook output should render after the status table');
    }

    public function test_default_buy_button_present_only_when_invalid_and_permalink_set(): void
    {
        $invalidWithPermalink = $this->render($this->module('acme'));
        $this->assertStringContainsString('gumroad.com/l/', $invalidWithPermalink);

        $invalidWithoutPermalink = $this->render($this->module(null));
        $this->assertStringNotContainsString('gumroad.com/l/', $invalidWithoutPermalink);

        $valid = $this->render($this->validModule());
        $this->assertStringNotContainsString('gumroad.com/l/', $valid);
    }

    public function test_buy_button_filter_replaces_default_and_receives_it_as_the_value(): void
    {
        $received = null;
        add_filter('gumpress_license_page_buy_button_' . self::PRODUCT, static function ($default) use (&$received) {
            $received = $default;

            return '<a class="my-cta">Upgrade</a>';
        });

        $html = $this->render($this->module('acme'));

        $this->assertStringContainsString('my-cta', $html);
        $this->assertStringNotContainsString('gumroad.com/l/', $html);
        $this->assertIsString($received);
        $this->assertStringContainsString('gumroad.com/l/', $received, 'filter should receive the default markup as $value');
    }

    /** Lets an integrator ADD a button for a product with no permalink configured, not just replace an existing one. */
    public function test_buy_button_filter_runs_even_when_the_default_is_empty(): void
    {
        $received = 'not yet called';
        add_filter('gumpress_license_page_buy_button_' . self::PRODUCT, static function ($default) use (&$received) {
            $received = $default;

            return '<a class="added-cta">Buy</a>';
        });

        $html = $this->render($this->module(null)); // no permalink -> default is ''

        $this->assertSame('', $received);
        $this->assertStringContainsString('added-cta', $html);
    }

    public function test_a_filter_returning_null_prints_nothing_and_does_not_fatal(): void
    {
        add_filter('gumpress_license_page_buy_button_' . self::PRODUCT, static fn () => null);

        $html = $this->render($this->module('acme'));

        $this->assertStringNotContainsString('gumroad.com/l/', $html);
    }

    public function test_a_filter_returning_an_array_prints_nothing_and_does_not_fatal(): void
    {
        add_filter('gumpress_license_page_buy_button_' . self::PRODUCT, static fn () => ['not', 'a', 'string']);

        $html = $this->render($this->module('acme'));

        $this->assertStringNotContainsString('gumroad.com/l/', $html);
        $this->assertStringNotContainsString('Array', $html);
    }

    public function test_status_rows_hooks_fire_between_the_status_table_and_the_buy_button(): void
    {
        add_action('gumpress_license_page_status_rows_' . self::PRODUCT, static function () {
            echo '<tr><td>EXTRA-ROW</td></tr>';
        });

        $html = $this->render($this->module('acme'));

        $rowPos = strpos($html, 'EXTRA-ROW');
        $tableClosePos = strrpos($html, '</table>');
        $buyPos = strpos($html, 'gumroad.com/l/');

        $this->assertNotFalse($rowPos);
        $this->assertNotFalse($tableClosePos);
        $this->assertNotFalse($buyPos);
        $this->assertLessThan($tableClosePos, $rowPos, 'status-rows hook output should render before </table>');
        $this->assertLessThan($buyPos, $rowPos);
    }
}
