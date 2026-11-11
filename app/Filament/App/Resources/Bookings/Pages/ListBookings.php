<?php

namespace App\Filament\App\Resources\Bookings\Pages;

use App\Filament\App\Resources\Bookings\BookingResource;
use Filament\Resources\Pages\ListRecords;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;
}
