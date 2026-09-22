<?php

namespace App\Filament\Resources\EventFaqResource\Pages;

use App\Filament\Resources\EventFaqResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEventFaq extends CreateRecord
{
    protected static string $resource = EventFaqResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
