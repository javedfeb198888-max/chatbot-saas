<?php

namespace App\Training;

use App\Training\Exceptions\TrainingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class PageFetcher
{
    public function __construct(private UrlGuard $guard)
    {
    }

    /**
     * Downloads one page. Redirects are followed by hand so every hop is
     * checked by the guard before it is requested.
     */
    public function fetch(string $url): FetchedPage
    {
        $config = config('chatbot.training');

        for ($hop = 0; $hop <= $config['max_redirects']; $hop++) {
            $ips = $this->guard->assertSafe($url);

            try {
                $response = Http::withUserAgent($config['user_agent'])
                    ->timeout($config['fetch_timeout'])
                    ->withOptions([
                        'allow_redirects' => false,
                        // Connect to the exact IP that was just checked, so the host
                        // cannot switch to a private address between check and request.
                        'curl' => [CURLOPT_RESOLVE => [$this->pin($url, $ips)]],
                    ])
                    ->get($url);
            } catch (ConnectionException) {
                throw new TrainingException('The website did not respond.');
            }

            if ($response->redirect()) {
                $url = $this->absoluteUrl($url, (string) $response->header('Location'));

                continue;
            }

            if ($response->failed()) {
                throw new TrainingException("The website answered with error {$response->status()}.");
            }

            $body = $response->body();

            if (strlen($body) > $config['max_page_bytes']) {
                throw new TrainingException('This page is too large to train on.');
            }

            return new FetchedPage($url, $body, strtolower((string) $response->header('Content-Type')));
        }

        throw new TrainingException('The website redirected too many times.');
    }

    /** @param  array<int, string>  $ips */
    private function pin(string $url, array $ips): string
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);

        // Prefer IPv4; curl wants IPv6 addresses in brackets.
        usort($ips, fn (string $a, string $b) => str_contains($a, ':') <=> str_contains($b, ':'));
        $ip = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];

        return trim($parts['host'], '[]').':'.$port.':'.$ip;
    }

    private function absoluteUrl(string $base, string $location): string
    {
        if ($location === '') {
            throw new TrainingException('The website sent a redirect without an address.');
        }

        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $directory = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin.$directory.'/'.$location;
    }
}
