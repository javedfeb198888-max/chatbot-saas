<?php

namespace App\Booking;

use App\Models\CalendarConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to Cal.com. The client connects their Google or Outlook calendar
 * inside Cal.com; we only ask for free slots and create bookings.
 */
class CalCom
{
    /**
     * Free slots between two local dates (inclusive), as UTC instants.
     *
     * @return array<int, CarbonImmutable>
     */
    public function slots(CalendarConnection $connection, string $from, string $to, string $timezone): array
    {
        $response = $this->send(fn () => $this->request($connection, config('chatbot.calcom.slots_version'))
            ->get('/slots', [
                'username' => $connection->cal_username,
                'eventTypeSlug' => $connection->event_type_slug,
                'start' => $from,
                'end' => $to,
                'timeZone' => $timezone,
            ]));

        return collect($response['data'] ?? [])
            ->flatten(1)
            ->map(fn ($slot) => $slot['start'] ?? null)
            ->filter()
            ->map(fn (string $start) => CarbonImmutable::parse($start)->utc())
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array{name: string, email: string, timeZone: string}  $attendee
     * @return array<string, mixed> the booking as Cal.com returned it
     */
    public function book(CalendarConnection $connection, CarbonImmutable $start, array $attendee, ?string $notes = null): array
    {
        $body = [
            'username' => $connection->cal_username,
            'eventTypeSlug' => $connection->event_type_slug,
            'start' => $start->utc()->format('Y-m-d\TH:i:s\Z'),
            'attendee' => $attendee,
        ];

        if (filled($notes)) {
            $body['bookingFieldsResponses'] = ['notes' => $notes];
        }

        $response = $this->send(fn () => $this->request($connection, config('chatbot.calcom.bookings_version'))
            ->post('/bookings', $body));

        return $response['data'] ?? [];
    }

    private function request(CalendarConnection $connection, string $version): PendingRequest
    {
        $request = Http::baseUrl(rtrim(config('chatbot.calcom.base_url'), '/'))
            ->timeout(config('chatbot.calcom.timeout'))
            ->acceptJson()
            ->withHeaders(['cal-api-version' => $version]);

        // Only needed if a client's Cal.com plan requires a key.
        return filled($connection->api_key) ? $request->withToken($connection->api_key) : $request;
    }

    /** @return array<string, mixed> */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw new BookingException('The calendar could not be reached.');
        }

        if ($response->failed()) {
            Log::warning('Cal.com request failed', ['status' => $response->status(), 'body' => $response->json()]);

            throw new BookingException(match (true) {
                $response->status() === 404 => 'The calendar or event type was not found.',
                $response->status() === 401, $response->status() === 403 => 'The calendar refused the request.',
                default => 'The calendar returned an error.',
            });
        }

        return $response->json() ?? [];
    }
}
