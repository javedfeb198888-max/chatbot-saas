<?php

namespace App\Chat;

use Illuminate\Support\Facades\DB;

class UsageRecorder
{
    /** Adds one answered message and its tokens to today's row for the client. */
    public function record(string $clientId, int $tokensIn, int $tokensOut, string $model): void
    {
        [$inputPrice, $outputPrice] = config("chatbot.chat.prices.{$model}", config('chatbot.chat.default_price'));

        $cost = round(($tokensIn * $inputPrice + $tokensOut * $outputPrice) / 1_000_000, 4);

        DB::statement(
            'insert into usage_daily (client_id, day, messages, tokens_in, tokens_out, cost_usd)
             values (?, ?, 1, ?, ?, ?)
             on conflict (client_id, day) do update set
                messages = usage_daily.messages + 1,
                tokens_in = usage_daily.tokens_in + excluded.tokens_in,
                tokens_out = usage_daily.tokens_out + excluded.tokens_out,
                cost_usd = usage_daily.cost_usd + excluded.cost_usd',
            [$clientId, now()->toDateString(), $tokensIn, $tokensOut, $cost],
        );
    }
}
