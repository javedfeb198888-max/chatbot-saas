<?php

namespace App\Providers;

use App\Support\CurrentClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request or job. Web requests start strict (no data
        // until a client is set); console commands start unrestricted.
        $this->app->scoped(CurrentClient::class, fn ($app) => new CurrentClient(
            unrestricted: $app->runningInConsole(),
        ));
    }

    public function boot(): void
    {
        //
    }
}
