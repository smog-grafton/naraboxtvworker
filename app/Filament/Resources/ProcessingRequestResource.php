<?php

namespace App\Filament\Resources;

use App\Enums\ProcessingRequestStatus;
use App\Filament\Resources\ProcessingRequestResource\Pages;
use App\Jobs\Transcode\ProcessMediaPipelineJob;
use App\Models\ProcessingRequest;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProcessingRequestResource extends Resource
{
    protected static ?string $model = ProcessingRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Processing';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['attempts', 'callbackLogs', 'syncLogs']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('external_id')
                    ->label('External ID')
                    ->searchable()
                    ->copyable()
                    ->limit(16),
                TextColumn::make('status')
                    ->formatStateUsing(fn ($state) => $state instanceof \BackedEnum ? $state->value : $state)
                    ->badge()
                    ->color(fn ($state): string => match (is_object($state) ? $state->value : $state) {
                        'completed' => 'success',
                        'failed', 'cancelled' => 'danger',
                        'received', 'downloading', 'downloaded', 'probing', 'transcoding', 'uploading', 'syncing' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('cdn_asset_id')->label('CDN Asset')->limit(12)->toggleable(),
                TextColumn::make('cdn_source_id')->label('CDN Source')->toggleable(),
                TextColumn::make('source_url')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('failure_reason')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('attempts_count')->label('Attempts')->suffix(' attempts'),
                TextColumn::make('callback_logs_count')->label('Callbacks')->suffix(' logs'),
                TextColumn::make('received_at')->dateTime()->sortable(),
                TextColumn::make('completed_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(
                        array_column(ProcessingRequestStatus::cases(), 'value'),
                        array_column(ProcessingRequestStatus::cases(), 'value')
                    )),
            ])
            ->actions([
                \Filament\Tables\Actions\ViewAction::make(),
                \Filament\Tables\Actions\Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (ProcessingRequest $record): bool => in_array($record->status, [ProcessingRequestStatus::Failed, ProcessingRequestStatus::Cancelled], true))
                    ->action(function (ProcessingRequest $record): void {
                        $record->update([
                            'status' => ProcessingRequestStatus::Received,
                            'failure_reason' => null,
                            'started_at' => null,
                            'completed_at' => null,
                        ]);
                        ProcessMediaPipelineJob::dispatch($record->fresh());
                        \Filament\Notifications\Notification::make()->title('Retry dispatched')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            ProcessingRequestResource\RelationManagers\AttemptsRelationManager::class,
            ProcessingRequestResource\RelationManagers\CallbackLogsRelationManager::class,
            ProcessingRequestResource\RelationManagers\SyncLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProcessingRequests::route('/'),
            'view' => Pages\ViewProcessingRequest::route('/{record}'),
        ];
    }
}
