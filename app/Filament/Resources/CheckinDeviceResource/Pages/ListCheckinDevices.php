<?php

declare(strict_types=1);

namespace App\Filament\Resources\CheckinDeviceResource\Pages;

use App\Filament\Resources\CheckinDeviceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCheckinDevices extends ListRecords
{
    protected static string $resource = CheckinDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make()
        ];
    }
}
