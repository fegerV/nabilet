<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

/**
 * Виджет «Быстрые подсказки» на главной панели.
 * Ссылки на справку и частые действия администратора.
 */
class QuickHelpWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static string $view = 'filament.widgets.quick-help-widget';

    protected int | string | array $columnSpan = 2;

    protected static ?int $sort = 50;

    public function getTips(): array
    {
        return [
            [
                'icon' => 'heroicon-o-sparkles',
                'title' => 'Новое мероприятие',
                'text' => '1. Организатор → Площадка → Зал → Схема → Мероприятие → Сеанс → Published.',
                'route' => 'filament.resources.events.create',
            ],
            [
                'icon' => 'heroicon-o-map',
                'title' => 'Схема зала не редактируется?',
                'text' => 'Опубликованная схема неизменна. Создайте новую версию (Duplicate) и опубликуйте её.',
                'route' => 'filament.resources.halls.index',
            ],
            [
                'icon' => 'heroicon-o-cash',
                'title' => 'Проверьте продажи',
                'text' => 'Следите за выручкой и статусами платежей в разделах Orders и Payments.',
                'route' => 'filament.resources.orders.index',
            ],
            [
                'icon' => 'heroicon-o-qr-code',
                'title' => 'Билет не сканируется?',
                'text' => 'Проверьте статус заказа: отменённые и возвращённые билеты не проходят контроль.',
                'route' => 'filament.resources.tickets.index',
            ],
        ];
    }
}