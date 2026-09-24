<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-light-bulb class="w-6 h-6" />
                <h2 class="text-lg font-semibold">
                    {{ __('Быстрые подсказки') }}
                </h2>
            </div>
        </x-slot>

        <x-slot name="description">
            {{ __('Короткие советы для ежедневной работы') }}
        </x-slot>

        <div class="space-y-4">
            @php
                $tips = $this->getTips();
            @endphp

            @if(count($tips) > 0)
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach($tips as $tip)
                        <div class="rounded-lg border border-line bg-white dark:bg-gray-800 p-4">
                            <div class="flex items-center gap-2 font-medium">
                                <x-heroicon-o-light-bulb class="w-5 h-5 text-primary-500 shrink-0" />
                                <h4 class="text-sm font-semibold">{{ $tip['title'] }}</h4>
                            </div>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">{{ $tip['text'] }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="mt-2">
                    <a href="{{ route('filament.admin.pages.help-docs') }}"
                       class="inline-flex items-center gap-1 text-sm text-primary-600 hover:text-primary-700 dark:text-primary-400">
                        {{ __('Открыть полное руководство') }}
                        <span aria-hidden="true">→</span>
                    </a>
                </div>
            @else
                <p class="text-sm text-gray-500">{{ __('Подсказок пока нет') }}</p>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>