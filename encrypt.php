<?php

function cr($s)
{
    return sprintf('%u', crc32($s));
}

function dh($s)
{
    return dechex($s);
}

function r($s)
{
    return str_rot13($s);
}

function t($s)
{
    return trim($s, '=');
}

function b($s)
{
    return base64_encode($s);
}

function j($s)
{
    return json_encode($s);
}

function g($s)
{
    return gzdeflate($s, 9);
}

if (php_sapi_name() == 'cli') {
    $short_id = $argv[1] ?? null;
    $json     = $argv[2] ?? null;

    if (empty($short_id)) {
        print("ERROR: Argument 1 is required!".PHP_EOL);
        exit(1);
    }

    if (empty($json)) {
        print("ERROR: Argument 2 is required!".PHP_EOL);
        exit(1);
    }

    if (!$config = json_decode($json, true)) {
        print("ERROR: Couldn't parse config. Argument 2 is expected to be a JSON encoded array!".PHP_EOL);
        exit(2);
    }

    if (!is_array($config)) {
        print("ERROR: Couldn't parse config. Argument 2 is expected to be a json encoded ARRAY!".PHP_EOL);
        exit(3);
    }

    $encoded  = t(r(b(g(r(j($config))))));
    $checksum = dh(cr($encoded.$short_id));
    echo $encoded.$checksum.PHP_EOL;
    exit(0);
} else die("No dogs allowed!");
