<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\Oracle\OracleException;

function oracleRequestException(int $status, string $body = ''): RequestException
{
    return new RequestException(new Response(new Psr7Response($status, [], $body)));
}

test('rate limited and overloaded statuses map to their exceptions', function () {
    expect(OracleException::toAiException(oracleRequestException(429), 'oracle', 'm'))
        ->toBeInstanceOf(RateLimitedException::class)
        ->and(OracleException::toAiException(oracleRequestException(503), 'oracle', 'm'))
        ->toBeInstanceOf(ProviderOverloadedException::class)
        ->and(OracleException::toAiException(oracleRequestException(402), 'oracle', 'm'))
        ->toBeInstanceOf(InsufficientCreditsException::class);
});

test('quota errors in the response body map to insufficient credits even on non-402 statuses', function () {
    $exception = OracleException::toAiException(
        oracleRequestException(400, json_encode(['message' => 'service limit exceeded: quota for this model'])),
        'oracle',
        'cohere.command-a-03-2025',
    );

    expect($exception)->toBeInstanceOf(InsufficientCreditsException::class);
});

test('other request errors become an AiException carrying the http status code', function () {
    $exception = OracleException::toAiException(oracleRequestException(400, 'bad request'), 'oracle', 'm');

    expect($exception)->toBeInstanceOf(AiException::class)
        ->and($exception->getCode())->toBe(400);
});

test('existing ai exceptions pass through unchanged', function () {
    $original = RateLimitedException::forProvider('oracle', 429);

    expect(OracleException::toAiException($original, 'oracle', 'm'))->toBe($original);
});
