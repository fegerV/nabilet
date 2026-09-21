<?php

declare(strict_types=1);

namespace App\Filament\Resources\CheckinDeviceResource\Pages;

use App\Filament\Resources\CheckinDeviceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCheckinDevice extends EditRecord
{
    protected static string $resource = CheckinDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\DeleteAction::make()
        ];
    }
}
