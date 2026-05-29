<?php

use GuzzleHttp\Psr7\Request;
use Laravel\Ai\Gateway\Oracle\OracleRequestSigner;

function oracleSignerFixture(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

    openssl_pkey_export($key, $pem);

    $public = openssl_pkey_get_details($key)['key'];

    $signer = new OracleRequestSigner(
        'ocid1.tenancy.oc1..aaaa',
        'ocid1.user.oc1..bbbb',
        'aa:bb:cc:dd:ee',
        $pem,
    );

    return [$signer, $public];
}

test('key id concatenates tenancy, user, and fingerprint', function () {
    [$signer] = oracleSignerFixture();

    expect($signer->keyId())->toBe('ocid1.tenancy.oc1..aaaa/ocid1.user.oc1..bbbb/aa:bb:cc:dd:ee');
});

test('post request signing string includes the body headers in order', function () {
    [$signer] = oracleSignerFixture();

    $request = (new Request(
        'POST',
        'https://inference.generativeai.us-chicago-1.oci.oraclecloud.com/20231130/actions/chat',
        ['Content-Type' => 'application/json', 'date' => 'Thu, 29 May 2026 12:00:00 GMT'],
        '{"a":1}',
    ))->withHeader('host', 'inference.generativeai.us-chicago-1.oci.oraclecloud.com')
        ->withHeader('x-content-sha256', base64_encode(hash('sha256', '{"a":1}', true)))
        ->withHeader('content-length', '7');

    $signingString = $signer->buildSigningString($request, [
        '(request-target)', 'host', 'date', 'x-content-sha256', 'content-type', 'content-length',
    ]);

    expect($signingString)->toBe(implode("\n", [
        '(request-target): post /20231130/actions/chat',
        'host: inference.generativeai.us-chicago-1.oci.oraclecloud.com',
        'date: Thu, 29 May 2026 12:00:00 GMT',
        'x-content-sha256: '.base64_encode(hash('sha256', '{"a":1}', true)),
        'content-type: application/json',
        'content-length: 7',
    ]));
});

test('request target defaults an empty path to a slash', function () {
    [$signer] = oracleSignerFixture();

    $signingString = $signer->buildSigningString(new Request('GET', 'https://host.example.com'), ['(request-target)']);

    expect($signingString)->toBe('(request-target): get /');
});

test('request target includes the query string', function () {
    [$signer] = oracleSignerFixture();

    $request = new Request('GET', 'https://host.example.com/path?foo=bar&baz=qux');

    $signingString = $signer->buildSigningString($request, ['(request-target)']);

    expect($signingString)->toBe('(request-target): get /path?foo=bar&baz=qux');
});

test('signing a post request adds the signature headers', function () {
    [$signer] = oracleSignerFixture();

    $request = new Request(
        'POST',
        'https://inference.generativeai.us-chicago-1.oci.oraclecloud.com/20231130/actions/chat',
        ['Content-Type' => 'application/json'],
        '{"hello":"world"}',
    );

    $signed = $signer->sign($request, 'Thu, 29 May 2026 12:00:00 GMT');

    expect($signed->getHeaderLine('date'))->toBe('Thu, 29 May 2026 12:00:00 GMT')
        ->and($signed->getHeaderLine('x-content-sha256'))->toBe(base64_encode(hash('sha256', '{"hello":"world"}', true)))
        ->and($signed->getHeaderLine('content-length'))->toBe((string) strlen('{"hello":"world"}'))
        ->and($signed->getHeaderLine('Authorization'))->toContain('Signature version="1"')
        ->and($signed->getHeaderLine('Authorization'))->toContain('keyId="ocid1.tenancy.oc1..aaaa/ocid1.user.oc1..bbbb/aa:bb:cc:dd:ee"')
        ->and($signed->getHeaderLine('Authorization'))->toContain('algorithm="rsa-sha256"')
        ->and($signed->getHeaderLine('Authorization'))->toContain('headers="(request-target) host date x-content-sha256 content-type content-length"');
});

test('the produced signature verifies against the public key', function () {
    [$signer, $public] = oracleSignerFixture();

    $request = new Request(
        'POST',
        'https://inference.generativeai.us-chicago-1.oci.oraclecloud.com/20231130/actions/chat',
        ['Content-Type' => 'application/json'],
        '{"hello":"world"}',
    );

    $signed = $signer->sign($request, 'Thu, 29 May 2026 12:00:00 GMT');

    preg_match('/signature="([^"]+)"/', $signed->getHeaderLine('Authorization'), $matches);

    $signingString = $signer->buildSigningString($signed, [
        '(request-target)', 'host', 'date', 'x-content-sha256', 'content-type', 'content-length',
    ]);

    expect(openssl_verify($signingString, base64_decode($matches[1]), $public, OPENSSL_ALGO_SHA256))->toBe(1);
});

test('bodyless requests only sign the request-target, host, and date', function () {
    [$signer] = oracleSignerFixture();

    $signed = $signer->sign(new Request('GET', 'https://host.example.com/20231130/models'), 'Thu, 29 May 2026 12:00:00 GMT');

    expect($signed->getHeaderLine('Authorization'))->toContain('headers="(request-target) host date"')
        ->and($signed->hasHeader('x-content-sha256'))->toBeFalse();
});
