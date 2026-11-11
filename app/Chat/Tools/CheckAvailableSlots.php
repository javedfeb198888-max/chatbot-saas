<?php

namespace App\Chat\Tools;

use App\Booking\BookingException;
use App\Booking\CalCom;
use App\Models\Bot;
use App\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

class CheckAvailableSlots implements Tool
{
    public function __construct(private Bot $bot, private Conversation $conversation, private CalCom $calCom)
    {
    }

    public function name(): string
    {
        return 'check_available_slots';
    }

    public function description(): string
    {
        return 'Look up free appointment times in the calendar for a date or a short range of dates.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('First day to check, as YYYY-MM-DD.')->required(),
            'days' => $schema->integer()->description('How many days to check from that date, 1 to 7. Default 1.'),
        ];
    }

    public function handle(Request $request): string
    {
        $connection = $this->bot->calendarConnection;

        if ($connection === null) {
            return 'Online booking is not set up. Offer to take contact details instead.';
        }

        $timezone = static::timezone($this->conversation, $connection->timezone);

        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $request['date'], $timezone);
        } catch (Throwable) {
            return 'The date must be in YYYY-MM-DD format.';
        }

        $today = CarbonImmutable::now($timezone)->startOfDay();

        if ($from->lt($today)) {
            $from = $today;
        }

        $days = max(1, min(7, (int) ($request['days'] ?? 1)));
        $to = $from->addDays($days - 1);

        try {
            $slots = $this->calCom->slots($connection, $from->toDateString(), $to->toDateString(), $timezone);
        } catch (BookingException $e) {
            return $e->getMessage().' Offer to take contact details instead.';
        }

        $slots = array_values(array_filter($slots, fn (CarbonImmutable $slot) => $slot->isFuture()));

        if ($slots === []) {
            return "No free times between {$from->toDateString()} and {$to->toDateString()}. Ask the visitor for another day.";
        }

        // At most eight times per day keeps the reply short for the model.
        $lines = collect($slots)
            ->groupBy(fn (CarbonImmutable $slot) => $slot->setTimezone($timezone)->toDateString())
            ->map(fn ($day) => $day->take(8)->map(fn (CarbonImmutable $slot) => [
                'start' => $slot->format('Y-m-d\TH:i:s\Z'),
                'local_time' => $slot->setTimezone($timezone)->format('D j M, H:i'),
            ])->values())
            ->flatten(1)
            ->values();

        return "Free times ({$timezone}). Pass the exact \"start\" value to the book appointment tool:\n"
            .$lines->toJson(JSON_UNESCAPED_SLASHES);
    }

    public static function timezone(Conversation $conversation, string $fallback): string
    {
        $timezone = $conversation->visitor_timezone;

        return $timezone && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : $fallback;
    }
}
