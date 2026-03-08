<?php

namespace App\Filament\Resources\ProcessingRequestResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CallbackLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'callbackLogs';

    protected static ?string $title = 'Callback logs';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('direction'),
                TextColumn::make('target'),
                TextColumn::make('response_code'),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->defaultSort('id');
    }
}
