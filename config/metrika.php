<?php

declare(strict_types=1);

/*
 * Яндекс Метрика — счётчик для передачи данных в Яндекс Директ.
 *
 * Как это работает:
 *  1. В Метровке создаётся счётчик, в нём включается «Ещё → Таргетирование →
 *     Обмен данными с Директом».
 *  2. В Директе настраиваются цели по событиям (см. goals ниже) — чек-лист
 *     «Метрика + Директ» подхватывает их автоматически.
 *  3. Здесь указывается ID счётчика; SPA догружает тег mc.yandex.ru и шлёт
 *     ym(id, 'reachGoal', ...) по ключевым шагам воронки покупки билета.
 *
 * Все значения можно переопределить через админскую таблицу `metrika_settings`
 * (модуль Analytics, сервис MetrikaSettings) — без деплоя.
 */

return [
    // ID счётчика Метрики (число из кода вставки). Пусто/0 — интеграция выключена.
    'counter_id' => (int) env('YANDEX_METRIKA_COUNTER_ID', 0),

    // Ключ аутентификации счётчика из кода вставки (параметр &ct= / auth token).
    // Нужен, если счётчик размещён на другом домене или требуется точная атрибуция.
    'counter_auth' => env('YANDEX_METRIKA_COUNTER_AUTH'),

    // Тип счётчика: web — обычный сайт/SPA, hit — только события без pageView.
    'counter_type' => env('YANDEX_METRIKA_COUNTER_TYPE', 'web'),

    // Безопасный режим (clickBeacon:false, WebVisor off) — меньше данных, но
    // совместимо с политикой конфиденциальности.
    'safe_stage' => env('YANDEX_METRIKA_SAFE_MODE', 'false') === 'true',

    // Загрузка скрипта с cdn.jsdelivr.net (без cookie-домена Яндекса) — включать,
    // только если нужна работа при заблокированных куках третьих сторон.
    'accurate_track' => env('YANDEX_METRIKA_ACCURATE_TRACK', 'false') === 'true',

    // Товарная фича e-commerce: params.product для reachGoal корзины/заказа.
    'ecommerce' => env('YANDEX_METRIKA_ECOMMERCE', 'true') === 'true',

    /*
     * События воронки → цели Метрики для Яндекс Директа.
     * Ключ — внутреннее имя события (используется во фронтенде),
     * значение — имя цели (reachGoal), которое должно совпадать с целью
     * в настройках счётчика. Названия целей создайте в Метровке один в один.
     */
    'goals' => [
        'page_view'         => 'page_view',          // просмотр страницы (pageView, не цель)
        'event_view'        => 'event_view',         // карточка мероприятия
        'seatmap_open'      => 'seatmap_open',       // открыта схема зала
        'seat_selected'     => 'seat_selected',      // выбрано место
        'checkout_started'  => 'checkout_started',   // начал оформление заказа
        'payment_attempt'   => 'payment_attempt',    // нажал «Оплатить»
        'payment_success'   => 'purchase',           // ОПЛАТА ПРОШЛА — главная цель для автостратегий Директа
        'payment_fail'      => 'payment_fail',       // платёж не прошёл
    ],

    // Валюта для params.revenue/priority у цели покупки (для стратегии «оплата»).
    'currency' => env('YANDEX_METRIKA_CURRENCY', 'RUB'),
];
