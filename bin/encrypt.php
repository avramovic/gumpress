<?php

declare(strict_types=1);

/**
 * Seals a config array for GumPress::register(), producing a "gp1" blob:
 *
 *   "gp1" + base64url(AES-256-CBC(gzdeflate(json_encode($config)))) + 16 hex chars of HMAC-SHA256
 *
 * Run it with `composer encrypt`, or directly:
 *
 *   php bin/encrypt.php <product-id> '<json-config>'
 *
 * The first argument is the Gumroad **product_id** (the opaque dashboard
 * string), not the permalink — it must match whatever is passed as
 * register()'s second argument, because the key derives from it. This
 * script's help text used to say "permalink", left over from before 2.0
 * split the two apart.
 *
 * This MUST stay byte-for-byte identical to gumpress/src/Config.php's
 * decode_encrypted() and the licensing server's App\Services\Shim\ConfigSealer.
 * tests/ConfigTest.php requires this file and round-trips gumpress_seal()
 * through Config::decode_encrypted() to hold that line.
 *
 * This is tamper-evidence, not real security: the key derives only from the
 * product_id, which ships in plaintext inside the plugin itself, so anyone
 * willing to read Config.php and write a script can still forge a blob. It
 * replaces the original CRC32 scheme, whose checksum was `dechex(crc32(...))`
 * — 1 to 8 hex characters depending on the value, while the decoder always
 * read exactly the last 8, silently corrupting ~6.25% of generated configs.
 * HMAC-SHA256 truncated to a fixed 16 hex characters can't recur that bug by
 * construction.
 */

function gumpress_seal_key(string $product): string
{
    return hash('sha256', 'gumpress|' . $product, true);
}

function gumpress_seal_iv(string $product): string
{
    return substr(hash('sha256', 'iv|' . $product, true), 0, 16);
}

function gumpress_base64url_encode(string $binary): string
{
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

/**
 * Pure codec — no output, no exit. The old version called exit(4) from in
 * here, which is what made the file impossible to require and left the test
 * suite maintaining its own copy of this logic.
 *
 * @param array<array-key, mixed> $config
 * @return string|null null when the config can't be JSON-encoded or openssl
 *                     refuses to encrypt.
 */
function gumpress_seal(array $config, string $product): ?string
{
    $json = json_encode($config);
    if ($json === false) {
        return null;
    }

    $deflated = gzdeflate($json, 9);
    if ($deflated === false) {
        return null;
    }

    $key = gumpress_seal_key($product);
    $iv = gumpress_seal_iv($product);

    $ciphertext = openssl_encrypt($deflated, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        return null;
    }

    $payload = gumpress_base64url_encode($ciphertext);
    $mac = substr(hash_hmac('sha256', $payload, $key), 0, 16);

    return 'gp1' . $payload . $mac;
}

// Only act when run directly — tests/bootstrap.php requires this file for
// gumpress_seal() and must not trigger the CLI. Under PHPUnit that require
// happens from inside a method, so the global $argv isn't even in scope
// here; when it is (running this file directly some other way), it points
// at the phpunit binary rather than this file. Either way the guard holds.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $usage = "Usage: php bin/encrypt.php <product-id> '<json-config>'\n";

    $product = $argv[1] ?? '';
    $json = $argv[2] ?? '';

    if ($product === '') {
        fwrite(STDERR, "ERROR: <product-id> is required — the opaque id from your Gumroad\n");
        fwrite(STDERR, "       dashboard, the same one you pass to GumPress::register().\n");
        fwrite(STDERR, $usage);
        exit(1);
    }

    if ($json === '') {
        fwrite(STDERR, "ERROR: <json-config> is required — the options array you would otherwise\n");
        fwrite(STDERR, "       pass to GumPress::register(), JSON-encoded.\n");
        fwrite(STDERR, $usage);
        exit(1);
    }

    // composer.json only *suggests* ext-openssl, because the library itself
    // degrades to a plaintext cache when it's missing. This tool can't
    // degrade to anything, so it says so in one line instead of hitting an
    // undefined-function fatal several frames deep inside gumpress_seal().
    if (!extension_loaded('openssl')) {
        fwrite(STDERR, "ERROR: sealing a config needs ext-openssl, which is not loaded.\n");
        exit(1);
    }

    $config = json_decode($json, true);

    if ($config === null && json_last_error() !== JSON_ERROR_NONE) {
        fwrite(STDERR, "ERROR: could not parse <json-config>: " . json_last_error_msg() . ".\n");
        fwrite(STDERR, "       Remember to quote it, so the shell passes it through as one word.\n");
        fwrite(STDERR, $usage);
        exit(1);
    }

    if (!is_array($config)) {
        fwrite(STDERR, "ERROR: <json-config> must be a JSON object, not a bare " . gettype($config) . ".\n");
        fwrite(STDERR, $usage);
        exit(1);
    }

    $blob = gumpress_seal($config, $product);

    if ($blob === null) {
        fwrite(STDERR, "ERROR: could not seal the config.\n");
        exit(1);
    }

    // The blob alone on STDOUT, so `composer encrypt ... > config.txt` and
    // shell substitution both work. All errors above go to STDERR — the old
    // version print()'d arg-validation errors to STDOUT, corrupting exactly
    // that use.
    fwrite(STDOUT, "{$blob}\n");
}
