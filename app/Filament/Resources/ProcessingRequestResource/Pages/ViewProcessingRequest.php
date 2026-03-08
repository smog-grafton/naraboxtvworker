<?php

namespace App\Filament\Resources\ProcessingRequestResource\Pages;

use App\Filament\Resources\ProcessingRequestResource;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;

class ViewProcessingRequest extends ViewRecord
{
    protected static string $resource = ProcessingRequestResource::class;

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
                TextEntry::make('received_at')->dateTime(),
                TextEntry::make('started_at')->dateTime(),
                TextEntry::make('completed_at')->dateTime(),
            ])->columns(2);
    }
}
