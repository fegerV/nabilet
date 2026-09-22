<?php

namespace App\Filament\Resources\EventFaqResource\Pages;

use App\Filament\Resources\EventFaqResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEventFaq extends EditRecord
{
    protected static string $resource = EventFaqResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
            Actions\ReplicateAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
