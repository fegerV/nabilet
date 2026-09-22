<?php

declare(strict_types=1);

namespace App\Filament\Resources\HallResource\Pages;

use App\Filament\Resources\HallResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHall extends EditRecord
{
    protected static string $resource = HallResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\Action::make('editSchema')
                ->label('Редактировать схему зала')
                ->icon('heroicon-o-cog-6-tooth')
                ->url(fn () => url('/admin/halls/' . $this->getRecord()->id . '/schema/edit'))
                ->requiresConfirmation(false)
                ->color('success')
                ->openUrlInNewTab(),
        ];
    }
}
