<?php

declare(strict_types=1);

namespace Nabilet\Modules\Installer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Nabilet\Modules\Installer\Support\InstallKey;

/**
 * Специальная процедура повторной установки: снимает install.lock и очищает
 * состояние мастера. Без неё /install физически не регистрируется после
 * завершения установки, поэтому «случайно переустановить» невозможно.
 *
 * Команда НЕ трогает БД и .env: она только открывает мастер. Оператор обязан
 * понимать, что повторный запуск миграций/сидеров может изменить данные.
 */
final class UnlockInstallerCommand extends Command
{
    protected $signature = 'nabilet:install:unlock {--force : Подтвердить снятие блокировки установщика}';

    protected $description = 'Снять install.lock и открыть мастер установки повторно (только с --force)';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Эта команда открывает повторную установку. Подтвердите: --force');

            return self::FAILURE;
        }

        $lock = storage_path('install.lock');
        if (! File::exists($lock)) {
            $this->info('Установщик уже открыт (install.lock отсутствует).');

            return self::SUCCESS;
        }

        File::delete($lock);
        File::delete(storage_path('framework/install-state.json'));
        InstallKey::forget();
        Log::channel('security')->warning('Installer: блокировка повторной установки снята вручную');

        $this->info('install.lock удалён. Откройте /install — ключ будет создан заново.');
        $this->warn('БД и .env не изменялись; повторная установка может затронуть данные.');

        return self::SUCCESS;
    }
}
