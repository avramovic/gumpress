<?php

/**
 * Seals a config array for GumPress::register(), producing a "gp1" blob:
 *
 *   "gp1" + base64url(AES-256-CBC(gzdeflate(json_encode($config)))) + 16 hex chars of HMAC-SHA256
 *
 * This MUST stay byte-for-byte identical to gumpress/src/Config.php's
 * decode_encrypted() and the licensing server's App\Services\Shim\ConfigSealer.
 *
 * This is tamper-evidence, not real security: the key derives only from the
 * product permalink, which ships in plaintext inside the plugin itself, so
 * anyone willing to read Config.php and write a script can still forge a
 * blob. It replaces the original CRC32 scheme, whose checksum was
 * `dechex(crc32(...))` — 1 to 8 hex characters depending on the value, while
 * the decoder always read exactly the last 8, silently corrupting ~6.25% of
 * generated configs. HMAC-SHA256 truncated to a fixed 16 hex characters
 * can't recur that bug by construction.
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

function gumpress_seal(array $config, string $product): string
{
    $json = json_encode($config);
    $deflated = gzdeflate($json, 9);

    $key = gumpress_seal_key($product);
    $iv = gumpress_seal_iv($product);

    $ciphertext = openssl_encrypt($deflated, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        fwrite(STDERR, 'ERROR: encryption failed.' . PHP_EOL);
        exit(4);
    }

    $payload = gumpress_base64url_encode($ciphertext);
    $mac = substr(hash_hmac('sha256', $payload, $key), 0, 16);

    return 'gp1' . $payload . $mac;
}

if (php_sapi_name() != 'cli') {
    die('Script must be run using PHP CLI!' . PHP_EOL);
}

$permalink = $argv[1] ?? null;
$json = $argv[2] ?? null;

if (empty($permalink)) {
    print('ERROR: Argument 1 (your Gumroad product permalink) is required!' . PHP_EOL);
    exit(1);
}

if (empty($json)) {
    print('ERROR: Argument 2 (a JSON-encoded config object) is required!' . PHP_EOL);
    exit(1);
}

$config = json_decode($json, true);
if ($config === null && json_last_error() !== JSON_ERROR_NONE) {
    print("ERROR: Couldn't parse config. Argument 2 is expected to be a JSON encoded array!" . PHP_EOL);
    exit(2);
}

if (!is_array($config)) {
    print("ERROR: Couldn't parse config. Argument 2 is expected to be a JSON encoded ARRAY!" . PHP_EOL);
    exit(3);
}

echo gumpress_seal($config, $permalink) . PHP_EOL;
exit(0);
