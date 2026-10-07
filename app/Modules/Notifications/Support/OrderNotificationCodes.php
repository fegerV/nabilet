<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Support;

use Nabilet\Modules\Orders\Support\OrderEventName;

/**
 * Код шаблона письма по статусу заказа.
 *
 * Код совпадает с именем события заказа (`order.<status>`), которое определено
 * в OrderEventName и обслуживает также исходящие вебхуки. Держать два списка —
 * значит два места, где они расходятся, а расхождение видно только по
 * отсутствию писем на проде. Класс остаётся отдельным, потому что код шаблона
 * и имя события — разные вещи, которые СЕЙЧАС совпадают; если письма когда-то
 * разойдутся с вебхуками, меняется только этот файл.
 */
final class OrderNotificationCodes
{
    public static function forStatus(string $status): ?string
    {
        return OrderEventName::forStatus($status);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return OrderEventName::all();
    }
}
