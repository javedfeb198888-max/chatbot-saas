<?php

namespace App\Http\Middleware;

use App\Support\CurrentClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every dashboard route after login.
 * Super admin sees all clients; everyone else is locked to their own client.
 */
class SetCurrentClient
{
    public function __construct(private CurrentClient $currentClient)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->currentClient->restrict();

        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($user->isSuperAdmin()) {
            $this->currentClient->allowAll();

            return $next($request);
        }

        $client = $user->client;

        abort_if($client === null, 403, 'This account is not linked to a client.');
        abort_if(! $client->isActive(), 403, 'This client account is suspended.');

        $this->currentClient->set($client);

        return $next($request);
    }
}
