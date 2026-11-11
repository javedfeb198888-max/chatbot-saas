<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Client;
use App\Models\UsageDaily;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected function getHeading(): ?string
    {
        return 'This month, all clients';
    }

    protected function getStats(): array
    {
        $usage = UsageDaily::where('day', '>=', now()->startOfMonth()->toDateString())
            ->selectRaw('coalesce(sum(messages), 0) as messages, coalesce(sum(cost_usd), 0) as cost')
            ->first();

        return [
            Stat::make('Active clients', Client::where('status', 'active')->count())
                ->description(Client::where('status', 'suspended')->count().' suspended'),
            Stat::make('Messages answered', number_format((int) $usage->messages)),
            Stat::make('AI cost (estimate)', '$'.number_format((float) $usage->cost, 2))
                ->description('From token counts and the prices in config/chatbot.php'),
        ];
    }
}
