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

        $pendingOrRunning = ProcessingRequest::whereIn('status', $inProgress)->count();
        $received = ProcessingRequest::where('status', ProcessingRequestStatus::Received)->count();

        return [
            Stat::make('Total requests', ProcessingRequest::count())
                ->description('All time')
                ->descriptionIcon('heroicon-m-queue-list'),
            Stat::make('Pending / running', $pendingOrRunning)
                ->description(
                    $received === $pendingOrRunning && $pendingOrRunning > 0
                        ? 'Waiting for a worker (increase HORIZON_TRANSCODE_PROCESSES). Jobs can take 15–60+ min per long video.'
                        : 'In progress (download → faststart → HLS → upload). Each job can take 15–60+ min for long videos.'
                )
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
