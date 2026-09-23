<?php

namespace App\Filament\Resources\EventSpeakerResource\Pages;

use App\Filament\Resources\EventSpeakerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEventSpeaker extends CreateRecord
{
    protected static string $resource = EventSpeakerResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
