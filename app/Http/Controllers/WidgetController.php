<?php

namespace App\Http\Controllers;

use App\Chat\ChatResponder;
use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WidgetController extends Controller
{
    /** What the widget needs to draw itself. */
    public function config(Request $request): JsonResponse
    {
        $bot = $this->bot($request);

        return response()->json([
            'active' => $this->isLive($bot),
            'name' => $bot->name,
            'greeting' => $bot->greeting,
            'color' => $bot->primary_color,
            'position' => $bot->position,
        ]);
    }

    /** Earlier messages of a conversation, so a page reload keeps the chat. */
    public function messages(Request $request, string $publicKey, string $conversation): JsonResponse
    {
        $data = $request->validate(['visitor_id' => ['required', 'string', 'max:64']]);

        $found = Conversation::where('bot_id', $this->bot($request)->id)
            ->where('visitor_id', $data['visitor_id'])
            ->whereKey($conversation)
            ->first();

        return response()->json([
            'messages' => $found?->messages()
                ->whereIn('role', ['user', 'assistant'])
                ->oldest('id')
                ->limit(100)
                ->get(['role', 'content'])
                ->all() ?? [],
        ]);
    }

    public function chat(Request $request, ChatResponder $responder): JsonResponse|StreamedResponse
    {
        $bot = $this->bot($request);

        if (! $this->isLive($bot)) {
            return response()->json(['message' => 'This assistant is not available.'], 403);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.config('chatbot.chat.max_message_chars')],
            'visitor_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'conversation_id' => ['nullable', 'uuid'],
            'page_url' => ['nullable', 'string', 'max:2000'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        if ($this->tooManyRequests($request, $bot, $data['visitor_id'])) {
            return response()->json(['message' => 'Too many messages. Please wait a moment.'], 429);
        }

        $conversation = $this->conversation($bot, $data);

        return response()->stream(function () use ($responder, $bot, $conversation, $data) {
            $this->send('meta', ['conversation_id' => $conversation->id]);

            foreach ($responder->respond($bot, $conversation, trim($data['message'])) as [$event, $payload]) {
                $this->send($event, $payload);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no', // tells nginx not to hold the stream back
        ]);
    }

    private function bot(Request $request): Bot
    {
        return $request->attributes->get('bot');
    }

    private function isLive(Bot $bot): bool
    {
        return $bot->is_active && $bot->client->isActive();
    }

    /**
     * Continues the visitor's own conversation, or starts a new one. Another
     * visitor's conversation id is never honoured.
     *
     * @param  array<string, mixed>  $data
     */
    private function conversation(Bot $bot, array $data): Conversation
    {
        $timezone = in_array($data['timezone'] ?? null, timezone_identifiers_list(), true) ? $data['timezone'] : null;

        $conversation = isset($data['conversation_id'])
            ? Conversation::where('bot_id', $bot->id)
                ->where('visitor_id', $data['visitor_id'])
                ->whereKey($data['conversation_id'])
                ->first()
            : null;

        if ($conversation !== null) {
            if ($timezone && $conversation->visitor_timezone !== $timezone) {
                $conversation->update(['visitor_timezone' => $timezone]);
            }

            return $conversation;
        }

        return Conversation::create([
            'bot_id' => $bot->id,
            'visitor_id' => $data['visitor_id'],
            'page_url' => $data['page_url'] ?? null,
            'visitor_timezone' => $timezone,
        ]);
    }

    private function tooManyRequests(Request $request, Bot $bot, string $visitorId): bool
    {
        $limits = config('chatbot.chat.rate_limit');

        $keys = [
            ["widget:bot:{$bot->id}", $limits['per_bot_per_minute']],
            ["widget:ip:{$bot->id}:".$request->ip(), $limits['per_visitor_per_minute']],
            ["widget:visitor:{$bot->id}:{$visitorId}", $limits['per_visitor_per_minute']],
        ];

        foreach ($keys as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return true;
            }
        }

        foreach ($keys as [$key]) {
            RateLimiter::hit($key, 60);
        }

        return false;
    }

    /** @param  array<string, mixed>  $data */
    private function send(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
