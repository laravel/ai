<?php

namespace Laravel\Ai\Files;

use Closure;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class UntrustedUrl
{
    protected const MAX_REDIRECTS = 10;

    protected const BLOCKED_HOSTS = ['localhost'];

    protected const BLOCKED_HOST_SUFFIXES = ['.local', '.localhost'];

    protected const BLOCKED_IPV4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    protected const BLOCKED_IPV6 = [
        '::1/128', '::/128', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8', '2001:db8::/32', '3fff::/20',
    ];

    protected const IPV4_EMBEDDING_IPV6 = ['::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48'];

    protected static ?Closure $resolver = null;

    /**
     * Fetch the URL, validating it and every redirect hop against private and internal addresses.
     *
     * @throws InvalidArgumentException if the URL or a redirect target is blocked, or the URL redirects too many times.
     */
    public static function fetch(string $url): Response
    {
        for ($hop = 0; $hop <= static::MAX_REDIRECTS; $hop++) {
            $addresses = static::validate($url);

            $response = Http::withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => static::pinnedResolution($url, $addresses)]])
                ->get($url);

            if (! $response->redirect() || blank($response->header('Location'))) {
                return $response;
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));
        }

        throw new InvalidArgumentException('The remote file URL redirected too many times.');
    }

    /**
     * Validate the URL, returning the addresses its host resolves to.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException if the URL does not use http or https, or its host is blocked, unresolvable, or resolves to a blocked address.
     */
    public static function validate(string $url): array
    {
        $parts = parse_url($url);

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new InvalidArgumentException("The remote file URL [{$url}] must use http or https.");
        }

        $host = rtrim(strtolower(trim($parts['host'] ?? '', '[]')), '.');

        if ($host === '' || in_array($host, static::BLOCKED_HOSTS, true) || str_ends_with($host, static::BLOCKED_HOST_SUFFIXES[0]) || str_ends_with($host, static::BLOCKED_HOST_SUFFIXES[1])) {
            throw new InvalidArgumentException("The remote file URL [{$url}] points to a blocked host.");
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) === false ? static::resolve($host) : [$host];

        if ($addresses === []) {
            throw new InvalidArgumentException("The remote file URL [{$url}] host could not be resolved.");
        }

        foreach ($addresses as $address) {
            if (static::isBlocked($address)) {
                throw new InvalidArgumentException("The remote file URL [{$url}] points to a blocked address.");
            }
        }

        return $addresses;
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
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return static::inAnyRange($address, static::BLOCKED_IPV4);
        }

        foreach (static::IPV4_EMBEDDING_IPV6 as $range) {
            if (static::inRange($address, $range)) {
                return static::inAnyRange(inet_ntop(substr(inet_pton($address), 12)), static::BLOCKED_IPV4);
            }
        }

        return static::inAnyRange($address, static::BLOCKED_IPV6);
    }

    /**
     * Determine if the given IP address is within any of the given CIDR ranges.
     *
     * @param  list<string>  $ranges
     */
    protected static function inAnyRange(string $address, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (static::inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the given IP address is within the given CIDR range.
     */
    protected static function inRange(string $address, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);

        $address = inet_pton($address);
        $network = inet_pton($network);

        if ($address === false || $network === false || strlen($address) !== strlen($network)) {
            return false;
        }

        $bytes = intdiv((int) $bits, 8);
        $remainder = (int) $bits % 8;

        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        return $remainder === 0
            || (ord($address[$bytes]) >> (8 - $remainder)) === (ord($network[$bytes]) >> (8 - $remainder));
    }

    /**
     * Pin the request to the resolved addresses so a rebinding lookup cannot redirect the connection.
     *
     * @param  list<string>  $addresses
     * @return list<string>
     */
    protected static function pinnedResolution(string $url, array $addresses): array
    {
        $parts = parse_url($url);

        $host = rtrim(strtolower($parts['host']), '.');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);

        return [$host.':'.$port.':'.implode(',', $addresses)];
    }
}
