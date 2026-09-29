<?php

namespace Laravel\Ai\Files;

use Closure;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

class UntrustedUrl
{
    protected const MAX_REDIRECTS = 5;

    protected const BLOCKED_HOSTS = ['localhost'];

    protected const BLOCKED_HOST_SUFFIXES = ['.local', '.localhost'];

    protected static ?Closure $resolver = null;

    /**
     * Fetch the URL, validating it and every redirect hop against private and internal addresses.
     *
     * @throws InvalidArgumentException if the URL or a redirect target is blocked, or the URL redirects too many times.
     */
    public static function fetch(string $url): Response
    {
        for ($hop = 0; $hop <= static::MAX_REDIRECTS; $hop++) {
            $uri = new Uri($url);

            $response = Http::withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => static::validate($uri)]])
                ->get($url);

            if (! $response->redirect() || blank($response->header('Location'))) {
                return $response;
            }

            $url = (string) UriResolver::resolve($uri, new Uri($response->header('Location')));
        }

        throw new InvalidArgumentException('The remote file URL redirected too many times.');
    }

    /**
     * Resolve hostnames with the given callback, or the system resolver when null.
     *
     * @param  (Closure(string): list<string>)|null  $resolver
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        static::$resolver = $resolver;
    }

    /**
     * Validate the URL, returning the resolve entries that pin its host to the checked addresses.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException if the URL does not use http or https, or its host is blocked, unresolvable, or resolves to a blocked address.
     */
    protected static function validate(Uri $uri): array
    {
        if (! in_array($uri->getScheme(), ['http', 'https'], true)) {
            throw new InvalidArgumentException("The remote file URL [{$uri}] must use http or https.");
        }

        $host = rtrim(trim($uri->getHost(), '[]'), '.');

        if (in_array($host, config('ai.remote_files.allowed_hosts', []), true)) {
            return [];
        }

        if ($host === '' || in_array($host, static::BLOCKED_HOSTS, true) || Str::endsWith($host, static::BLOCKED_HOST_SUFFIXES)) {
            throw new InvalidArgumentException("The remote file URL [{$uri}] points to a blocked host.");
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;

        $addresses = $literal ? [$host] : static::resolve($host);

        if ($addresses === []) {
            throw new InvalidArgumentException("The remote file URL [{$uri}] host could not be resolved.");
        }

        foreach ($addresses as $address) {
            if (static::isBlocked($address)) {
                throw new InvalidArgumentException("The remote file URL [{$uri}] points to a blocked address.");
            }
        }

        if ($literal) {
            return [];
        }

        $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);

        return [$host.':'.$port.':'.implode(',', $addresses)];
    }

    /**
     * Resolve the given host to its IPv4 and IPv6 addresses.
     *
     * @return list<string>
     */
    protected static function resolve(string $host): array
    {
        if (static::$resolver !== null) {
            return (static::$resolver)($host);
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }

    /**
     * Determine if the given IP address is private, reserved, or otherwise internal.
     */
    protected static function isBlocked(string $address): bool
    {
        // The global range check accepts NAT64 addresses, so the IPv4 address they carry is checked instead.
        if (IpUtils::checkIp($address, '64:ff9b::/96')) {
            $address = inet_ntop(substr(inet_pton($address), 12));
        }

        return IpUtils::checkIp($address, '64:ff9b:1::/48')
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false;
    }
}
