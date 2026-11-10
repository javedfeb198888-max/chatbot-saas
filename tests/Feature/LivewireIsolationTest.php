<?php

namespace Tests\Feature;

use App\Actions\CreateClientWithOwner;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Filament pages keep talking to the server through Livewire update requests.
 * Those requests must run the same client lock as the first page load.
 */
class LivewireIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_livewire_update_requests_stay_locked_to_the_users_client(): void
    {
        Notification::fake();

        $create = app(CreateClientWithOwner::class);
        $create->handle(['name' => 'Acme Dental', 'slug' => 'acme'], 'Ali', 'ali@acme.test');
        $create->handle(['name' => 'Globex Law', 'slug' => 'globex'], 'Sara', 'sara@globex.test');

        $owner = User::where('email', 'ali@acme.test')->firstOrFail();

        $page = $this->actingAs($owner)->get('/app/bots')->assertOk();

        // Take the page component's snapshot out of the HTML, as the browser would.
        preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);

        $snapshot = collect($matches[1])
            ->map(fn (string $raw) => html_entity_decode($raw, ENT_QUOTES))
            ->first(fn (string $json) => str_contains($json, 'ListBots'));

        $this->assertNotNull($snapshot, 'Bots page snapshot not found in the HTML.');

        $updateUri = collect(Route::getRoutes())
            ->first(fn ($route) => str_ends_with($route->uri(), 'livewire/update') || str_contains((string) $route->getName(), 'livewire.update'))
            ->uri();

        // A real request starts with a fresh, strict context. The test client reuses
        // the app, so reset it: only the panel middleware may open the client now.
        $this->app->forgetScopedInstances();
        app(CurrentClient::class)->restrict();

        $response = $this->actingAs($owner)->postJson('/'.$updateUri, [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => (object) [],
                'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]],
        ], ['X-Livewire' => 'true']);

        $response->assertOk();

        $html = json_encode($response->json());

        $this->assertStringContainsString('Acme Dental Assistant', $html);
        $this->assertStringNotContainsString('Globex Law Assistant', $html);
    }
}
