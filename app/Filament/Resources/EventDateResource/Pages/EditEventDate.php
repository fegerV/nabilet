<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventDateResource\Pages;

use App\Filament\Resources\EventDateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEventDate extends EditRecord
{
    protected static string $resource = EventDateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
