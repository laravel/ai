<?php

namespace Laravel\Ai\Gateway\Oracle;

use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Signs outgoing OCI requests using the OCI HTTP Signature scheme (draft-cavage-http-signatures-08).
 *
 * Supports API-key (config-file) authentication. The class is intentionally structured so that
 * alternative credential sources (instance-principal / resource-principal) can produce a signer
 * with a resolved key + keyId without changing the signing algorithm below.
 *
 * @see https://docs.oracle.com/en-us/iaas/Content/API/Concepts/signingrequests.htm
 */
class OracleRequestSigner
{
    /**
     * The headers signed for requests that carry a body (POST / PUT / PATCH).
     *
     * @var list<string>
     */
    protected const BODY_HEADERS = ['(request-target)', 'host', 'date', 'x-content-sha256', 'content-type', 'content-length'];

    /**
     * The headers signed for requests without a body (GET / HEAD / DELETE).
     *
     * @var list<string>
     */
    protected const BODYLESS_HEADERS = ['(request-target)', 'host', 'date'];

    public function __construct(
        protected string $tenancyId,
        protected string $userId,
        protected string $fingerprint,
        protected string $privateKey,
        protected ?string $passphrase = null,
    ) {}

    /**
     * Get the keyId used to identify the API signing key to OCI.
     */
    public function keyId(): string
    {
        return "{$this->tenancyId}/{$this->userId}/{$this->fingerprint}";
    }

    /**
     * Sign the given PSR-7 request and return a new request carrying the signature headers.
     */
    public function sign(RequestInterface $request, ?string $date = null): RequestInterface
    {
        $method = strtolower($request->getMethod());
        $date ??= gmdate('D, d M Y H:i:s').' GMT';
        $host = $request->getUri()->getHost();

        $request = $request->withHeader('date', $date);

        if (! $request->hasHeader('host')) {
            $request = $request->withHeader('host', $host);
        }

        $hasBody = in_array($method, ['post', 'put', 'patch'], true);

        if ($hasBody) {
            $body = (string) $request->getBody();

            if ($request->getBody()->isSeekable()) {
                $request->getBody()->rewind();
            }

            $contentType = $request->getHeaderLine('content-type') ?: 'application/json';

            $request = $request
                ->withHeader('content-type', $contentType)
                ->withHeader('content-length', (string) strlen($body))
                ->withHeader('x-content-sha256', base64_encode(hash('sha256', $body, true)));
        }

        $headers = $hasBody ? self::BODY_HEADERS : self::BODYLESS_HEADERS;

        $signingString = $this->buildSigningString($request, $headers);

        $signature = $this->signString($signingString);

        $authorization = sprintf(
            'Signature version="1",keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $this->keyId(),
            implode(' ', $headers),
            $signature,
        );

        return $request->withHeader('Authorization', $authorization);
    }

    /**
     * Build the signing string for the given request and ordered header list.
     *
     * @param  list<string>  $headers
     */
    public function buildSigningString(RequestInterface $request, array $headers): string
    {
        $lines = [];

        foreach ($headers as $header) {
            if ($header === '(request-target)') {
                $target = $request->getUri()->getPath() ?: '/';

                if ($query = $request->getUri()->getQuery()) {
                    $target .= '?'.$query;
                }

                $lines[] = '(request-target): '.strtolower($request->getMethod()).' '.$target;

                continue;
            }

            $lines[] = $header.': '.$request->getHeaderLine($header);
        }

        return implode("\n", $lines);
    }

    /**
     * Sign the given string with the configured RSA private key and return the base64 signature.
     */
    protected function signString(string $signingString): string
    {
        $key = openssl_pkey_get_private($this->privateKey, $this->passphrase ?? '');

        if ($key === false) {
            throw new InvalidArgumentException('Unable to load the OCI private key. Verify the key contents and passphrase.');
        }

        $signature = '';

        if (! openssl_sign($signingString, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Failed to sign the OCI request.');
        }

        return base64_encode($signature);
    }
}
