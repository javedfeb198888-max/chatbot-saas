<?php

namespace App\Filament\App\Resources\Bookings;

use App\Filament\App\Resources\Bookings\Pages\ListBookings;
use App\Models\Booking;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Appointments the bot booked. The calendar itself (Cal.com, and through it
 * Google or Outlook) stays the source of truth for changes and cancellations.
 */
class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $slug = 'bookings';

    protected static string|UnitEnum|null $navigationGroup = 'Inbox';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['lead', 'bot.calendarConnection']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Appointment')
                    ->dateTime('D j M Y, H:i')
                    ->timezone(fn (Booking $record) => static::timezoneFor($record))
                    ->description(fn (Booking $record) => static::timezoneFor($record))
                    ->sortable(),
                TextColumn::make('lead.name')->label('Name')->placeholder('Unknown'),
                TextColumn::make('lead.email')->label('Email')->copyable()->placeholder('Unknown'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => $state === 'confirmed' ? 'success' : 'gray'),
                TextColumn::make('created_at')->label('Booked')->since(),
            ]);
    }

    private static function timezoneFor(Booking $record): string
    {
        return $record->bot?->calendarConnection?->timezone ?? 'UTC';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookings::route('/'),
        ];
    }
}
