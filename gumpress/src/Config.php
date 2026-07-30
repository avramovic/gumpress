<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * All configuration defaults live here, once, unlike v1 where `cache_time`
 * was accepted and silently ignored while the real TTLs were hardcoded three
 * different places in the file.
 */
final class Config
{
    public const DEFAULT_LICENSE_URL = 'https://api.gumroad.com/v2/licenses/verify';

    public const DEFAULT_CONFIGURATOR_URL = 'https://gumpress.eu/configurator';

    private const SEAL_PREFIX = 'gp1';

    private const SEAL_MAC_LENGTH = 16;

    private const DEFAULTS = [
        // Identity / detection
        'type' => null, // 'plugin' | 'theme'; auto-detected when null.
        'text_domain' => null, // defaults to the host module's own slug.

        // The human-readable Gumroad permalink (the part of the product's
        // public gum.co/... or gumroad.com/l/... URL), entirely separate
        // from the product_id passed as register()'s 2nd argument. Purely
        // cosmetic: used for the license page's "Buy" link and its own URL
        // slug (see Module::page_slug()). When unset, the Buy link is
        // hidden (a product_id is not a valid gumroad.com/l/... path) and
        // the page slug falls back to a short hash of the product_id.
        'permalink' => null,

        // License validity
        'disallow_test_keys' => false,
        'payment_grace' => 7, // days a failed subscription payment stays valid.
        'max_uses' => 0, // 0 disables seat limiting entirely (see README: Gumroad's
                         // `uses` counts verifications, not seats, and can't be decremented).
        'max_uses_policy' => 'block', // 'block' (invalidate) | 'warn' (show a notice).

        // Network / caching / offline behaviour
        'license_check_url' => self::DEFAULT_LICENSE_URL,
        'proxy_fallback' => false, // fall back to Gumroad direct if a custom proxy is unreachable.
        'offline_grace' => 14, // days a previously-valid license stays valid while unreachable.
        'offline_policy' => 'grace', // 'grace' | 'closed' | 'open'.

        // Updates
        'update_check_url' => null,

        // Server-controlled override channel (see Overrides::apply()). Keys
        // listed here are never handed to the server, no matter what a verify
        // response's `gumpress.config` object contains.
        'lock_config' => [],

        // Points the non-production unsealed-config admin notice (see
        // Engine::maybe_warn_unsealed_config()) somewhere other than the
        // default configurator, for anyone self-hosting one.
        'configurator_url' => null,

        // Admin UI
        'plugins_page_link' => true,
        'hide_menu_page' => false,
        'suppress_notices' => false,
        'suppress_key_notice' => false,
        'hide_owner_email' => false,
        'hide_custom_fields' => false,
        'license_page_title' => null,
        'license_page_menu' => null,

        // Callbacks: license_page_top, license_page_bottom.
        'callbacks' => [],

        '_encrypted' => false,
    ];

    private array $data;

    public function __construct(array $options = [])
    {
        $this->data = array_merge(self::DEFAULTS, $options);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * @param mixed $default
     * @return callable|mixed
     */
    public function callback(string $key, $default = null)
    {
        $callback = $this->data['callbacks'][$key] ?? null;

        return is_callable($callback) ? $callback : $default;
    }

    /**
     * True when the footer credit is waived: the integrator points
     * license_check_url at a GumPress-run licensing server rather than
     * Gumroad direct. Deliberately derived rather than configurable —
     * there is no option to turn the credit off.
     */
    public function is_white_label(): bool
    {
        $url = (string) $this->get('license_check_url', self::DEFAULT_LICENSE_URL);
        if ($url === '' || $url === self::DEFAULT_LICENSE_URL) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        return in_array('gumpress', explode('.', strtolower($host)), true);
    }

    public function all(): array
    {
        return $this->data;
    }

    /**
     * Every option that differs from DEFAULTS — what
     * Engine::maybe_warn_unsealed_config() links into the configurator with,
     * so a developer who already has a working plain-array config gets it
     * pre-filled there instead of retyping everything. Excludes keys that
     * either can't survive a query string ('callbacks' is a PHP closure map)
     * or wouldn't help reproduce anything ('lock_config' is a list of keys
     * to protect, not a value to carry over; 'configurator_url' pointing
     * back at itself would be circular; '_encrypted'/'type'/'text_domain'
     * aren't developer-facing options at all).
     */
    public function non_defaults(): array
    {
        $excluded = ['callbacks', 'lock_config', 'configurator_url', '_encrypted', 'type', 'text_domain'];
        $diff = [];

        foreach ($this->data as $key => $value) {
            if (in_array($key, $excluded, true)) {
                continue;
            }

            if ($value === (self::DEFAULTS[$key] ?? null)) {
                continue;
            }

            $diff[$key] = $value;
        }

        return $diff;
    }

    /**
     * True when nothing was passed to register() beyond GumPress's own
     * defaults — the only case where an unsealed config has nothing to hide.
     */
    public function is_default(): bool
    {
        return $this->data == self::DEFAULTS;
    }

    /**
     * Decodes the "gp1" sealed-string form of $options that GumPress::register()
     * accepts, produced by the repo's bin/encrypt.php CLI tool or the licensing
     * server's web configurator (App\Services\Shim\ConfigSealer — the two
     * MUST stay byte-for-byte identical). This is obfuscation / tamper
     * evidence, not real security: the key derives only from the product_id
     * passed to register(), which ships in plaintext inside the plugin
     * itself, so anyone willing to read this file and write a script can
     * still forge a blob. What it buys over the old CRC32 scheme is that
     * the payload is no longer readable at rest (was base64+rot13) and the MAC is fixed-width
     * by construction, so it can't recur the old truncation bug (CRC32
     * rendered as 1-8 hex chars while the decoder always read the last 8,
     * silently corrupting ~6.25% of configs).
     *
     * Format: "gp1" + base64url(AES-256-CBC(gzdeflate(json_encode($config)))) + 16 hex chars of HMAC-SHA256.
     *
     * @return array|null null when the blob fails its tamper check or doesn't decode.
     */
    public static function decode_encrypted(string $blob, string $product): ?array
    {
        if (!str_starts_with($blob, self::SEAL_PREFIX)) {
            return null;
        }

        $body = substr($blob, strlen(self::SEAL_PREFIX));
        if (strlen($body) <= self::SEAL_MAC_LENGTH) {
            return null;
        }

        $mac = substr($body, -self::SEAL_MAC_LENGTH);
        $payload = substr($body, 0, -self::SEAL_MAC_LENGTH);

        $key = self::seal_key($product);
        $expected_mac = substr(hash_hmac('sha256', $payload, $key), 0, self::SEAL_MAC_LENGTH);

        if (!hash_equals($expected_mac, $mac)) {
            return null;
        }

        $ciphertext = self::base64url_decode($payload);
        if ($ciphertext === null) {
            return null;
        }

        $deflated = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, self::seal_iv($product));
        if ($deflated === false) {
            return null;
        }

        $json = @gzinflate($deflated);
        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function seal_key(string $product): string
    {
        return hash('sha256', 'gumpress|' . $product, true);
    }

    private static function seal_iv(string $product): string
    {
        return substr(hash('sha256', 'iv|' . $product, true), 0, 16);
    }

    private static function base64url_decode(string $payload): ?string
    {
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);

        return $decoded !== false ? $decoded : null;
    }
}
