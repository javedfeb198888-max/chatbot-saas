<?php

namespace App\Http\Middleware;

use App\Models\Bot;
use App\Support\CurrentClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every widget request names a bot by its public key. This finds the bot,
 * checks the calling website is allowed, and locks the request to the bot's
 * client. The browser never sends a client id.
 */
class ResolveWidgetBot
{
    public function __construct(private CurrentClient $currentClient)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->currentClient->restrict();

        // Looked up without the client scope: at this point no client is known yet.
        $bot = Bot::withoutGlobalScopes()->with('client')->where('public_key', $request->route('publicKey'))->first();

        if ($bot === null) {
            return response()->json(['message' => 'Unknown bot.'], 404);
        }

        $origin = $request->headers->get('Origin');

        if (! $this->originAllowed($bot, $origin)) {
            return response()->json(['message' => 'This website is not allowed to use this bot.'], 403);
        }

        if ($request->isMethod('OPTIONS')) {
            return $this->cors(response()->noContent(), $origin);
        }

        $this->currentClient->set($bot->client_id);

        $request->attributes->set('bot', $bot);

        return $this->cors($next($request), $origin);
    }

    private function originAllowed(Bot $bot, ?string $origin): bool
    {
        $allowed = collect($bot->allowed_domains ?? [])
            ->map(fn (string $domain) => $this->normalise($domain))
            ->filter()
            ->values();

        // No list yet: the widget works anywhere, so a client can try it first.
        if ($allowed->isEmpty()) {
            return true;
        }

        $host = $origin ? $this->normalise($origin) : null;

        if ($host === null) {
            return false;
        }

        return $allowed->contains(fn (string $domain) => $host === $domain || str_ends_with($host, '.'.$domain));
    }

    /** "https://www.Example.com/page" and "example.com" both become "example.com". */
    private function normalise(string $value): ?string
    {
        $value = trim(strtolower($value));
        $host = parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST);

        return $host ? preg_replace('/^www\./', '', $host) : null;
    }

    private function cors(Response $response, ?string $origin): Response
    {
        if ($origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept');
            $response->headers->set('Access-Control-Max-Age', '600');
        }

        return $response;
    }
}
