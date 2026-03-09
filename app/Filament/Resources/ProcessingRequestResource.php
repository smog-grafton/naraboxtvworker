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
        return parent::getEloquentQuery()->withCount(['attempts', 'callbackLogs', 'syncLogs'])->with('hlsArtifact');
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
                TextColumn::make('hlsArtifact.status')->label('Artifact')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hlsArtifact.download_expires_at')->label('Artifact expires')->dateTime()->toggleable(isToggledHiddenByDefault: true),
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
                \Filament\Tables\Actions\Action::make('copy_artifact_url')
                    ->label('Copy artifact URL')
                    ->icon('heroicon-o-link')
                    ->visible(fn (ProcessingRequest $record): bool => $record->hlsArtifact?->download_token && $record->hlsArtifact?->status === 'artifact_ready')
                    ->action(function (ProcessingRequest $record): void {
                        $base = rtrim(config('app.url'), '/');
                        $token = $record->hlsArtifact?->download_token;
                        if (! $token) {
                            return;
                        }
                        $url = $base . '/api/v1/artifacts/' . $token;
                        \Filament\Notifications\Notification::make()
                            ->title('Artifact URL')
                            ->body($url)
                            ->success()
                            ->send();
                    }),
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
            ->headerActions([
                \Filament\Tables\Actions\Action::make('create_manual_request')
                    ->label('New manual request')
                    ->icon('heroicon-o-plus')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('source_url')
                            ->label('Source URL')
                            ->required()
                            ->url()
                            ->maxLength(2048),
                        \Filament\Forms\Components\TextInput::make('original_filename')
                            ->label('Original filename')
                            ->maxLength(512),
                    ])
                    ->action(function (array $data): void {
                        $request = ProcessingRequest::create([
                            'cdn_asset_id' => null,
                            'cdn_source_id' => null,
                            'source_url' => $data['source_url'],
                            'original_filename' => $data['original_filename'] ?? null,
                            'status' => ProcessingRequestStatus::Received,
                            'payload' => null,
                            'artifact_paths' => [],
                        ]);
                        ProcessMediaPipelineJob::dispatch($request);
                        \Filament\Notifications\Notification::make()
                            ->title('Manual request queued')
                            ->success()
                            ->send();
                    }),
                \Filament\Tables\Actions\Action::make('cleanup_artifact')
                    ->label('Cleanup artifact')
                    ->icon('heroicon-o-trash')
                    ->requiresConfirmation()
                    ->visible(fn (ProcessingRequest $record): bool => $record->hlsArtifact !== null)
                    ->action(function (ProcessingRequest $record): void {
                        $artifact = $record->hlsArtifact;
                        if (! $artifact) {
                            return;
                        }
                        if (is_string($artifact->zip_path) && is_file($artifact->zip_path)) {
                            @unlink($artifact->zip_path);
                        }
                        if (is_string($artifact->hls_dir) && is_dir($artifact->hls_dir)) {
                            $deleteDir = function (string $path) use (&$deleteDir): void {
                                $items = @scandir($path);
                                if (! is_array($items)) {
                                    return;
                                }
                                foreach ($items as $name) {
                                    if ($name === '.' || $name === '..') {
                                        continue;
                                    }
                                    $full = $path . '/' . $name;
                                    if (is_dir($full)) {
                                        $deleteDir($full);
                                    } else {
                                        @unlink($full);
                                    }
                                }
                                @rmdir($path);
                            };
                            $deleteDir($artifact->hls_dir);
                        }
                        $artifact->update([
                            'status' => 'expired',
                            'download_token' => null,
                            'download_expires_at' => now(),
                        ]);
                        \Filament\Notifications\Notification::make()
                            ->title('Artifact cleaned up')
                            ->success()
                            ->send();
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
