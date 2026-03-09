<?php

namespace App\Filament\Widgets;

use App\Enums\ProcessingRequestStatus;
use App\Models\ProcessingRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProcessingRequestsStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $inProgress = [
            ProcessingRequestStatus::Received,
            ProcessingRequestStatus::Downloading,
            ProcessingRequestStatus::Downloaded,
            ProcessingRequestStatus::Probing,
            ProcessingRequestStatus::Transcoding,
            ProcessingRequestStatus::Uploading,
            ProcessingRequestStatus::Syncing,
        ];

        return [
            Stat::make('Total requests', ProcessingRequest::count())
                ->description('All time')
                ->descriptionIcon('heroicon-m-queue-list'),
            Stat::make('Pending / running', ProcessingRequest::whereIn('status', $inProgress)->count())
                ->description('In progress (includes downloaded until pipeline completes)')
                ->color('warning'),
            Stat::make('Completed', ProcessingRequest::where('status', ProcessingRequestStatus::Completed)->count())
                ->description('Success')
                ->color('success'),
            Stat::make('Failed', ProcessingRequest::whereIn('status', [ProcessingRequestStatus::Failed, ProcessingRequestStatus::Cancelled])->count())
                ->description('Errors')
                ->color('danger'),
        ];
    }
}
