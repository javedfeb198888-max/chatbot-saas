<?php

namespace Tests\Feature;

use App\Actions\CreateClientWithOwner;
use App\Chat\UsageRecorder;
use App\Chat\WidgetAgent;
use App\Enums\Role;
use App\Filament\App\Resources\Bots\Pages\EditBot;
use App\Filament\App\Resources\Leads\Pages\ListLeads;
use App\Models\Booking;
use App\Models\Bot;
use App\Models\CalendarConnection;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Ai\Embeddings;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Client $acme;

    private Client $globex;

    private User $owner;

    private User $member;

    private User $admin;

    private Conversation $acmeChat;

    private Conversation $globexChat;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Embeddings::fake();
        Http::preventStrayRequests();

        $create = app(CreateClientWithOwner::class);
        $this->acme = $create->handle(['name' => 'Acme Dental', 'slug' => 'acme', 'monthly_message_limit' => 100], 'Ali', 'ali@acme.test');
        $this->globex = $create->handle(['name' => 'Globex Law', 'slug' => 'globex'], 'Sara', 'sara@globex.test');

        $this->owner = User::where('email', 'ali@acme.test')->firstOrFail();
        $this->member = $this->makeUser($this->acme, Role::Member, 'staff@acme.test');
        $this->admin = $this->makeUser(null, Role::SuperAdmin, 'admin@platform.test');

        $this->acmeChat = $this->seedChat($this->acme, 'Acme Visitor', 'visitor@acme-customer.test', 'Do you do whitening?', 'Yes, we offer whitening.');
        $this->globexChat = $this->seedChat($this->globex, 'Globex Visitor', 'visitor@globex-customer.test', 'What are your fees?', 'Our fee is confidential.');

        app(UsageRecorder::class)->record($this->acme->id, 1000, 200, 'claude-haiku-5-5');
        app(UsageRecorder::class)->record($this->acme->id, 1000, 200, 'claude-haiku-5-5');
        app(UsageRecorder::class)->record($this->globex->id, 500, 100, 'some-other-model');
    }

    private function seedChat(Client $client, string $name, string $email, string $question, string $answer): Conversation
    {
        return app(CurrentClient::class)->runAs($client, function () use ($name, $email, $question, $answer) {
            $bot = Bot::firstOrFail();
            $chat = Conversation::create(['bot_id' => $bot->id, 'visitor_id' => 'v', 'page_url' => 'https://example.test/page', 'last_message_at' => now()]);

            Message::create(['conversation_id' => $chat->id, 'role' => 'user', 'content' => $question]);
            Message::create(['conversation_id' => $chat->id, 'role' => 'assistant', 'content' => $answer]);

            $lead = new Lead(['conversation_id' => $chat->id, 'name' => $name, 'email' => $email]);
            $lead->bot_id = $bot->id;
            $lead->save();

            Booking::create(['bot_id' => $bot->id, 'conversation_id' => $chat->id, 'lead_id' => $lead->id, 'starts_at' => now()->addDays(2), 'provider_booking_uid' => 'uid']);

            return $chat;
        });
    }

    private function makeUser(?Client $client, Role $role, string $email): User
    {
        $user = User::factory()->make(['email' => $email]);
        $user->client_id = $client?->id;
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function actAsPanelUser(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentClient::class)->set($user->client_id);
    }

    public function test_inbox_pages_show_only_the_clients_own_records(): void
    {
        foreach (['/app/conversations', '/app/leads', '/app/bookings'] as $page) {
            $this->actingAs($this->owner)->get($page)
                ->assertOk()
                ->assertSee('Acme Visitor')
                ->assertDontSee('Globex Visitor');

            // A new browser session for the next person.
            $this->flushSession();
            $this->actingAs($this->member)->get($page)->assertOk()->assertSee('Acme Visitor');
            $this->flushSession();
        }
    }

    public function test_a_conversation_page_shows_the_transcript_and_visitor(): void
    {
        $this->actingAs($this->owner)->get("/app/conversations/{$this->acmeChat->id}")
            ->assertOk()
            ->assertSee('Do you do whitening?')
            ->assertSee('Yes, we offer whitening.')
            ->assertSee('visitor@acme-customer.test');
    }

    public function test_dashboard_times_are_shown_in_the_clients_time_zone(): void
    {
        $this->acme->update(['timezone' => 'Asia/Karachi']);
        app(CurrentClient::class)->runAs($this->acme, fn () => $this->acmeChat->forceFill(['created_at' => '2026-11-11 05:25:00'])->save());

        // 05:25 UTC is 10:25 in Karachi.
        $this->actingAs($this->owner->fresh())->get("/app/conversations/{$this->acmeChat->id}")
            ->assertOk()
            ->assertSee('11 Nov 2026, 10:25');
    }

    public function test_another_clients_conversation_cannot_be_opened(): void
    {
        $this->actingAs($this->owner)->get("/app/conversations/{$this->globexChat->id}")->assertNotFound();
    }

    public function test_only_owners_may_delete_visitor_data(): void
    {
        $lead = app(CurrentClient::class)->runAs($this->acme, fn () => Lead::firstOrFail());

        $this->assertTrue($this->owner->can('delete', $lead));
        $this->assertTrue($this->owner->can('delete', $this->acmeChat));
        $this->assertFalse($this->member->can('delete', $lead));
        $this->assertFalse($this->member->can('delete', $this->acmeChat));
        $this->assertFalse($this->owner->can('delete', $this->globexChat));
        $this->assertFalse($this->owner->can('update', $lead));

        $this->actAsPanelUser($this->owner);
        Livewire::test(ListLeads::class)->callTableAction('delete', $lead);

        $this->assertSame(0, Lead::count());
        $this->assertSame(1, app(CurrentClient::class)->runAs($this->globex, fn () => Lead::count()));
    }

    public function test_the_client_dashboard_shows_this_months_usage(): void
    {
        $this->actingAs($this->owner)->get('/app')->assertOk();

        $this->actAsPanelUser($this->owner);

        Livewire::test(\App\Filament\App\Widgets\UsageOverview::class)
            ->assertSee('2 of 100')
            ->assertSee('98 left this month')
            ->assertSee('Conversations')
            ->assertSee('Bookings');
    }

    public function test_the_usage_widget_warns_when_the_limit_is_reached(): void
    {
        $this->acme->update(['monthly_message_limit' => 2]);
        $this->actAsPanelUser($this->owner->fresh());

        Livewire::test(\App\Filament\App\Widgets\UsageOverview::class)->assertSee('Limit reached');
    }

    public function test_the_admin_sees_usage_per_client_and_platform_totals(): void
    {
        $this->actingAs($this->admin)->get('/admin/clients')
            ->assertOk()
            ->assertSee('2 of 100')
            ->assertSee('1 of 2,000');

        $this->actingAs($this->admin)->get('/admin')->assertOk();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(CurrentClient::class)->allowAll();

        Livewire::test(\App\Filament\Admin\Widgets\PlatformOverview::class)
            ->assertSee('Active clients')
            ->assertSee('3')        // messages answered across both clients
            ->assertSee('$0.00');   // 0.0014 dollars rounds to 0.00
    }

    public function test_cost_is_estimated_from_tokens_and_model_prices(): void
    {
        $cost = fn (Client $client) => (float) \App\Models\UsageDaily::withoutGlobalScopes()->where('client_id', $client->id)->sum('cost_usd');

        // Haiku: 2 x (1000 x $0.10 + 200 x $0.50) per million = $0.0004
        $this->assertEqualsWithDelta(0.0004, $cost($this->acme), 0.00001);

        // Unknown model falls back to the default price: (500 x $1 + 100 x $5) per million = $0.001
        $this->assertEqualsWithDelta(0.001, $cost($this->globex), 0.00001);
    }

    public function test_an_owner_can_connect_and_disconnect_the_booking_calendar(): void
    {
        $this->actAsPanelUser($this->owner);
        $bot = Bot::firstOrFail();

        // Saving with the calendar fields empty creates nothing.
        Livewire::test(EditBot::class, ['record' => $bot->getRouteKey()])
            ->fillForm(['name' => 'Acme Helper'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Acme Helper', $bot->fresh()->name);
        $this->assertSame(0, CalendarConnection::count());

        Livewire::test(EditBot::class, ['record' => $bot->getRouteKey()])
            ->fillForm([
                'calendarConnection.cal_username' => 'acme-dental',
                'calendarConnection.event_type_slug' => 'consultation',
                'calendarConnection.timezone' => 'Asia/Karachi',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $connection = CalendarConnection::sole();

        $this->assertSame($this->acme->id, $connection->client_id);
        $this->assertSame($bot->id, $connection->bot_id);
        $this->assertSame('acme-dental', $connection->cal_username);
        $this->assertSame('Asia/Karachi', $connection->timezone);
    }

    public function test_the_event_type_is_required_once_a_username_is_entered(): void
    {
        $this->actAsPanelUser($this->owner);

        Livewire::test(EditBot::class, ['record' => Bot::firstOrFail()->getRouteKey()])
            ->fillForm(['calendarConnection.cal_username' => 'acme-dental', 'calendarConnection.event_type_slug' => null])
            ->call('save')
            ->assertHasFormErrors(['calendarConnection.event_type_slug']);
    }

    public function test_the_calendar_test_button_reports_free_times(): void
    {
        $this->actAsPanelUser($this->owner);
        $bot = Bot::firstOrFail();

        CalendarConnection::create(['bot_id' => $bot->id, 'cal_username' => 'acme-dental', 'event_type_slug' => 'consultation', 'timezone' => 'UTC']);

        Http::fake(['api.cal.com/*' => Http::response(['status' => 'success', 'data' => ['2026-11-13' => [['start' => '2026-11-13T09:00:00.000Z']]]])]);

        Livewire::test(EditBot::class, ['record' => $bot->getRouteKey()])
            ->callAction('testCalendar')
            ->assertNotified('Calendar connected');
    }

    public function test_the_bot_gets_booking_tools_only_when_a_calendar_is_connected(): void
    {
        WidgetAgent::fake(fn () => 'ok');

        $toolNames = function (string $message) {
            $names = null;

            $this->app->forgetScopedInstances();
            $bot = Bot::withoutGlobalScopes()->where('client_id', $this->acme->id)->firstOrFail();
            $this->postJson("/api/widget/{$bot->public_key}/chat", ['message' => $message, 'visitor_id' => 'v9'])->streamedContent();

            WidgetAgent::assertPrompted(function ($prompt) use (&$names, $message) {
                if ($prompt->prompt !== $message) {
                    return false;
                }

                $names = collect($prompt->agent->tools())->map(fn ($tool) => $tool->name())->all();

                return true;
            });

            return $names;
        };

        $this->assertSame(['save_contact_details'], $toolNames('Before calendar'));

        app(CurrentClient::class)->runAs($this->acme, fn () => CalendarConnection::create([
            'bot_id' => Bot::firstOrFail()->id, 'cal_username' => 'acme-dental', 'event_type_slug' => 'consultation', 'timezone' => 'UTC',
        ]));

        $this->assertSame(['save_contact_details', 'check_available_slots', 'book_appointment'], $toolNames('After calendar'));
    }
}
