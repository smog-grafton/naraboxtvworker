<?php

namespace App\Filament\Resources\ProcessingRequestResource\Pages;

use App\Enums\ProcessingRequestStatus;
use App\Filament\Resources\ProcessingRequestResource;
use App\Jobs\Transcode\ProcessMediaPipelineJob;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Actions;

class ViewProcessingRequest extends ViewRecord
{
    protected static string $resource = ProcessingRequestResource::class;

    public function getHeaderActions(): array
    {
        return [
            Actions\Action::make('retry')
                ->label('Retry')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Retry processing')
                ->modalDescription('Reset this request to received and dispatch a new pipeline job. Use after fixing the source or environment.')
                ->visible(fn (): bool => in_array($this->record->status, [ProcessingRequestStatus::Failed, ProcessingRequestStatus::Cancelled], true))
                ->action(function (): void {
                    $this->record->update([
                        'status' => ProcessingRequestStatus::Received,
                        'failure_reason' => null,
                        'started_at' => null,
                        'completed_at' => null,
                    ]);
                    ProcessMediaPipelineJob::dispatch($this->record->fresh());
                    \Filament\Notifications\Notification::make()
                        ->title('Retry dispatched')
                        ->body('The request has been re-queued for processing.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                TextEntry::make('external_id')->label('External ID')->copyable(),
                TextEntry::make('status')->badge(),
                TextEntry::make('cdn_asset_id')->label('CDN Asset ID'),
                TextEntry::make('cdn_source_id')->label('CDN Source ID'),
                TextEntry::make('source_url')->label('Source URL')->url(fn ($state) => $state)->openUrlInNewTab()->columnSpanFull(),
                TextEntry::make('failure_reason')->columnSpanFull(),
                TextEntry::make('hlsArtifact.status')->label('HLS Artifact Status')->badge(),
                TextEntry::make('hlsArtifact.quality_status')->label('Quality Status'),
                TextEntry::make('hlsArtifact.download_expires_at')->label('Artifact expires')->dateTime(),
                TextEntry::make('hlsArtifact.zip_size_bytes')->label('ZIP size')->formatStateUsing(fn ($state) => $state !== null ? number_format($state / 1024 / 1024, 2) . ' MB' : '—'),
                TextEntry::make('received_at')->dateTime(),
                TextEntry::make('started_at')->dateTime(),
                TextEntry::make('completed_at')->dateTime(),
            ])->columns(2);
    }
}
