<?php

namespace App\Chat\Tools;

use App\Booking\BookingException;
use App\Booking\CalCom;
use App\Models\Booking;
use App\Models\Bot;
use App\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

class BookAppointment implements Tool
{
    public function __construct(private Bot $bot, private Conversation $conversation, private CalCom $calCom)
    {
    }

    public function name(): string
    {
        return 'book_appointment';
    }

    public function description(): string
    {
        return 'Book an appointment in the calendar. Only call this after the visitor chose one exact time from the check available slots tool and gave their name and email.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'start' => $schema->string()->description('The exact "start" value of the chosen slot, as returned by the slots tool.')->required(),
            'name' => $schema->string()->description('The visitor\'s full name.')->required(),
            'email' => $schema->string()->description('The visitor\'s email address.')->required(),
            'phone' => $schema->string()->description('The visitor\'s phone number, if given.'),
            'notes' => $schema->string()->description('One short line on the reason for the appointment, if given.'),
        ];
    }

    public function handle(Request $request): string
    {
        $connection = $this->bot->calendarConnection;

        if ($connection === null) {
            return 'Online booking is not set up. Offer to take contact details instead.';
        }

        $validator = Validator::make($request->all(), [
            'start' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return 'Not booked: '.$validator->errors()->first().' Ask the visitor to correct it.';
        }

        $data = $validator->validated();

        try {
            $start = CarbonImmutable::parse($data['start'])->utc();
        } catch (Throwable) {
            return 'Not booked: the start time is not valid. Check the free times again.';
        }

        // The model may call the tool twice for the same slot; book only once.
        $existing = Booking::where('conversation_id', $this->conversation->id)
            ->where('starts_at', $start)
            ->where('status', 'confirmed')
            ->first();

        if ($existing !== null) {
            return 'This appointment is already booked.';
        }

        $timezone = CheckAvailableSlots::timezone($this->conversation, $connection->timezone);
        $local = $start->setTimezone($timezone);

        try {
            // Never trust the time the model passed in: confirm it is still free.
            $free = $this->calCom->slots($connection, $local->toDateString(), $local->toDateString(), $timezone);

            if (! collect($free)->contains(fn (CarbonImmutable $slot) => $slot->equalTo($start))) {
                return 'Not booked: that time is no longer free. Check the free times again and offer another.';
            }

            $result = $this->calCom->book($connection, $start, [
                'name' => $data['name'],
                'email' => $data['email'],
                'timeZone' => $timezone,
            ], $data['notes'] ?? null);
        } catch (BookingException $e) {
            return 'Not booked: '.$e->getMessage().' Apologise and offer to take contact details instead.';
        }

        $lead = SaveContactDetails::store($this->bot, $this->conversation, [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        Booking::create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'lead_id' => $lead->id,
            'provider_booking_uid' => $result['uid'] ?? null,
            'starts_at' => $start,
            'status' => 'confirmed',
            'raw' => $result,
        ]);

        return "Booked for {$local->format('l j F Y')} at {$local->format('H:i')} ({$timezone}). A confirmation email was sent to {$data['email']}.";
    }
}
