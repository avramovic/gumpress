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
        $sealed = false;

        if (isset($options['__gumpress_encrypted'])) {
            $blob = $options['__gumpress_encrypted'];
            $decoded = Config::decode_encrypted($blob, $product);
            if ($decoded === null) {
                Notices::queue(sprintf(
                    'GumPress: encrypted configuration for %s failed its tamper check and was ignored.',
                    Module::label($file, $product)
                ));
                $decoded = [];
            } else {
                $sealed = true;
            }
            // A tamper-check failure falls through to $decoded = [] above,
            // i.e. an all-defaults config — is_default() below already
            // keeps that quiet, so this path only ever shows the one
            // "failed its tamper check" notice, not that plus an unrelated
            // unsealed-config warning.
            $options = $decoded;
        }

        // Always assigned, unconditionally, from a local — never from whatever
        // a caller's plain array happened to contain. Without this, a plain
        // array carrying its own '_encrypted' => true would sail through
        // Config::__construct()'s array_merge() and short-circuit both the
        // notice below and enforce_seal_policy()'s production fallback.
        $options['_encrypted'] = $sealed;

        $config = self::enforce_seal_policy(new Config($options), $product, $file);

        return Module::create($file, $product, $config);
    }

    /**
     * A plain-array config ships every setting — including which server
     * license_check_url points at — in the clear inside the compiled
     * plugin/theme, editable with a text editor. That's an acceptable
     * trade-off for local development, but easy to forget to seal before
     * shipping, and just as easy for a pirate to swap out entirely, since a
     * plain array never touches decode_encrypted()'s tamper check at all.
     *
     * Outside production, this only warns — deliberately not gated behind
     * suppress_notices, since that option exists so a developer can keep a
     * customer's dashboard clean, and letting it silence a build warning
     * aimed at the developer would hide the one thing they need to see.
     *
     * In production, a non-default plain-array config is discarded instead
     * of applied: everything but the 'type'/'text_domain'/'permalink'
     * identity keys reverts to Config::DEFAULTS, and a line goes to
     * error_log() (never an admin notice — a real customer's dashboard
     * should never carry a build-time warning). Those three keys are the
     * exception because a pirate gains nothing from them (no server
     * redirection, no relaxed seat limit, no silenced notices) while
     * dropping them can fail a legitimate customer closed — e.g. a theme
     * with an unusual layout that sets type => 'theme' explicitly (see
     * README) would otherwise mis-detect as a plugin and resolve bare
     * GumPress::valid() calls from its own template files to NullModule.
     * This is the asymmetric half of the same policy that keeps the
     * encrypted-blob tamper-check notice above loud in every environment: a
     * broken seal means someone tried to seal it and the seal didn't
     * survive (a build bug worth surfacing loudly); a plain array means
     * nobody sealed anything (a developer mistake that must not leak to
     * the customer it now silently protects).
     *
     * Also silent (in both environments) while the config is still all
     * defaults: register($file, $product) with no options (or options that
     * just restate a default, like the quick-start's payment_grace => 7)
     * ships nothing worth hiding, so there's nothing for sealing to
     * protect and nothing for production to discard.
     *
     * $file is optional (and omitted by the direct-call tests below) so the
     * notice degrades to identifying the module by its bare product id
     * rather than failing to warn at all when a caller has no file path.
     */
    public static function enforce_seal_policy(Config $config, string $product, ?string $file = null): Config
    {
        if ($config->get('_encrypted') || $config->is_default()) {
            return $config;
        }

        if (!Env::is_non_production()) {
            error_log(sprintf(
                'GumPress: unsealed (plain-array) configuration for "%s" was ignored in production.',
                $product
            ));

            return new Config(array_intersect_key(
                $config->all(),
                array_flip(['type', 'text_domain', 'permalink'])
            ));
        }

        $configurator_url = (string) ($config->get('configurator_url') ?? Config::DEFAULT_CONFIGURATOR_URL);
        $link = self::configurator_link($configurator_url, $product, $config);
        $configured_type = $config->get('type');
        $label = $file === null
            ? sprintf('"%s"', $product)
            : Module::label($file, $product, is_string($configured_type) ? $configured_type : null);

        Notices::queue_html(sprintf(
            'GumPress: %s is running with an unsealed (plain-array) configuration. Production '
            . 'ignores it entirely and falls back to defaults — including license_check_url. '
            . '<a href="%s" target="_blank">Seal it before shipping</a>.',
            esc_html($label),
            esc_url($link)
        ), 'warning');

        return $config;
    }

    /**
     * The unsealed-config notice's link, carrying every non-default option
     * over as a query parameter (see Config::non_defaults()) so the
     * configurator loads pre-filled instead of blank. Booleans become "1"/"0"
     * — the only shape ConfigConfigurator's Livewire #[Url] bindings expect
     * for a bool-typed property; arrays (there are none left in
     * non_defaults() once 'lock_config' is excluded, but stay defensive)
     * are skipped rather than mangled into query-string bracket notation
     * the configurator doesn't parse.
     */
    private static function configurator_link(string $configurator_url, string $product, Config $config): string
    {
        $params = ['product_id' => $product];

        foreach ($config->non_defaults() as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $params[$key] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }

        $separator = str_contains($configurator_url, '?') ? '&' : '?';

        return $configurator_url . $separator . http_build_query($params);
    }
}
