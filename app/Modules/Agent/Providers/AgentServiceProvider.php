<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Providers;

use Illuminate\Support\ServiceProvider;

class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Маршруты модуля НЕ грузятся отсюда — они уже подключены в
        // routes/ai.php, как и все остальные модульные маршруты в
        // routes/api.php (единое место, а не `loadRoutesFrom` в провайдере —
        // см. тот же комментарий в AuthServiceProvider).
        //
        // Команда `nabilet:agent-token` регистрируется явно в
        // bootstrap/app.php (`withCommands`): модульные провайдеры не бутятся,
        // а Laravel не находит команды вне `app/Console/Commands/` сам.
    }
}
