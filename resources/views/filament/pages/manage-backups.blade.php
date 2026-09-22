<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Last Backup Info -->
        <x-filament::card>
            <h3 class="text-lg font-semibold mb-4">Последний бэкап</h3>
            
            @if(isset($lastBackupInfo['filename']))
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <p class="text-sm text-gray-500">Файл</p>
                        <p class="font-medium truncate">{{ $lastBackupInfo['filename'] }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Размер</p>
                        <p class="font-medium">{{ $lastBackupInfo['size'] }} MB</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Создан</p>
                        <p class="font-medium">{{ $lastBackupInfo['created_at'] }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Возраст</p>
                        <p class="font-medium {{ $lastBackupInfo['age_hours'] > 24 ? 'text-red-600' : 'text-green-600' }}">
                            {{ $lastBackupInfo['age_hours'] }} ч. назад
                        </p>
                    </div>
                </div>
            @else
                <p class="text-gray-500">{{ $lastBackupInfo['message'] ?? 'Нет информации' }}</p>
            @endif
        </x-filament::card>

        <!-- Yandex Disk Settings -->
        <x-filament::card>
            <h3 class="text-lg font-semibold mb-4">Настройки Яндекс.Диска</h3>
            
            <form wire:submit="saveYandexToken" class="space-y-4">
                <x-filament-forms::field.wrapper label="OAuth токен" required>
                    <x-filament-forms::input
                        type="password"
                        wire:model="yandexDiskToken"
                        placeholder="Введите токен Яндекс.Диска"
                    />
                    <p class="text-xs text-gray-500 mt-1">
                        Получите токен в <a href="https://oauth.yandex.ru/" target="_blank" class="text-primary-600 hover:underline">Кабинете разработчика Яндекс</a>
                    </p>
                </x-filament-forms::field.wrapper>

                <x-filament::button type="submit" color="primary">
                    Сохранить токен
                </x-filament::button>
            </form>
        </x-filament::card>

        <!-- Backup Actions -->
        <x-filament::card>
            <h3 class="text-lg font-semibold mb-4">Создать бэкап</h3>
            
            <div class="flex flex-wrap gap-4">
                <x-filament::button
                    wire:click="createBackup"
                    wire:loading.attr="disabled"
                    color="success"
                    size="lg"
                >
                    <span wire:loading.remove>📦 Полный бэкап (БД + файлы)</span>
                    <span wire:loading>⏳ Создание...</span>
                </x-filament::button>

                <x-filament::button
                    wire:click="createDatabaseOnlyBackup"
                    wire:loading.attr="disabled"
                    color="primary"
                    size="lg"
                >
                    <span wire:loading.remove>🗄️ Только база данных</span>
                    <span wire:loading>⏳ Создание...</span>
                </x-filament::button>
            </div>

            <div class="mt-6 p-4 bg-blue-50 rounded-lg">
                <h4 class="font-semibold text-blue-800 mb-2">ℹ️ Информация</h4>
                <ul class="text-sm text-blue-700 space-y-1">
                    <li>• Полные бэкапы автоматически загружаются на Яндекс.Диск</li>
                    <li>• Бэкапы БД также отправляются на облачное хранилище</li>
                    <li>• Рекомендуется создавать бэкапы перед важными изменениями</li>
                    <li>• Автоматические бэкапы можно настроить через Cron</li>
                </ul>
            </div>
        </x-filament::card>

        <!-- Scheduled Backups Info -->
        <x-filament::card>
            <h3 class="text-lg font-semibold mb-4">Автоматические бэкапы</h3>
            
            <div class="p-4 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-600 mb-2">Для настройки автоматических бэкапов добавьте в Crontab:</p>
                <code class="block p-3 bg-white rounded border text-xs overflow-x-auto">
                    0 2 * * * /opt/php82/bin/php /home/c/XXXXX/artisan backup:run >> /dev/null 2>&1
                </code>
                <p class="text-xs text-gray-500 mt-2">
                    Эта команда будет запускать бэкап каждый день в 2:00 ночи
                </p>
            </div>
        </x-filament::card>
    </div>
</x-filament-panels::page>
