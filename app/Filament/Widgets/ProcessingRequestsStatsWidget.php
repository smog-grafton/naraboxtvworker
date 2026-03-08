<?php

namespace App\Filament\Widgets;

use App\Models\ProcessingRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProcessingRequestsStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('Total requests', ProcessingRequest::count())
                ->description('All time')
                ->descriptionIcon('heroicon-m-queue-list'),
            Stat::make('Pending / running', ProcessingRequest::whereIn('status', [
                'received', 'downloading', 'downloaded', 'probing', 'transcoding', 'uploading', 'syncing',
            ])->count())
                ->description('In progress')
                ->color('warning'),
            Stat::make('Completed', ProcessingRequest::where('status', 'completed')->count())
                ->description('Success')
                ->color('success'),
            Stat::make('Failed', ProcessingRequest::where('status', 'failed')->count())
                ->description('Errors')
                ->color('danger'),
        ];
    }
}
