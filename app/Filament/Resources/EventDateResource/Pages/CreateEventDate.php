<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventDateResource\Pages;

use App\Filament\Resources\EventDateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEventDate extends CreateRecord
{
    protected static string $resource = EventDateResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
