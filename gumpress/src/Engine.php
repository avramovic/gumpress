<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * The one entry point the frozen gumpress.php bootstrap calls. Everything
 * else in this namespace is free to change shape release to release, because
 * each copy of GumPress always loads and runs its own Engine — there is no
 * shared "one engine wins" arbitration to keep compatible across versions.
 */
final class Engine
{
    public static function create(string $file, string $product, array $options): Module
    {
        if (isset($options['__gumpress_encrypted'])) {
            $blob = $options['__gumpress_encrypted'];
            $decoded = Config::decode_encrypted($blob, $product);
            if ($decoded === null) {
                Notices::queue(sprintf(
                    'GumPress: encrypted configuration for "%s" failed its tamper check and was ignored.',
                    $product
                ));
                $decoded = [];
            } else {
                $decoded['_encrypted'] = true;
            }
            $options = $decoded;
        }

        $config = new Config($options);
        self::maybe_warn_unsealed_config($config, $product);

        return Module::create($file, $product, $config);
    }

    /**
     * A plain-array config ships every setting — including which server
     * license_check_url points at — in the clear inside the compiled
     * plugin/theme, editable with a text editor. That's an acceptable
     * trade-off for local development, but easy to forget to seal before
     * shipping. Non-production-only by design: a real customer's site never
     * triggers this. Deliberately not gated behind suppress_notices — that
     * option exists so a developer can keep a customer's dashboard clean,
     * and letting it silence a build warning aimed at the developer would
     * hide the one thing they need to see.
     */
    public static function maybe_warn_unsealed_config(Config $config, string $product): void
    {
        if ($config->get('_encrypted') || !Env::is_non_production()) {
            return;
        }

        $configurator_url = (string) ($config->get('configurator_url') ?? Config::DEFAULT_CONFIGURATOR_URL);

        Notices::queue(sprintf(
            'GumPress: "%s" is running with an unsealed (plain-array) configuration outside of '
            . 'production. Anyone with file or hosting access can silently edit its settings — including '
            . 'which server it reports to — with a text editor. Seal it before shipping: %s',
            $product,
            $configurator_url
        ), 'warning');
    }
}
