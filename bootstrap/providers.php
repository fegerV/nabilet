<?php

/**
 * Application service providers.
 *
 * `Nabilet\Core\NabiletServiceProvider` is NOT listed here, and that is a defect
 * rather than a design: it is the kernel's documented single entry point, whose
 * job is to discover modules and register each module's own provider, and nothing
 * references it. The consequence is that **no module service provider ever boots**
 * — `config/nabilet.php` names nine of them and none is registered — which is why
 * `HookRegistry` and `OrganizationContext` are not the singletons their own
 * docblocks call load-bearing, and why `role:admin` on the hall routes points at
 * an alias no one registers. Recorded in docs/SERVER-HEALTH.md and
 * docs/REVIEW-spec-bundle.md.
 *
 * The Auth provider is listed individually because that module's guard driver has
 * to be registered for the API to authenticate anyone. Registering the whole
 * registry is the real fix and is deliberately not attempted here: every module
 * provider calls `loadRoutesFrom()`, which is a bare `require` with no prefix, so
 * booting them all at once would expose every module's endpoints a second time
 * outside `/api/v1`. That refactor needs its own change.
 */

return [
    App\Providers\Filament\AdminPanelProvider::class,

    // Registers the `session_token` auth driver that the `api` guard names.
    // Its route file is loaded by routes/api.php, not by the provider.
    Nabilet\Modules\Auth\Providers\AuthServiceProvider::class,

    // PaymentService и его провайдер (YooKassa) — PaymentServiceProvider не
    // регистрируется через NabiletServiceProvider (см. комментарий выше), поэтому
    // перечисляем его явно, чтобы биндинги работали для вебхуков и рефандов.
    Nabilet\Modules\Payments\Providers\PaymentServiceProvider::class,
];
