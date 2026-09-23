<?php

namespace App\Filament\Resources\EventSpeakerResource\Pages;

use App\Filament\Resources\EventSpeakerResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEventSpeakers extends ListRecords
{
    protected static string $resource = EventSpeakerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
