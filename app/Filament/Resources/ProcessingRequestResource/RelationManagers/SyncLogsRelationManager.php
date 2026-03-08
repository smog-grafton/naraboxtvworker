<?php

namespace App\Filament\Resources\ProcessingRequestResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SyncLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'syncLogs';

    protected static ?string $title = 'Sync logs';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('target'),
                TextColumn::make('action'),
                TextColumn::make('response_code'),
                TextColumn::make('error_message')->limit(50),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->defaultSort('id');
    }
}
