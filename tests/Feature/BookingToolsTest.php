<?php

namespace Tests\Feature;

use App\Actions\CreateClientWithOwner;
use App\Chat\Tools\BookAppointment;
use App\Chat\Tools\CheckAvailableSlots;
use App\Chat\Tools\SaveContactDetails;
use App\Models\Booking;
use App\Models\Bot;
use App\Models\CalendarConnection;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Lead;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class BookingToolsTest extends TestCase
{
    use RefreshDatabase;

    private Client $acme;

    private Bot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-11-12 08:00:00');

        $this->acme = app(CreateClientWithOwner::class)->handle(['name' => 'Acme Dental', 'slug' => 'acme'], 'Ali', 'ali@acme.test');

        app(CurrentClient::class)->set($this->acme);

        $this->bot = Bot::firstOrFail();
        $this->conversation = Conversation::create(['bot_id' => $this->bot->id, 'visitor_id' => 'v1', 'visitor_timezone' => 'Asia/Karachi']);
    }

    private function connectCalendar(): void
    {
        CalendarConnection::create([
            'bot_id' => $this->bot->id,
            'cal_username' => 'acme-dental',
            'event_type_slug' => 'consultation',
            'timezone' => 'Europe/London',
        ]);

        $this->bot->refresh();
    }

    private function fakeCalCom(array $slots = null, int $bookingStatus = 201): void
    {
        Http::fake([
            'api.cal.com/v2/slots*' => Http::response(['status' => 'success', 'data' => $slots ?? [
                '2026-11-13' => [
                    ['start' => '2026-11-13T14:00:00.000+05:00'],
                    ['start' => '2026-11-13T14:30:00.000+05:00'],
                ],
            ]]),
            'api.cal.com/v2/bookings' => Http::response(
                $bookingStatus < 300 ? ['status' => 'success', 'data' => ['uid' => 'cal_uid_123', 'status' => 'accepted']] : ['status' => 'error'],
                $bookingStatus,
            ),
        ]);
    }

    private function tool(string $class): object
    {
        return app()->make($class, ['bot' => $this->bot, 'conversation' => $this->conversation]);
    }

    public function test_slots_are_fetched_without_an_api_key_and_shown_in_the_visitors_time(): void
    {
        $this->connectCalendar();
        $this->fakeCalCom();

        $result = $this->tool(CheckAvailableSlots::class)->handle(new Request(['date' => '2026-11-13']));

        $this->assertStringContainsString('Asia/Karachi', $result);
        $this->assertStringContainsString('"start":"2026-11-13T09:00:00Z"', $result);
        $this->assertStringContainsString('Fri 13 Nov, 14:00', $result);
        $this->assertStringContainsString('Fri 13 Nov, 14:30', $result);

        Http::assertSent(function (HttpRequest $request) {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://api.cal.com/v2/slots')
                && $request['username'] === 'acme-dental'
                && $request['eventTypeSlug'] === 'consultation'
                && $request['start'] === '2026-11-13'
                && $request['end'] === '2026-11-13'
                && $request['timeZone'] === 'Asia/Karachi'
                && $request->header('cal-api-version') === ['2024-09-04']
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_slots_use_the_calendar_time_zone_when_the_visitors_is_unknown(): void
    {
        $this->connectCalendar();
        $this->fakeCalCom();
        $this->conversation->update(['visitor_timezone' => 'Not/AZone']);

        $result = $this->tool(CheckAvailableSlots::class)->handle(new Request(['date' => '2026-11-13']));

        $this->assertStringContainsString('Europe/London', $result);
        $this->assertStringContainsString('Fri 13 Nov, 09:00', $result);
    }

    public function test_a_range_is_capped_and_past_dates_start_from_today(): void
    {
        $this->connectCalendar();
        $this->fakeCalCom([]);

        $result = $this->tool(CheckAvailableSlots::class)->handle(new Request(['date' => '2020-01-01', 'days' => 30]));

        $this->assertStringContainsString('No free times', $result);
        Http::assertSent(fn (HttpRequest $request) => $request['start'] === '2026-11-12' && $request['end'] === '2026-11-18');
    }

    public function test_without_a_calendar_the_tools_say_so_and_call_nothing(): void
    {
        Http::fake();

        $this->assertStringContainsString('not set up', $this->tool(CheckAvailableSlots::class)->handle(new Request(['date' => '2026-11-13'])));
        $this->assertStringContainsString('not set up', $this->tool(BookAppointment::class)->handle(new Request([
            'start' => '2026-11-13T09:00:00Z', 'name' => 'Sana', 'email' => 'sana@example.test',
        ])));

        Http::assertNothingSent();
    }

    public function test_an_appointment_is_booked_and_recorded(): void
    {
        $this->connectCalendar();
        $this->fakeCalCom();

        $result = $this->tool(BookAppointment::class)->handle(new Request([
            'start' => '2026-11-13T09:00:00Z',
            'name' => 'Sana Khan',
            'email' => 'sana@example.test',
            'phone' => '0300 1234567',
            'notes' => 'Tooth pain',
        ]));

        $this->assertStringContainsString('Booked for Friday 13 November 2026 at 14:00 (Asia/Karachi)', $result);

        Http::assertSent(function (HttpRequest $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.cal.com/v2/bookings'
                && $request['username'] === 'acme-dental'
                && $request['eventTypeSlug'] === 'consultation'
                && $request['start'] === '2026-11-13T09:00:00Z'
                && $request['attendee'] === ['name' => 'Sana Khan', 'email' => 'sana@example.test', 'timeZone' => 'Asia/Karachi']
                && $request->header('cal-api-version') === ['2024-08-13'];
        });

        $booking = Booking::with('lead')->sole();

        $this->assertSame($this->acme->id, $booking->client_id);
        $this->assertSame($this->conversation->id, $booking->conversation_id);
        $this->assertSame('cal_uid_123', $booking->provider_booking_uid);
        $this->assertSame('2026-11-13 09:00:00', $booking->starts_at->utc()->toDateTimeString());
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('Sana Khan', $booking->lead->name);
        $this->assertSame('sana@example.test', $booking->lead->email);
        $this->assertSame('0300 1234567', $booking->lead->phone);
    }

    public function test_the_same_slot_is_never_booked_twice_in_one_conversation(): void
    {
        $this->connectCalendar();
        $this->fakeCalCom();

        $args = ['start' => '2026-11-13T09:00:00Z', 'name' => 'Sana', 'email' => 'sana@example.test'];

        $this->tool(BookAppointment::class)->handle(new Request($args));
        $second = $this->tool(BookAppointment::class)->handle(new Request($args));

        $this->assertStringContainsString('already booked', $second);
        $this->assertSame(1, Booking::count());
        Http::assertSentCount(2); // one slots check + one booking, nothing for the repeat
    }

    public function test_a_time_that_is_no_longer_free_is_not_booked(): void
    {
        $this->connectCalendar();
        $this->fakeCalCom();

        $result = $this->tool(BookAppointment::class)->handle(new Request([
            'start' => '2026-11-13T11:00:00Z', 'name' => 'Sana', 'email' => 'sana@example.test',
        ]));

        $this->assertStringContainsString('no longer free', $result);
        $this->assertSame(0, Booking::count());
        Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'POST');
    }

    public function test_a_bad_email_or_time_is_rejected_before_calling_the_calendar(): void
    {
        $this->connectCalendar();
        Http::fake();

        $tool = $this->tool(BookAppointment::class);

        $this->assertStringContainsString('Not booked', $tool->handle(new Request(['start' => '2026-11-13T09:00:00Z', 'name' => 'Sana', 'email' => 'not-an-email'])));
        $this->assertStringContainsString('Not booked', $tool->handle(new Request(['start' => 'tomorrow-ish at teatime!!', 'name' => 'Sana', 'email' => 'sana@example.test'])));
        $this->assertStringContainsString('Not booked', $tool->handle(new Request(['start' => '2026-11-13T09:00:00Z', 'email' => 'sana@example.test'])));

        Http::assertNothingSent();
        $this->assertSame(0, Booking::count());
    }

    public function test_a_calendar_error_books_nothing_and_tells_the_bot(): void
    {
        $this->connectCalendar();
        $this->fakeCalCom(bookingStatus: 500);

        $result = $this->tool(BookAppointment::class)->handle(new Request([
            'start' => '2026-11-13T09:00:00Z', 'name' => 'Sana', 'email' => 'sana@example.test',
        ]));

        $this->assertStringContainsString('Not booked', $result);
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Lead::count());
    }

    public function test_an_unknown_calendar_gives_a_clear_message(): void
    {
        $this->connectCalendar();
        Http::fake(['api.cal.com/*' => Http::response(['status' => 'error'], 404)]);

        $result = $this->tool(CheckAvailableSlots::class)->handle(new Request(['date' => '2026-11-13']));

        $this->assertStringContainsString('was not found', $result);
    }

    public function test_contact_details_are_saved_once_per_conversation_and_updated(): void
    {
        $tool = $this->tool(SaveContactDetails::class);

        $this->assertSame('Contact details saved.', $tool->handle(new Request(['name' => 'Sana', 'phone' => '0300 1234567'])));
        $this->assertSame('Contact details saved.', $tool->handle(new Request(['name' => 'Sana Khan', 'email' => 'sana@example.test', 'notes' => 'Wants a call back'])));

        $lead = Lead::sole();

        $this->assertSame($this->acme->id, $lead->client_id);
        $this->assertSame($this->conversation->id, $lead->conversation_id);
        $this->assertSame('Sana Khan', $lead->name);
        $this->assertSame('sana@example.test', $lead->email);
        $this->assertSame('0300 1234567', $lead->phone); // kept from the first call
        $this->assertSame('Wants a call back', $lead->notes);
    }

    public function test_contact_details_need_a_name_and_a_way_to_reach_the_visitor(): void
    {
        $tool = $this->tool(SaveContactDetails::class);

        $this->assertStringContainsString('Not saved', $tool->handle(new Request(['name' => 'Sana'])));
        $this->assertStringContainsString('Not saved', $tool->handle(new Request(['email' => 'sana@example.test'])));
        $this->assertStringContainsString('Not saved', $tool->handle(new Request(['name' => 'Sana', 'email' => 'nope'])));
        $this->assertSame(0, Lead::count());
    }

    public function test_tool_names_and_schemas_are_what_the_prompt_refers_to(): void
    {
        $this->assertSame('check_available_slots', $this->tool(CheckAvailableSlots::class)->name());
        $this->assertSame('book_appointment', $this->tool(BookAppointment::class)->name());
        $this->assertSame('save_contact_details', $this->tool(SaveContactDetails::class)->name());
    }
}
