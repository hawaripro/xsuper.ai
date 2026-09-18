<?php

namespace App\Services;

use App\Exceptions\AiProxyException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

class AiProviderEndpoint
{
    private const INVALID_URL = 'Enter a public HTTPS provider URL without credentials, query parameters, or fragments.';

    public function normalize(string $url, string $protocol = 'openai'): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $url)) {
            throw new InvalidArgumentException(self::INVALID_URL);
        }
        $url = trim($url);
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false
            || preg_match('/[\x00-\x20\x7f\\\\]/', rawurldecode($url))) {
            throw new InvalidArgumentException(self::INVALID_URL);
        }
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || array_key_exists('query', $parts) || array_key_exists('fragment', $parts)) {
            throw new InvalidArgumentException(self::INVALID_URL);
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $address = trim($host, '[]');
        if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
            if (! $this->isPublicAddress($address)) {
                throw new InvalidArgumentException(self::INVALID_URL);
            }
        } elseif (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || ! str_contains($host, '.')
            || preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/i', $host)
            || preg_match('/(?:^|\.)(?:localhost|localdomain|local|internal|intranet|lan|home|onion)$/i', $host)) {
            throw new InvalidArgumentException(self::INVALID_URL);
        }

        $port = $parts['port'] ?? 443;
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(self::INVALID_URL);
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if ($protocol === 'fal') {
            if ($host !== 'fal.run' || $port !== 443 || $path !== '') {
                throw new InvalidArgumentException('Use https://fal.run as the fal provider URL, without /v1 or a model path.');
            }

            return 'https://fal.run';
        }

        return 'https://'.$host.($port === 443 ? '' : ':'.$port).($path === '' ? '/v1' : $path);
    }

    public function requestOptions(string $baseUrl): array
    {
        try {
            $url = $this->normalize($baseUrl);
            $parts = parse_url($url);
            $host = $parts['host'];
            $address = trim($host, '[]');
            $addresses = filter_var($address, FILTER_VALIDATE_IP) !== false
                ? [$address]
                : $this->resolveAddresses($host);
            if ($addresses === [] || ! extension_loaded('curl')) {
                throw new InvalidArgumentException;
            }
            foreach ($addresses as $resolved) {
                if (! is_string($resolved) || ! $this->isPublicAddress($resolved)) {
                    throw new InvalidArgumentException;
                }
            }
        } catch (Throwable) {
            throw new AiProxyException('The provider endpoint is unavailable.', 503);
        }

        $addresses = array_values(array_unique($addresses));
        $pinned = array_map(fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip, $addresses);

        return [
            'allow_redirects' => false,
            'proxy' => '',
            'verify' => true,
            'curl' => [
                CURLOPT_RESOLVE => [$host.':'.($parts['port'] ?? 443).':'.implode(',', $pinned)],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ],
        ];
    }

    protected function resolveAddresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }
        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! IpUtils::checkIp($address, ['224.0.0.0/4', 'ff00::/8', '64:ff9b::/96', '2002::/16']);
    }
}
