<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-qr-code class="w-6 h-6" />
                <h2 class="text-lg font-semibold">{{ __('Лента сканирований') }}</h2>
            </div>
        </x-slot>

        <x-slot name="description">
            {{ __('Последние проверки билетов на входе') }}
        </x-slot>

        <div class="space-y-4">
            @php
                $scans = $this->getScans();
            @endphp

            @if(count($scans) > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left border border-line">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="p-3 font-semibold">{{ __('Билет') }}</th>
                                <th class="p-3 font-semibold">{{ __('Событие') }}</th>
                                <th class="p-3 font-semibold">{{ __('Когда') }}</th>
                                <th class="p-3 font-semibold">{{ __('Результат') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach($scans as $scan)
                                <tr>
                                    <td class="p-3 font-mono text-xs">{{ $scan->qr_token_hash }}</td>
                                    <td class="p-3">{{ $scan->event }}</td>
                                    <td class="p-3">{{ $scan->scanned_at->format('d M Y H:i') }}</td>
                                    <td class="p-3">
                                        <x-filament::badge
                                            :color="match($scan->result) {
                                                'allowed' => 'success',
                                                'rejected' => 'danger',
                                                default => 'gray',
                                            }"
                                        >
                                            {{ $scan->result }}
                                        </x-filament::badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-6 text-gray-500 dark:text-gray-400">
                    <x-heroicon-o-qr-code class="w-10 h-10 mx-auto mb-2 opacity-50" />
                    <p>{{ __('Сканирований пока нет') }}</p>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>