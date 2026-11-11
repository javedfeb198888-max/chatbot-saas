<?php

namespace App\Training;

use App\Training\Exceptions\TrainingException;

/**
 * Clients type in the URLs we fetch, so a URL must never reach our own
 * server or private network (SSRF). Only public http/https sites pass.
 */
class UrlGuard
{
    public function __construct(private HostResolver $resolver)
    {
    }

    /**
     * @return array<int, string> the public IP addresses the host resolved to
     */
    public function assertSafe(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new TrainingException('This is not a valid web address.');
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new TrainingException('Only http and https addresses can be used.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new TrainingException('Addresses with a username or password cannot be used.');
        }

        if (isset($parts['port']) && ! in_array($parts['port'], [80, 443], true)) {
            throw new TrainingException('Only standard web ports can be used.');
        }

        $host = trim($parts['host'], '[]');
        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw new TrainingException('This website could not be found.');
        }

        foreach ($ips as $ip) {
            if (! $this->isPublic($ip)) {
                throw new TrainingException('This address is not a public website.');
            }
        }

        return $ips;
    }

    private function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
