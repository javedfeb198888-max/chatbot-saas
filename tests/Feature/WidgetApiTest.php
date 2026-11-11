<?php

namespace Tests\Feature;

use App\Actions\CreateClientWithOwner;
use App\Actions\Training\AddSource;
use App\Chat\ChatResponder;
use App\Chat\WidgetAgent;
use App\Models\Bot;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\UsageDaily;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Embeddings;
use RuntimeException;
use Tests\TestCase;

class WidgetApiTest extends TestCase
{
    use RefreshDatabase;

    private Client $acme;

    private Client $globex;

    private Bot $bot;

    private Bot $globexBot;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Embeddings::fake();
        RateLimiter::clear('x');
        cache()->flush();

        $create = app(CreateClientWithOwner::class);
        $this->acme = $create->handle(['name' => 'Acme Dental', 'slug' => 'acme'], 'Ali', 'ali@acme.test');
        $this->globex = $create->handle(['name' => 'Globex Law', 'slug' => 'globex'], 'Sara', 'sara@globex.test');

        $context = app(CurrentClient::class);

        $this->bot = $context->runAs($this->acme, function () {
            $bot = Bot::firstOrFail();
            app(AddSource::class)->handle($bot, ['type' => 'text', 'title' => 'Hours', 'content' => 'Acme Dental is open Monday to Friday from nine to five.']);

            return $bot;
        });

        $this->globexBot = $context->runAs($this->globex, function () {
            $bot = Bot::firstOrFail();
            app(AddSource::class)->handle($bot, ['type' => 'text', 'title' => 'Fees', 'content' => 'Globex confidential fee is nine hundred dollars per hour.']);

            return $bot;
        });

        // Each test request must earn its own client context, like a real request.
        $context->restrict();
    }

    private function chat(array $body = [], ?Bot $bot = null, array $headers = []): TestResponse
    {
        $this->app->forgetScopedInstances();
        app(CurrentClient::class)->restrict();

        return $this->postJson('/api/widget/'.($bot ?? $this->bot)->public_key.'/chat', $body + [
            'message' => 'When are you open?',
            'visitor_id' => 'visitor_1',
        ], $headers);
    }

    /** @return array<int, array{event: string, data: array}> */
    private function events(TestResponse $response): array
    {
        return collect(preg_split("/\n\n/", trim($response->streamedContent())))
            ->map(function (string $block) {
                preg_match('/^event: (.+)$/m', $block, $event);
                preg_match('/^data: (.+)$/m', $block, $data);

                return ['event' => $event[1], 'data' => json_decode($data[1], true)];
            })
            ->all();
    }

    private function inContext(Client $client, callable $callback): mixed
    {
        return app(CurrentClient::class)->runAs($client, $callback);
    }

    public function test_config_returns_what_the_widget_needs(): void
    {
        $this->getJson("/api/widget/{$this->bot->public_key}/config")
            ->assertOk()
            ->assertExactJson([
                'active' => true,
                'name' => 'Acme Dental Assistant',
                'greeting' => 'Hi! How can I help you today?',
                'color' => '#2563eb',
                'position' => 'right',
            ]);
    }

    public function test_an_unknown_bot_key_is_not_found(): void
    {
        $this->getJson('/api/widget/bot_doesnotexist/config')->assertNotFound();
        $this->chat([], null, [])->assertOk(); // sanity: the real key works
    }

    public function test_a_chat_reply_is_streamed_and_saved(): void
    {
        WidgetAgent::fake(['We are open Monday to Friday, nine to five.']);

        $response = $this->chat(['page_url' => 'https://acme.test/contact', 'timezone' => 'Asia/Karachi'])->assertOk();

        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));

        $events = $this->events($response);

        $this->assertSame('meta', $events[0]['event']);
        $this->assertSame('done', end($events)['event']);

        $text = collect($events)->where('event', 'delta')->map(fn ($e) => $e['data']['text'])->implode('');
        $this->assertSame('We are open Monday to Friday, nine to five.', $text);

        $conversation = $this->inContext($this->acme, fn () => Conversation::with('messages')->sole());

        $this->assertSame($events[0]['data']['conversation_id'], $conversation->id);
        $this->assertSame($this->acme->id, $conversation->client_id);
        $this->assertSame('visitor_1', $conversation->visitor_id);
        $this->assertSame('https://acme.test/contact', $conversation->page_url);
        $this->assertSame('Asia/Karachi', $conversation->visitor_timezone);
        $this->assertSame(['user', 'assistant'], $conversation->messages->pluck('role')->all());
        $this->assertSame('When are you open?', $conversation->messages[0]->content);
        $this->assertSame('We are open Monday to Friday, nine to five.', $conversation->messages[1]->content);

        $usage = UsageDaily::withoutGlobalScopes()->where('client_id', $this->acme->id)->sole();
        $this->assertSame(1, $usage->messages);
        $this->assertSame(0, UsageDaily::withoutGlobalScopes()->where('client_id', $this->globex->id)->count());
    }

    public function test_the_bot_is_given_only_its_own_clients_training_content(): void
    {
        WidgetAgent::fake(['ok']);

        $this->chat()->assertOk()->streamedContent();

        WidgetAgent::assertPrompted(function ($prompt) {
            $instructions = $prompt->agent->instructions();

            return str_contains($instructions, 'open Monday to Friday from nine to five')
                && str_contains($instructions, 'Acme Dental')
                && ! str_contains($instructions, 'Globex')
                && ! str_contains($instructions, 'nine hundred dollars');
        });
    }

    public function test_a_conversation_continues_and_sends_earlier_messages_to_the_model(): void
    {
        WidgetAgent::fake(['First answer.', 'Second answer.']);

        $id = $this->events($this->chat(['message' => 'First question']))[0]['data']['conversation_id'];
        $again = $this->events($this->chat(['message' => 'Second question', 'conversation_id' => $id]))[0]['data']['conversation_id'];

        $this->assertSame($id, $again);
        $this->assertSame(4, $this->inContext($this->acme, fn () => Message::count()));

        WidgetAgent::assertPrompted(function ($prompt) {
            $history = collect($prompt->agent->messages())->map(fn ($m) => $m->content)->all();

            return $prompt->prompt === 'Second question' && $history === ['First question', 'First answer.'];
        });
    }

    public function test_another_visitor_cannot_take_over_a_conversation(): void
    {
        WidgetAgent::fake(['a', 'b']);

        $id = $this->events($this->chat())[0]['data']['conversation_id'];
        $other = $this->events($this->chat(['visitor_id' => 'someone_else', 'conversation_id' => $id]))[0]['data']['conversation_id'];

        $this->assertNotSame($id, $other);

        $this->app->forgetScopedInstances();
        $this->getJson("/api/widget/{$this->bot->public_key}/conversations/{$id}/messages?visitor_id=someone_else")
            ->assertOk()->assertExactJson(['messages' => []]);

        $this->app->forgetScopedInstances();
        $this->getJson("/api/widget/{$this->bot->public_key}/conversations/{$id}/messages?visitor_id=visitor_1")
            ->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('messages.0.content', 'When are you open?');
    }

    public function test_a_conversation_id_from_another_bot_is_ignored(): void
    {
        WidgetAgent::fake(['a', 'b']);

        $acmeId = $this->events($this->chat())[0]['data']['conversation_id'];
        $globexId = $this->events($this->chat(['conversation_id' => $acmeId], $this->globexBot))[0]['data']['conversation_id'];

        $this->assertNotSame($acmeId, $globexId);
        $this->assertSame(2, $this->inContext($this->acme, fn () => Message::count()));
    }

    public function test_only_allowed_websites_may_use_the_bot(): void
    {
        WidgetAgent::fake(fn () => 'ok');

        $this->inContext($this->acme, fn () => $this->bot->update(['allowed_domains' => ['acme.test', 'https://www.Shop.Example.com/']]));

        $this->chat([], null, ['Origin' => 'https://evil.test'])->assertForbidden();
        $this->chat([], null, [])->assertForbidden(); // no Origin at all
        $this->chat([], null, ['Origin' => 'https://notacme.test'])->assertForbidden();

        foreach (['https://acme.test', 'https://www.acme.test', 'https://book.acme.test', 'https://shop.example.com'] as $origin) {
            $response = $this->chat([], null, ['Origin' => $origin])->assertOk();
            $this->assertSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
            $response->streamedContent();
        }
    }

    public function test_the_browser_preflight_request_is_answered(): void
    {
        $this->call('OPTIONS', "/api/widget/{$this->bot->public_key}/chat", server: ['HTTP_ORIGIN' => 'https://anysite.test'])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://anysite.test')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    }

    public function test_bad_input_is_rejected_with_cors_headers_intact(): void
    {
        $this->chat(['message' => ''], null, ['Origin' => 'https://site.test'])
            ->assertStatus(422)
            ->assertHeader('Access-Control-Allow-Origin', 'https://site.test');

        $this->chat(['message' => str_repeat('a', 2001)])->assertStatus(422);
        $this->chat(['visitor_id' => 'bad id with spaces'])->assertStatus(422);
        $this->chat(['conversation_id' => 'not-a-uuid'])->assertStatus(422);

        WidgetAgent::assertNeverPrompted();
    }

    public function test_a_visitor_sending_too_fast_is_slowed_down(): void
    {
        WidgetAgent::fake(fn () => 'ok');
        config(['chatbot.chat.rate_limit.per_visitor_per_minute' => 2]);

        $this->chat()->assertOk()->streamedContent();
        $this->chat()->assertOk()->streamedContent();
        $this->chat()->assertStatus(429);
    }

    public function test_when_the_monthly_limit_is_used_up_the_ai_is_not_called(): void
    {
        WidgetAgent::fake(['should never be used']);

        $this->acme->update(['monthly_message_limit' => 0]);

        $events = $this->events($this->chat());

        $this->assertSame(ChatResponder::LIMIT_MESSAGE, $events[1]['data']['text']);
        $this->assertSame('done', end($events)['event']);
        WidgetAgent::assertNeverPrompted();
        $this->assertSame(0, UsageDaily::withoutGlobalScopes()->count());
    }

    public function test_an_inactive_bot_or_suspended_client_does_not_answer(): void
    {
        WidgetAgent::fake(['x']);

        $this->inContext($this->acme, fn () => $this->bot->update(['is_active' => false]));
        $this->chat()->assertForbidden();

        $this->app->forgetScopedInstances();
        $this->getJson("/api/widget/{$this->bot->public_key}/config")->assertOk()->assertJsonPath('active', false);

        $this->globex->update(['status' => 'suspended']);
        $this->chat([], $this->globexBot)->assertForbidden();

        WidgetAgent::assertNeverPrompted();
    }

    public function test_an_ai_failure_gives_the_visitor_a_polite_error(): void
    {
        WidgetAgent::fake(fn () => throw new RuntimeException('provider exploded'));

        $events = $this->events($this->chat());

        $this->assertSame('error', end($events)['event']);
        $this->assertSame(ChatResponder::ERROR_MESSAGE, end($events)['data']['message']);
        $this->assertStringNotContainsString('exploded', json_encode($events));

        $this->assertSame(['user'], $this->inContext($this->acme, fn () => Message::pluck('role')->all()));
        $this->assertSame(0, UsageDaily::withoutGlobalScopes()->count());
    }
}
