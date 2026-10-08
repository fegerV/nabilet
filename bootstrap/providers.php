<?php

/**
 * Application service providers.
 *
 * Auth and Payments are registered explicitly for their guard driver and payment
 * bindings. Tickets is registered to expose its namespaced email views; its routes
 * are loaded centrally from routes/api.php so they retain the /api/v1 prefix.
 *
 * NabiletServiceProvider and the remaining module providers are not registered by
 * this file. Do not add the whole module registry until route ownership and prefix
 * handling have been normalized; some providers load route files independently,
 * which could register endpoints outside the central API group.
 */

return [
    // Registers the `session_token` auth driver that the `api` guard names.
    // Its route file is loaded by routes/api.php, not by the provider.
    Nabilet\Modules\Auth\Providers\AuthServiceProvider::class,

    // PaymentService и его провайдер (YooKassa) — PaymentServiceProvider не
    // регистрируется через NabiletServiceProvider (см. комментарий выше), поэтому
    // перечисляем его явно, чтобы биндинги работали для вебхуков и рефандов.
    Nabilet\Modules\Payments\Providers\PaymentServiceProvider::class,
    Nabilet\Modules\Tickets\Providers\TicketServiceProvider::class,

    // Media: провайдер регистрируется ради биндинга `MediaService` с диском из
    // `nabilet.media.disk`. Свои маршруты он НЕ грузит (иначе они появились бы
    // без префикса /api/v1) — поэтому он безопасен для этого файла, в отличие
    // от провайдеров, о которых предупреждает комментарий выше.
    Nabilet\Modules\Media\Providers\MediaServiceProvider::class,
];
