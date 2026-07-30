<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * Encrypts the arrays GumPress caches in wp_options — today the verify
 * state (which holds the buyer's email and checkout custom fields) and the
 * update-check response (which holds the package URL).
 *
 * The one threat this defends is database exposure WITHOUT filesystem
 * access: a SQL injection, or a leaked DB dump. That rules out deriving the
 * key from anything the database already contains. In particular it rules
 * out the two obvious candidates: the product_id (which is embedded in the
 * option's own name — see Module::option_name()) and the license key (which
 * sits in a neighbouring option, and must stay readable so a site can
 * re-verify itself unattended). So the key comes from wp_salt(), which
 * lives in wp-config.php and never in the database. Anyone holding both the
 * filesystem and the database can still read everything — that is inherent
 * to at-rest encryption in WordPress, not a shortcoming of this class.
 *
 * Deliberately NOT reusing Config's "gp1" seal. That one derives a fixed IV
 * per product, which is fine for a config blob written once, but would leak
 * equality between successive writes of a row that changes over time. Here
 * the IV is random per write and stored alongside the ciphertext.
 *
 * Format: "gpo1" + base64url(iv[16] . AES-256-CBC(json)) + 16 hex chars of
 * HMAC-SHA256 over that payload, verified before decrypting.
 */
final class Vault
{
    private const PREFIX = 'gpo1';

    private const MAC_LENGTH = 16;

    private const IV_LENGTH = 16;

    /**
     * False on a PHP build without ext-openssl (composer.json only requires
     * php >= 8.0), or before WordPress can give us a salt. Everything below
     * falls back to storing plaintext rather than failing: a missing
     * extension must never brick a customer's site or lock them out of a
     * plugin they paid for.
     */
    public static function available(): bool
    {
        return function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && function_exists('wp_salt')
            && self::salt() !== '';
    }

    /**
     * @return string|array the sealed string, or $value untouched when
     *                      encryption isn't available.
     */
    public static function seal(array $value)
    {
        if (!self::available()) {
            return $value;
        }

        $json = json_encode($value);
        if ($json === false) {
            return $value;
        }

        $iv = self::random_iv();
        if ($iv === null) {
            return $value;
        }

        $ciphertext = openssl_encrypt($json, 'aes-256-cbc', self::cipher_key(), OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return $value;
        }

        $payload = self::base64url_encode($iv . $ciphertext);

        return self::PREFIX . $payload . self::mac($payload);
    }

    /**
     * @param mixed $stored the raw option value.
     * @return array|null null means "unreadable" — a tampered or
     *                    undecryptable blob, which every caller must treat
     *                    as an empty cache rather than an error.
     */
    public static function open($stored): ?array
    {
        // Written before this class existed, or by an install where
        // available() was false. Left as-is; the next write seals it.
        if (is_array($stored)) {
            return $stored;
        }

        if (!is_string($stored) || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }

        $body = substr($stored, strlen(self::PREFIX));
        if (strlen($body) <= self::MAC_LENGTH || !self::available()) {
            return null;
        }

        $mac = substr($body, -self::MAC_LENGTH);
        $payload = substr($body, 0, -self::MAC_LENGTH);

        if (!hash_equals(self::mac($payload), $mac)) {
            return null;
        }

        $raw = self::base64url_decode($payload);
        if ($raw === null || strlen($raw) <= self::IV_LENGTH) {
            return null;
        }

        $json = openssl_decrypt(
            substr($raw, self::IV_LENGTH),
            'aes-256-cbc',
            self::cipher_key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_LENGTH)
        );
        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Null when no CSPRNG is available — callers then store plaintext. */
    private static function random_iv(): ?string
    {
        try {
            return random_bytes(self::IV_LENGTH);
        } catch (\Exception $e) {
            return null;
        }
    }

    private static function mac(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, self::mac_key()), 0, self::MAC_LENGTH);
    }

    /** Separate keys for confidentiality and authenticity, never one for both. */
    private static function cipher_key(): string
    {
        return hash('sha256', 'gumpress|vault|' . self::salt(), true);
    }

    private static function mac_key(): string
    {
        return hash('sha256', 'gumpress|vault-mac|' . self::salt(), true);
    }

    private static function salt(): string
    {
        return function_exists('wp_salt') ? (string) wp_salt('auth') : '';
    }

    private static function base64url_encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function base64url_decode(string $payload): ?string
    {
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);

        return $decoded !== false ? $decoded : null;
    }
}
