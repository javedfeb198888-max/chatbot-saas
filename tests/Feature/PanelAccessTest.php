<?php

namespace Tests\Feature;

use App\Actions\CreateClientWithOwner;
use App\Enums\Role;
use App\Filament\Admin\Resources\Clients\Pages\CreateClient;
use App\Filament\App\Resources\TeamMembers\Pages\CreateTeamMember;
use App\Models\Bot;
use App\Models\Client;
use App\Models\User;
use App\Notifications\ClientInvite;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private Client $acme;

    private Client $globex;

    private User $acmeOwner;

    private User $globexOwner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $create = app(CreateClientWithOwner::class);

        $this->acme = $create->handle(['name' => 'Acme Dental', 'slug' => 'acme'], 'Ali Owner', 'ali@acme.test');
        $this->globex = $create->handle(['name' => 'Globex Law', 'slug' => 'globex'], 'Sara Owner', 'sara@globex.test');

        $this->acmeOwner = User::where('email', 'ali@acme.test')->firstOrFail();
        $this->globexOwner = User::where('email', 'sara@globex.test')->firstOrFail();

        $this->admin = $this->makeUser(null, Role::SuperAdmin, 'admin@platform.test');
    }

    public function test_creating_a_client_makes_an_owner_a_bot_and_sends_an_invite(): void
    {
        $this->assertSame($this->acme->id, $this->acmeOwner->client_id);
        $this->assertSame(Role::Owner, $this->acmeOwner->role);

        $bots = app(CurrentClient::class)->runAs($this->acme, fn () => Bot::all());
        $this->assertCount(1, $bots);
        $this->assertSame('Acme Dental Assistant', $bots->first()->name);

        Notification::assertSentTo($this->acmeOwner, ClientInvite::class);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/app')->assertRedirect('/app/login');
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/app/login')->assertOk();
        $this->get('/admin/login')->assertOk();
    }

    public function test_a_client_user_cannot_open_the_admin_panel(): void
    {
        $this->actingAs($this->acmeOwner)->get('/admin')->assertForbidden();
        $this->actingAs($this->acmeOwner)->get('/admin/clients')->assertForbidden();
    }

    public function test_the_super_admin_sees_every_client(): void
    {
        $this->actingAs($this->admin)->get('/admin/clients')
            ->assertOk()
            ->assertSee('Acme Dental')
            ->assertSee('Globex Law');
    }

    public function test_the_super_admin_has_no_client_panel(): void
    {
        $this->actingAs($this->admin)->get('/app')->assertForbidden();
    }

    public function test_an_owner_sees_only_their_own_bot(): void
    {
        $this->actingAs($this->acmeOwner)->get('/app/bots')
            ->assertOk()
            ->assertSee('Acme Dental Assistant')
            ->assertDontSee('Globex Law Assistant');
    }

    public function test_an_owner_cannot_open_another_clients_bot(): void
    {
        $globexBot = app(CurrentClient::class)->runAs($this->globex, fn () => Bot::firstOrFail());
        $acmeBot = app(CurrentClient::class)->runAs($this->acme, fn () => Bot::firstOrFail());

        $this->actingAs($this->acmeOwner)->get("/app/bots/{$acmeBot->id}/edit")
            ->assertOk()
            ->assertSee($acmeBot->public_key);

        $this->actingAs($this->acmeOwner)->get("/app/bots/{$globexBot->id}/edit")->assertNotFound();
    }

    public function test_a_member_can_view_bots_but_not_edit_or_manage_the_team(): void
    {
        $member = $this->makeUser($this->acme, Role::Member, 'staff@acme.test');
        $acmeBot = app(CurrentClient::class)->runAs($this->acme, fn () => Bot::firstOrFail());

        $this->actingAs($member)->get('/app/bots')->assertOk()->assertSee('Acme Dental Assistant');
        $this->actingAs($member)->get("/app/bots/{$acmeBot->id}/edit")->assertForbidden();
        $this->actingAs($member)->get('/app/team')->assertForbidden();
    }

    public function test_users_of_a_suspended_client_are_locked_out(): void
    {
        $this->acme->update(['status' => 'suspended']);

        $this->actingAs($this->acmeOwner->fresh())->get('/app')->assertForbidden();
    }

    public function test_the_team_page_lists_only_the_owners_own_client(): void
    {
        $this->actingAs($this->acmeOwner)->get('/app/team')
            ->assertOk()
            ->assertSee('ali@acme.test')
            ->assertDontSee('sara@globex.test')
            ->assertDontSee('admin@platform.test');
    }

    public function test_an_owner_can_invite_a_team_member_into_their_own_client(): void
    {
        $this->actingAs($this->acmeOwner);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        Livewire::test(CreateTeamMember::class)
            ->fillForm(['name' => 'New Staff', 'email' => 'new@acme.test', 'role' => 'member'])
            ->call('create')
            ->assertHasNoFormErrors();

        $member = User::where('email', 'new@acme.test')->firstOrFail();

        $this->assertSame($this->acme->id, $member->client_id);
        $this->assertSame(Role::Member, $member->role);
        Notification::assertSentTo($member, ClientInvite::class);
    }

    public function test_the_admin_can_create_a_client_from_the_panel(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => 'Initech Clinic',
                'slug' => 'initech',
                'status' => 'active',
                'timezone' => 'Asia/Karachi',
                'monthly_message_limit' => 500,
                'owner_name' => 'Peter',
                'owner_email' => 'peter@initech.test',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $client = Client::where('slug', 'initech')->firstOrFail();
        $owner = User::where('email', 'peter@initech.test')->firstOrFail();

        $this->assertSame(500, $client->monthly_message_limit);
        $this->assertSame('Asia/Karachi', $client->timezone);
        $this->assertSame($client->id, $owner->client_id);
        Notification::assertSentTo($owner, ClientInvite::class);
    }

    public function test_an_owner_email_already_in_use_is_rejected(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => 'Copycat',
                'slug' => 'copycat',
                'owner_name' => 'Ali',
                'owner_email' => 'ali@acme.test',
            ])
            ->call('create')
            ->assertHasFormErrors(['owner_email']);

        $this->assertNull(Client::where('slug', 'copycat')->first());
    }

    public function test_the_invite_link_opens_the_set_password_page(): void
    {
        $invite = null;

        Notification::assertSentTo($this->acmeOwner, ClientInvite::class, function (ClientInvite $notification) use (&$invite) {
            $invite = $notification;

            return true;
        });

        $url = $invite->url($this->acmeOwner);

        $this->assertStringContainsString('/app/password-reset/reset', $url);
        $this->get($url)->assertOk();
    }

    public function test_an_owner_can_remove_their_own_staff_but_nobody_else(): void
    {
        $member = $this->makeUser($this->acme, Role::Member, 'staff@acme.test');

        $this->assertTrue($this->acmeOwner->can('delete', $member));
        $this->assertFalse($this->acmeOwner->can('delete', $this->acmeOwner));
        $this->assertFalse($this->acmeOwner->can('delete', $this->globexOwner));
        $this->assertFalse($member->can('delete', $this->acmeOwner));
        $this->assertFalse($this->acmeOwner->can('update', app(CurrentClient::class)->runAs($this->globex, fn () => Bot::firstOrFail())));
    }

    private function makeUser(?Client $client, Role $role, string $email): User
    {
        $user = User::factory()->make(['email' => $email]);
        $user->client_id = $client?->id;
        $user->role = $role;
        $user->save();

        return $user;
    }
}
