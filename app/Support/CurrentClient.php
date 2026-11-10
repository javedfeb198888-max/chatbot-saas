<?php

namespace App\Support;

use App\Models\Client;

/**
 * Holds which client the current request (or job) is acting for.
 *
 * Three states:
 *  - a client id is set   -> tenant models are filtered to that client
 *  - unrestricted         -> no filter (super admin, console commands)
 *  - neither (strict)     -> tenant models return nothing (fail closed)
 */
class CurrentClient
{
    private ?string $id = null;

    public function __construct(private bool $unrestricted = false)
    {
    }

    public function set(Client|string $client): void
    {
        $this->id = $client instanceof Client ? $client->getKey() : $client;
        $this->unrestricted = false;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    /** Super admin and trusted background code: see every client. */
    public function allowAll(): void
    {
        $this->id = null;
        $this->unrestricted = true;
    }

    /** No client and no bypass: tenant queries return nothing. */
    public function restrict(): void
    {
        $this->id = null;
        $this->unrestricted = false;
    }

    public function isUnrestricted(): bool
    {
        return $this->unrestricted;
    }

    /**
     * Run a callback as one client, then restore the previous state.
     * Used by queue jobs and the widget API.
     */
    public function runAs(Client|string $client, callable $callback): mixed
    {
        [$previousId, $previousUnrestricted] = [$this->id, $this->unrestricted];

        $this->set($client);

        try {
            return $callback();
        } finally {
            $this->id = $previousId;
            $this->unrestricted = $previousUnrestricted;
        }
    }
}
