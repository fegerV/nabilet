<?php

namespace App\Filament\Resources\EventFaqResource\Pages;

use App\Filament\Resources\EventFaqResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEventFaqs extends ListRecords
{
    protected static string $resource = EventFaqResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
