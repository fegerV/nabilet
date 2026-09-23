<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventDateResource\Pages;

use App\Filament\Resources\EventDateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEventDates extends ListRecords
{
    protected static string $resource = EventDateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
