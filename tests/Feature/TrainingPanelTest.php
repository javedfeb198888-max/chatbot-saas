<?php

namespace Tests\Feature;

use App\Actions\CreateClientWithOwner;
use App\Actions\Training\AddSource;
use App\Actions\Training\SaveQaPair;
use App\Enums\Role;
use App\Filament\App\Resources\QaPairs\Pages\CreateQaPair;
use App\Filament\App\Resources\QaPairs\Pages\EditQaPair;
use App\Filament\App\Resources\QaPairs\Pages\ListQaPairs;
use App\Filament\App\Resources\Sources\Pages\CreateSource;
use App\Filament\App\Resources\Sources\Pages\ListSources;
use App\Models\Bot;
use App\Models\Chunk;
use App\Models\Client;
use App\Models\QaPair;
use App\Models\Source;
use App\Models\User;
use App\Support\CurrentClient;
use App\Training\HostResolver;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Ai\Embeddings;
use Livewire\Livewire;
use Tests\TestCase;

class TrainingPanelTest extends TestCase
{
    use RefreshDatabase;

    private Client $acme;

    private Client $globex;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Embeddings::fake();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(
            '<html><head><title>About us</title></head><body><main><p>We are a friendly dental clinic in the city centre.</p></main></body></html>',
            200, ['Content-Type' => 'text/html'],
        )]);

        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });

        $create = app(CreateClientWithOwner::class);
        $this->acme = $create->handle(['name' => 'Acme Dental', 'slug' => 'acme'], 'Ali', 'ali@acme.test');
        $this->globex = $create->handle(['name' => 'Globex Law', 'slug' => 'globex'], 'Sara', 'sara@globex.test');

        $this->owner = User::where('email', 'ali@acme.test')->firstOrFail();

        $this->member = User::factory()->make(['email' => 'staff@acme.test']);
        $this->member->client_id = $this->acme->id;
        $this->member->role = Role::Member;
        $this->member->save();

        // Globex already has training content that Acme must never see.
        $this->as($this->globex, function () {
            app(AddSource::class)->handle(Bot::firstOrFail(), ['type' => 'text', 'title' => 'Globex fee schedule', 'content' => 'Globex confidential fee schedule for legal clients.']);
            app(SaveQaPair::class)->create(Bot::firstOrFail(), 'Globex secret question?', 'Globex secret answer.');
        });
    }

    private function as(Client $client, callable $callback): mixed
    {
        return app(CurrentClient::class)->runAs($client, $callback);
    }

    private function actAsPanelUser(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentClient::class)->set($user->client_id);
    }

    public function test_training_pages_show_only_the_clients_own_content(): void
    {
        $this->as($this->acme, function () {
            app(AddSource::class)->handle(Bot::firstOrFail(), ['type' => 'text', 'title' => 'Acme parking notes', 'content' => 'Free parking behind the Acme building for patients.']);
            app(SaveQaPair::class)->create(Bot::firstOrFail(), 'Acme insurance question?', 'Yes we do.');
        });

        $this->actingAs($this->owner)->get('/app/training')
            ->assertOk()
            ->assertSee('Acme parking notes')
            ->assertDontSee('Globex fee schedule')
            ->assertDontSee('Acme insurance question?'); // Q&A has its own screen

        $this->actingAs($this->owner)->get('/app/questions')
            ->assertOk()
            ->assertSee('Acme insurance question?')
            ->assertDontSee('Globex secret question?');
    }

    public function test_members_can_look_but_not_add_or_change(): void
    {
        $pair = $this->as($this->acme, fn () => app(SaveQaPair::class)->create(Bot::firstOrFail(), 'Opening hours?', 'Nine to five.'));

        $this->actingAs($this->member)->get('/app/training')->assertOk();
        $this->actingAs($this->member)->get('/app/questions')->assertOk()->assertSee('Opening hours?');
        $this->actingAs($this->member)->get('/app/training/create')->assertForbidden();
        $this->actingAs($this->member)->get('/app/questions/create')->assertForbidden();
        $this->actingAs($this->member)->get("/app/questions/{$pair->id}/edit")->assertForbidden();

        $this->assertFalse($this->member->can('delete', $pair));
        $this->assertFalse($this->member->can('update', $this->as($this->acme, fn () => Source::firstOrFail())));
    }

    public function test_an_owner_cannot_open_another_clients_question(): void
    {
        $globexPair = $this->as($this->globex, fn () => QaPair::firstOrFail());

        $this->actingAs($this->owner)->get("/app/questions/{$globexPair->id}/edit")->assertNotFound();
        $this->assertFalse($this->owner->can('update', $globexPair));
    }

    public function test_an_owner_can_add_a_web_page_from_the_panel(): void
    {
        $this->actAsPanelUser($this->owner);

        Livewire::test(CreateSource::class)
            ->fillForm(['type' => 'url', 'url' => 'https://acme.test/about'])
            ->call('create')
            ->assertHasNoFormErrors();

        $source = Source::where('url', 'https://acme.test/about')->sole();

        $this->assertSame($this->acme->id, $source->client_id);
        $this->assertSame('ready', $source->status);
        $this->assertSame('About us', $source->title);
        $this->assertSame(1, Chunk::where('source_id', $source->id)->count());
    }

    public function test_adding_the_same_page_twice_shows_an_error_on_the_field(): void
    {
        $this->actAsPanelUser($this->owner);

        app(AddSource::class)->handle(Bot::firstOrFail(), ['type' => 'url', 'url' => 'https://acme.test/about']);

        Livewire::test(CreateSource::class)
            ->fillForm(['type' => 'url', 'url' => 'https://acme.test/about'])
            ->call('create')
            ->assertHasFormErrors(['url']);

        $this->assertSame(1, Source::where('type', 'url')->count());
    }

    public function test_an_owner_can_paste_text_from_the_panel(): void
    {
        $this->actAsPanelUser($this->owner);

        Livewire::test(CreateSource::class)
            ->fillForm(['type' => 'text', 'title' => 'Parking', 'content' => 'Free parking is available behind the building.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('ready', Source::where('title', 'Parking')->sole()->status);
    }

    public function test_an_owner_can_add_edit_and_delete_a_question(): void
    {
        $this->actAsPanelUser($this->owner);

        Livewire::test(CreateQaPair::class)
            ->fillForm(['question' => 'Do you open on Sunday?', 'answer' => 'No, we are closed on Sunday.'])
            ->call('create')
            ->assertHasNoFormErrors();

        $pair = QaPair::sole();
        $this->assertStringContainsString('closed on Sunday', Chunk::where('source_id', $pair->source_id)->sole()->content);

        Livewire::test(EditQaPair::class, ['record' => $pair->getRouteKey()])
            ->fillForm(['answer' => 'Yes, from ten to two.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertStringContainsString('from ten to two', Chunk::where('source_id', $pair->source_id)->sole()->content);

        Livewire::test(ListQaPairs::class)->callTableAction('delete', $pair);

        $this->assertSame(0, QaPair::count());
        $this->assertSame(0, Chunk::count());
        $this->assertSame(0, Source::count());
    }

    public function test_train_again_and_delete_work_from_the_table(): void
    {
        $this->actAsPanelUser($this->owner);

        $source = app(AddSource::class)->handle(Bot::firstOrFail(), ['type' => 'url', 'url' => 'https://acme.test/about']);
        Source::whereKey($source->id)->update(['status' => 'failed', 'error' => 'The website did not respond.']);

        Livewire::test(ListSources::class)
            ->assertCanSeeTableRecords([$source])
            ->callTableAction('retrain', $source);

        $this->assertSame('ready', $source->fresh()->status);
        $this->assertNull($source->fresh()->error);

        Livewire::test(ListSources::class)->callTableAction('delete', $source);

        $this->assertSame(0, Source::count());
        $this->assertSame(0, Chunk::count());
    }

    public function test_members_do_not_get_the_change_buttons(): void
    {
        $source = $this->as($this->acme, fn () => app(AddSource::class)->handle(Bot::firstOrFail(), ['type' => 'url', 'url' => 'https://acme.test/about']));

        $this->actAsPanelUser($this->member);

        Livewire::test(ListSources::class)
            ->assertCanSeeTableRecords([$source])
            ->assertTableActionHidden('retrain', $source)
            ->assertTableActionHidden('delete', $source);
    }
}
