<x-filament-panels::page>
    <div class="space-y-6">
        <!-- System Status Overview -->
        @if($isLoading)
            <div class="flex items-center justify-center py-12">
                <x-filament::loading-indicator class="w-12 h-12" />
            </div>
        @else
            <!-- Status Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <x-filament::card>
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full 
                            {{ $systemChecks['database']['status'] === 'ok' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600' }} 
                            flex items-center justify-center">
                            🗄️
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">База данных</p>
                            <p class="font-semibold {{ $systemChecks['database']['status'] === 'ok' ? 'text-green-600' : 'text-red-600' }}">
                                {{ $systemChecks['database']['message'] }}
                            </p>
                        </div>
                    </div>
                </x-filament::card>

                <x-filament::card>
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full 
                            {{ $systemChecks['cache']['status'] === 'ok' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600' }} 
                            flex items-center justify-center">
                            ⚡
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Кэш</p>
                            <p class="font-semibold {{ $systemChecks['cache']['status'] === 'ok' ? 'text-green-600' : 'text-red-600' }}">
                                {{ $systemChecks['cache']['message'] }}
                            </p>
                        </div>
                    </div>
                </x-filament::card>

                <x-filament::card>
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full 
                            {{ $systemChecks['storage']['status'] === 'ok' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600' }} 
                            flex items-center justify-center">
                            💾
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Хранилище</p>
                            <p class="font-semibold {{ $systemChecks['storage']['status'] === 'ok' ? 'text-green-600' : 'text-red-600' }}">
                                {{ $systemChecks['storage']['message'] }}
                            </p>
                        </div>
                    </div>
                </x-filament::card>

                <x-filament::card>
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full 
                            {{ $systemChecks['disk']['status'] === 'ok' ? 'bg-green-100 text-green-600' : ($systemChecks['disk']['status'] === 'warning' ? 'bg-yellow-100 text-yellow-600' : 'bg-red-100 text-red-600') }} 
                            flex items-center justify-center">
                            📊
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Диск</p>
                            <p class="font-semibold">
                                {{ $systemChecks['disk']['free_gb'] }} / {{ $systemChecks['disk']['total_gb'] }} GB
                            </p>
                            <p class="text-xs {{ $systemChecks['disk']['used_percent'] > 80 ? 'text-red-600' : 'text-gray-500' }}">
                                Занято: {{ $systemChecks['disk']['used_percent'] }}%
                            </p>
                        </div>
                    </div>
                </x-filament::card>
            </div>

            <!-- System Info -->
            <x-filament::card>
                <h3 class="text-lg font-semibold mb-4">Информация о системе</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="flex justify-between py-2 border-b">
                        <span class="text-gray-600">Версия PHP</span>
                        <span class="font-medium">{{ $systemChecks['php_version'] }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b">
                        <span class="text-gray-600">Версия Laravel</span>
                        <span class="font-medium">{{ $systemChecks['laravel_version'] }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b">
                        <span class="text-gray-600">Лимит памяти</span>
                        <span class="font-medium">{{ $systemChecks['memory_limit'] }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b">
                        <span class="text-gray-600">Использование памяти</span>
                        <span class="font-medium">{{ $systemChecks['memory_usage'] }}</span>
                    </div>
                </div>
            </x-filament::card>

            <!-- Maintenance Actions -->
            <x-filament::card>
                <h3 class="text-lg font-semibold mb-4">Обслуживание системы</h3>
                
                <div class="flex flex-wrap gap-4">
                    <x-filament::button
                        wire:click="clearCache"
                        wire:loading.attr="disabled"
                        color="warning"
                    >
                        <span wire:loading.remove>🧹 Очистить кэш</span>
                        <span wire:loading>⏳ Очистка...</span>
                    </x-filament::button>

                    <x-filament::button
                        wire:click="optimizeDatabase"
                        wire:loading.attr="disabled"
                        color="primary"
                    >
                        <span wire:loading.remove>🗄️ Оптимизировать БД</span>
                        <span wire:loading>⏳ Оптимизация...</span>
                    </x-filament::button>

                    <a href="{{ route('filament.system.pages.logs') }}" 
                       class="inline-flex items-center gap-2 px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                        📋 Просмотр логов
                    </a>
                </div>
            </x-filament::card>

            <!-- Health Status Summary -->
            @php
                $hasErrors = collect($systemChecks)
                    ->whereIn('status', ['error'])
                    ->isNotEmpty();
                $hasWarnings = collect($systemChecks)
                    ->whereIn('status', ['warning'])
                    ->isNotEmpty();
            @endphp

            @if($hasErrors)
                <x-filament::callout type="danger">
                    <x-slot name="heading">⚠️ Обнаружены критические ошибки</x-slot>
                    Проверьте компоненты со статусом "error" и устраните проблемы.
                </x-filament::callout>
            @elseif($hasWarnings)
                <x-filament::callout type="warning">
                    <x-slot name="heading">⚡ Предупреждения</x-slot>
                    Некоторые компоненты работают в неоптимальном режиме.
                </x-filament::callout>
            @else
                <x-filament::callout type="success">
                    <x-slot name="heading">✅ Все системы в норме</x-slot>
                    Все проверки пройдены успешно.
                </x-filament::callout>
            @endif
        @endif
    </div>
</x-filament-panels::page>
