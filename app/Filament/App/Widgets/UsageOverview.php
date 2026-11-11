<?php

namespace App\Filament\App\Widgets;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Lead;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class UsageOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected function getHeading(): ?string
    {
        return 'This month';
    }

    protected function getStats(): array
    {
        $client = auth()->user()->client;
        $since = now()->startOfMonth();

        $used = $client->messagesThisMonth();
        $limit = $client->monthly_message_limit;

        return [
            Stat::make('Messages answered', number_format($used).' of '.number_format($limit))
                ->description($used >= $limit ? 'Limit reached: the bot has stopped answering' : number_format(max(0, $limit - $used)).' left this month')
                ->color($used >= $limit ? 'danger' : ($used >= $limit * 0.8 ? 'warning' : 'success')),
            Stat::make('Conversations', number_format(Conversation::where('created_at', '>=', $since)->count())),
            Stat::make('Leads', number_format(Lead::where('created_at', '>=', $since)->count())),
            Stat::make('Bookings', number_format(Booking::where('created_at', '>=', $since)->count())),
        ];
    }
}
