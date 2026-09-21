<?php

declare(strict_types=1);

namespace App\Filament\Resources\HallResource\Pages;

use App\Filament\Resources\HallResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateHall extends CreateRecord
{
    protected static string $resource = HallResource::class;

}
