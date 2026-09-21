<?php

declare(strict_types=1);

namespace App\Filament\Resources\CheckinDeviceResource\Pages;

use App\Filament\Resources\CheckinDeviceResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateCheckinDevice extends CreateRecord
{
    protected static string $resource = CheckinDeviceResource::class;

}
