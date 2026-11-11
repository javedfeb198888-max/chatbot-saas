<?php

namespace App\Chat;

use App\Chat\Tools\BookAppointment;
use App\Chat\Tools\CheckAvailableSlots;
use App\Chat\Tools\SaveContactDetails;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use Generator;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\TextDelta;
use Throwable;

class ChatResponder
{
    public const LIMIT_MESSAGE = 'Sorry, this assistant is not available right now. Please contact us directly.';

    public const ERROR_MESSAGE = 'Sorry, something went wrong. Please try again in a moment.';

    public function __construct(
        private Retriever $retriever,
        private PromptBuilder $prompts,
        private UsageRecorder $usage,
    ) {
    }

    /**
     * Answers one visitor message. Yields [event, data] pairs for the stream:
     * "delta" for each piece of text, then "done", or "error".
     *
     * @return Generator<int, array{0: string, 1: array<string, mixed>}>
     */
    public function respond(Bot $bot, Conversation $conversation, string $text): Generator
    {
        $history = $this->history($conversation);

        Message::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => $text]);
        $conversation->update(['last_message_at' => now()]);

        // The client's monthly allowance is used up: answer without calling the AI.
        if ($bot->client->hasReachedMessageLimit()) {
            $this->saveReply($conversation, self::LIMIT_MESSAGE);

            yield ['delta', ['text' => self::LIMIT_MESSAGE]];
            yield ['done', []];

            return;
        }

        $model = $bot->model ?: config('chatbot.chat.model');

        try {
            $agent = new WidgetAgent(
                $this->prompts->instructions($bot, $conversation, $this->retriever->search($bot, $text)),
                $history,
                $this->tools($bot, $conversation),
            );

            $stream = $agent->stream($text, provider: config('chatbot.chat.provider'), model: $model);

            foreach ($stream as $event) {
                if ($event instanceof TextDelta && $event->delta !== '') {
                    yield ['delta', ['text' => $event->delta]];
                }
            }

            $reply = trim((string) $stream->text);
            $tokensIn = (int) ($stream->usage->inputTokens ?? 0);
            $tokensOut = (int) ($stream->usage->outputTokens ?? 0);
        } catch (Throwable $e) {
            Log::error('Chat reply failed', ['conversation_id' => $conversation->id, 'exception' => $e]);

            yield ['error', ['message' => self::ERROR_MESSAGE]];

            return;
        }

        $this->saveReply($conversation, $reply, $tokensIn, $tokensOut);
        $this->usage->record($bot->client_id, $tokensIn, $tokensOut, $model);

        yield ['done', []];
    }

    /** @return array<int, UserMessage|AssistantMessage> */
    private function history(Conversation $conversation): array
    {
        return $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->latest('id')
            ->limit((int) config('chatbot.chat.history_messages'))
            ->get()
            ->reverse()
            ->map(fn (Message $message) => $message->role === 'user'
                ? new UserMessage($message->content)
                : new AssistantMessage($message->content))
            ->values()
            ->all();
    }

    /** @return array<int, \Laravel\Ai\Contracts\Tool> */
    private function tools(Bot $bot, Conversation $conversation): array
    {
        $tools = [app()->make(SaveContactDetails::class, ['bot' => $bot, 'conversation' => $conversation])];

        if ($bot->calendarConnection !== null) {
            $tools[] = app()->make(CheckAvailableSlots::class, ['bot' => $bot, 'conversation' => $conversation]);
            $tools[] = app()->make(BookAppointment::class, ['bot' => $bot, 'conversation' => $conversation]);
        }

        return $tools;
    }

    private function saveReply(Conversation $conversation, string $reply, int $tokensIn = 0, int $tokensOut = 0): void
    {
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
        ]);

        $conversation->update(['last_message_at' => now()]);
    }
}
