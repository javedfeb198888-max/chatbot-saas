<?php

namespace App\Filament\App\Resources\Bots\Pages;

use App\Booking\BookingException;
use App\Booking\CalCom;
use App\Filament\App\Resources\Bots\BotResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditBot extends EditRecord
{
    protected static string $resource = BotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testCalendar')
                ->label('Test calendar')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->action(function (CalCom $calCom) {
                    $connection = $this->getRecord()->calendarConnection()->first();

                    if ($connection === null) {
                        Notification::make()->title('Save your Cal.com details first.')->warning()->send();

                        return;
                    }

                    try {
                        $slots = $calCom->slots(
                            $connection,
                            now($connection->timezone)->toDateString(),
                            now($connection->timezone)->addDays(6)->toDateString(),
                            $connection->timezone,
                        );
                    } catch (BookingException $e) {
                        Notification::make()->title('Calendar test failed')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title(count($slots) > 0 ? 'Calendar connected' : 'Connected, but no free times')
                        ->body(count($slots).' free times in the next 7 days.')
                        ->{count($slots) > 0 ? 'success' : 'warning'}()
                        ->send();
                }),
        ];
    }
}
