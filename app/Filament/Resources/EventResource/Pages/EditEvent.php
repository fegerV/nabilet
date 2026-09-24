<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventResource\Pages;

use App\Filament\Resources\EventResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    /**
     * Кастомная страница редактирования с чек-листом готовности.
     *
     * @var view-string
     */
    protected static string $view = 'filament.resources.event-edit';

    /**
     * Статус чек-листа: элементы и общий флаг готовности.
     *
     * @var array<array<string, mixed>>
     */
    public array $checklistStatus = [];

    public bool $checklistOk = false;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\DeleteAction::make()
        ];
    }

    /**
     * Проверка готовности мероприятия к публикации.
     * Основана на реальных данных БД: заполненность полей, наличие
     * опубликованной схемы зала, сеансов с инвентарём и ценами.
     */
    public function runPublishChecklist(): void
    {
        $record = $this->getRecord();

        if ($record === null) {
            $this->checklistStatus = [
                [
                    'title' => 'Мероприятие не найдено',
                    'detail' => 'Запись отсутствует в базе данных.',
                    'ok' => false,
                ],
            ];
            $this->checklistOk = false;
            return;
        }

        $eventId = (int) $record->id;
        $items = [];

        // 1. Название
        $items[] = [
            'title' => 'Название',
            'detail' => filled($record->title) ? $record->title : 'Пустое название — покупатель не поймёт, что это за событие.',
            'ok' => filled($record->title),
        ];

        // 2. Slug
        $items[] = [
            'title' => 'Slug (адрес)',
            'detail' => filled($record->slug) ? $record->slug : 'Slug не заполнен. Без него нет адреса страницы.',
            'ok' => filled($record->slug),
        ];

        // 3. Описание
        $items[] = [
                    'title' => 'Описание',
                    'detail' => filled($record->description) && strlen($record->description) >= 30
                        ? 'Описание заполнено и достаточно подробное.'
                        : (filled($record->description)
                            ? 'Описание короткое (меньше 30 символов). Добавьте программу и детали.'
                            : 'Описание пустое. Расскажите о программе, артистах и формате.'),
                    'ok' => filled($record->description) && strlen($record->description) >= 30,
                ];

        // 4. Постер
        $items[] = [
            'title' => 'Постер',
            'detail' => filled($record->poster)
                ? 'Постер загружен.'
                : 'Постера нет — карточка на афише будет без изображения.',
            'ok' => filled($record->poster),
        ];

        // 5. Сеансы
        $sessionCount = DB::table('sessions')->where('event_id', $eventId)->count();
        $items[] = [
            'title' => 'Сеансы',
            'detail' => $sessionCount > 0
                ? sprintf('Создано сеансов: %d.', $sessionCount)
                : 'Нет ни одного сеанса. Без сеанса (дата × зал) продавать нечего.',
            'ok' => $sessionCount > 0,
        ];

        // 6. Зал у сеансов
        if ($sessionCount > 0) {
            $sessionsWithHall = DB::table('sessions')
                ->where('event_id', $eventId)
                ->whereNotNull('hall_id')
                ->count();

            $items[] = [
                'title' => 'Зал у сеансов',
                'detail' => $sessionsWithHall === $sessionCount
                    ? sprintf('Все %d сеансов привязаны к залу.', $sessionCount)
                    : sprintf('Часть сеансов (%d из %d) без зала.', $sessionCount - $sessionsWithHall, $sessionCount),
                'ok' => $sessionsWithHall === $sessionCount,
            ];

            // 7. Опубликованная схема зала
            $schemaOk = DB::table('sessions')
                ->join('hall_schema_versions', 'sessions.schema_version_id', '=', 'hall_schema_versions.id')
                ->where('sessions.event_id', $eventId)
                ->where('hall_schema_versions.status', 'published')
                ->count();

            $items[] = [
                'title' => 'Схема зала опубликована',
                'detail' => $schemaOk > 0
                    ? 'Сеансы используют опубликованные версии схем.'
                    : 'У сеансов нет опубликованной схемы зала. Опубликуйте схему (Halls → Edit Schema → Publish).',
                'ok' => $schemaOk > 0,
            ];

            // 8. Инвентарь (места в продаже)
            $inventoryCount = DB::table('inventory_items')
                ->join('sessions', 'inventory_items.session_id', '=', 'sessions.id')
                ->where('sessions.event_id', $eventId)
                ->count();

            $availableCount = DB::table('inventory_items')
                ->join('sessions', 'inventory_items.session_id', '=', 'sessions.id')
                ->where('sessions.event_id', $eventId)
                ->where('inventory_items.status', 'available')
                ->where('inventory_items.available_quantity', '>', 0)
                ->count();

            $items[] = [
                'title' => 'Инвентарь (места в продаже)',
                'detail' => $inventoryCount > 0
                    ? sprintf('В продаже %d из %d мест.', $availableCount, $inventoryCount)
                    : 'Инвентарь не создан: места не продаются. Пересохраните сеанс, чтобы сгенерировать места по схеме.',
                'ok' => $inventoryCount > 0,
            ];

            // 9. Цены
            $priceCount = DB::table('inventory_items')
                ->join('sessions', 'inventory_items.session_id', '=', 'sessions.id')
                ->where('sessions.event_id', $eventId)
                ->where('inventory_items.price_amount', '>', 0)
                ->count();

            $items[] = [
                'title' => 'Цены у мест',
                'detail' => $priceCount > 0
                    ? sprintf('Цены заданы для %d мест.', $priceCount)
                    : 'У мест нет цены (или она нулевая). Убедитесь, что ряды схемы имеют цену.',
                'ok' => $priceCount > 0,
            ];
        }

        // 10. Статус
        $isPublished = $record->status === 'published';
        $items[] = [
            'title' => 'Статус',
            'detail' => $isPublished
                ? 'Мероприятие уже опубликовано и доступно на витрине.'
                : sprintf('Текущий статус: %s. После проверки установите Published.', $record->status ?? 'draft'),
            'ok' => $isPublished,
        ];

        $this->checklistStatus = $items;

                $failed = collect($items)->filter(fn ($i) => ! $i['ok'])->count();
                $this->checklistOk = $failed === 0;
    }
}