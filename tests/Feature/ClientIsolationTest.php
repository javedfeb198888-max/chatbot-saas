<?php

namespace Tests\Feature;

use App\Casts\Vector;
use App\Enums\Role;
use App\Http\Middleware\SetCurrentClient;
use App\Models\Bot;
use App\Models\Chunk;
use App\Models\Client;
use App\Models\Source;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

/**
 * The one promise the whole product rests on:
 * a client can never read or write another client's data.
 */
class ClientIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Client $acme;

    private Client $globex;

    private Bot $acmeBot;

    private Bot $globexBot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Client::factory()->create(['name' => 'Acme']);
        $this->globex = Client::factory()->create(['name' => 'Globex']);

        $this->acmeBot = $this->context()->runAs($this->acme, fn () => Bot::factory()->create());
        $this->globexBot = $this->context()->runAs($this->globex, fn () => Bot::factory()->create());
    }

    private function context(): CurrentClient
    {
        return app(CurrentClient::class);
    }

    public function test_a_client_only_sees_its_own_bots(): void
    {
        $this->context()->set($this->acme);

        $this->assertSame([$this->acmeBot->id], Bot::pluck('id')->all());
        $this->assertNull(Bot::find($this->globexBot->id));
    }

    public function test_new_rows_are_stamped_with_the_current_client(): void
    {
        $this->context()->set($this->globex);

        $bot = Bot::create(['name' => 'Second bot']);

        $this->assertSame($this->globex->id, $bot->client_id);
        $this->assertStringStartsWith('bot_', $bot->public_key);
    }

    public function test_client_id_cannot_be_mass_assigned(): void
    {
        $this->context()->set($this->acme);

        $bot = Bot::create(['name' => 'Sneaky', 'client_id' => $this->globex->id]);

        $this->assertSame($this->acme->id, $bot->client_id);
    }

    public function test_rows_cannot_be_created_without_a_client(): void
    {
        $this->context()->allowAll();

        $this->expectException(LogicException::class);

        Bot::create(['name' => 'Orphan']);
    }

    public function test_strict_context_without_a_client_returns_nothing(): void
    {
        $this->context()->restrict();

        $this->assertCount(0, Bot::all());
    }

    public function test_unrestricted_context_sees_every_client(): void
    {
        $this->context()->allowAll();

        $this->assertCount(2, Bot::all());
    }

    public function test_vector_search_stays_inside_one_bot(): void
    {
        $near = $this->vector(0.9);
        $far = $this->vector(0.1);

        // Globex owns the chunk that is closest to the question.
        $this->seedChunk($this->globex, $this->globexBot, 'Globex secret pricing', $near);
        $this->seedChunk($this->acme, $this->acmeBot, 'Acme opening hours', $far);

        $this->context()->set($this->acme);

        $results = Chunk::where('bot_id', $this->acmeBot->id)
            ->orderByRaw('embedding <=> ?', [Vector::toSql($near)])
            ->limit(5)
            ->pluck('content')
            ->all();

        $this->assertSame(['Acme opening hours'], $results);

        // Even asking for the other bot's id returns nothing for this client.
        $this->assertCount(0, Chunk::where('bot_id', $this->globexBot->id)->get());
    }

    public function test_embedding_round_trips_as_an_array_of_floats(): void
    {
        $chunk = $this->seedChunk($this->acme, $this->acmeBot, 'Hello', $this->vector(0.5));

        $this->context()->set($this->acme);
        $embedding = Chunk::find($chunk->id)->embedding;

        $this->assertCount(1536, $embedding);
        $this->assertEqualsWithDelta(0.5, $embedding[0], 0.0001);
    }

    public function test_dashboard_middleware_locks_a_user_to_their_client(): void
    {
        Route::middleware(['web', SetCurrentClient::class])
            ->get('/_test/bots', fn () => Bot::pluck('id'));

        $owner = $this->user($this->acme, Role::Owner);

        $this->actingAs($owner)->get('/_test/bots')
            ->assertOk()
            ->assertExactJson([$this->acmeBot->id]);
    }

    public function test_super_admin_sees_all_clients_through_the_middleware(): void
    {
        Route::middleware(['web', SetCurrentClient::class])
            ->get('/_test/bots', fn () => Bot::count());

        $admin = $this->user(null, Role::SuperAdmin);

        $this->actingAs($admin)->get('/_test/bots')->assertOk()->assertSee('2');
    }

    public function test_suspended_client_is_blocked(): void
    {
        Route::middleware(['web', SetCurrentClient::class])
            ->get('/_test/bots', fn () => Bot::count());

        $this->acme->update(['status' => 'suspended']);

        $this->actingAs($this->user($this->acme, Role::Owner))
            ->get('/_test/bots')
            ->assertForbidden();
    }

    public function test_guest_request_sees_nothing(): void
    {
        Route::middleware(['web', SetCurrentClient::class])
            ->get('/_test/bots', fn () => Bot::count());

        $this->get('/_test/bots')->assertOk()->assertSee('0');
    }

    private function user(?Client $client, Role $role): User
    {
        $user = User::factory()->make();
        $user->client_id = $client?->id;
        $user->role = $role;
        $user->save();

        return $user;
    }

    /** @return array<int, float> */
    private function vector(float $value): array
    {
        // Two different directions so cosine distance can tell them apart.
        $vector = array_fill(0, 1536, 0.0);
        $vector[0] = $value;
        $vector[1] = 1 - $value;

        return $vector;
    }

    private function seedChunk(Client $client, Bot $bot, string $content, array $embedding): Chunk
    {
        return $this->context()->runAs($client, function () use ($bot, $content, $embedding) {
            $source = Source::create(['bot_id' => $bot->id, 'type' => 'text', 'status' => 'ready']);

            return Chunk::create([
                'bot_id' => $bot->id,
                'source_id' => $source->id,
                'content' => $content,
                'embedding' => $embedding,
            ]);
        });
    }
}
