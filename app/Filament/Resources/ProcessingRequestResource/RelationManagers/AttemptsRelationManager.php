<?php

namespace App\Filament\Resources\ProcessingRequestResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'attempts';

    protected static ?string $title = 'Processing attempts';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('stage'),
                TextColumn::make('status'),
                TextColumn::make('started_at')->dateTime(),
                TextColumn::make('finished_at')->dateTime(),
            ])
            ->defaultSort('id');
    }
}
