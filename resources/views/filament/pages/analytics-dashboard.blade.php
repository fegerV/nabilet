<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Period Selector -->
        <div class="flex gap-2">
            @foreach([7, 30, 90, 365] as $days)
                <button
                    wire:click="setPeriod({{ $days }})"
                    class="px-4 py-2 rounded-lg font-medium transition
                        {{ $period == $days 
                            ? 'bg-primary-600 text-white' 
                            : 'bg-white text-gray-700 hover:bg-gray-100' }}"
                >
                    {{ $days }} дн.
                </button>
            @endforeach
        </div>

        <!-- Metrics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <x-filament::card>
                <div class="text-center">
                    <p class="text-sm text-gray-500 uppercase">Продажи</p>
                    <p class="text-3xl font-bold text-primary-600">{{ number_format($metrics['totalSales'] ?? 0, 2) }} ₽</p>
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="text-center">
                    <p class="text-sm text-gray-500 uppercase">Заказы</p>
                    <p class="text-3xl font-bold text-success-600">{{ $metrics['totalOrders'] ?? 0 }}</p>
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="text-center">
                    <p class="text-sm text-gray-500 uppercase">Билеты</p>
                    <p class="text-3xl font-bold text-warning-600">{{ $metrics['totalTicketsSold'] ?? 0 }}</p>
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="text-center">
                    <p class="text-sm text-gray-500 uppercase">Средний чек</p>
                    <p class="text-3xl font-bold text-info-600">
                        {{ ($metrics['totalOrders'] ?? 0) > 0 
                            ? number_format(($metrics['totalSales'] ?? 0) / $metrics['totalOrders'], 2) 
                            : 0 }} ₽
                    </p>
                </div>
            </x-filament::card>
        </div>

        <!-- Chart Placeholder -->
        <x-filament::card>
            <h3 class="text-lg font-semibold mb-4">Динамика продаж</h3>
            <div id="salesChart" class="h-64"></div>
        </x-filament::card>

        <!-- Top Events -->
        <x-filament::card>
            <h3 class="text-lg font-semibold mb-4">Популярные события</h3>
            <div class="space-y-3">
                @forelse($topEvents as $index => $event)
                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                        <div class="flex items-center gap-3">
                            <span class="w-8 h-8 bg-primary-600 text-white rounded-full flex items-center justify-center font-bold">
                                {{ $index + 1 }}
                            </span>
                            <span class="font-medium">{{ $event['name'] }}</span>
                        </div>
                        <div class="text-right">
                            <p class="font-bold text-primary-600">{{ number_format($event['revenue'], 2) }} ₽</p>
                            <p class="text-sm text-gray-500">{{ $event['orders'] }} заказов</p>
                        </div>
                    </div>
                @empty
                    <p class="text-gray-500 text-center py-8">Нет данных за выбранный период</p>
                @endforelse
            </div>
        </x-filament::card>

        <!-- Export Actions -->
        {{-- Кнопки вели на route('analytics.export.csv') и route('analytics.export.excel'),
             которых в проекте нет: страница падала с RouteNotFoundException ещё до отрисовки.
             Экспорт теперь выполняет метод Livewire exportCsv(), поэтому ссылка не нужна.
             Кнопки .xlsx нет — пакет maatwebsite/excel не подключён; CSV открывается в Excel. --}}
        <div class="flex gap-4">
            <button
                type="button"
                wire:click="exportCsv"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
            >
                📊 Экспорт заказов в CSV
            </button>
        </div>
    </div>
</x-filament-panels::page>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const ctx = document.getElementById('salesChart').getContext('2d');
    const salesData = @json($salesData);
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: salesData.map(item => item.date),
            datasets: [{
                label: 'Выручка (₽)',
                data: salesData.map(item => item.revenue),
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99, 102, 241, 0.1)',
                tension: 0.4,
                fill: true
            }, {
                label: 'Заказы',
                data: salesData.map(item => item.orders),
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Рубли'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    grid: {
                        drawOnChartArea: false
                    },
                    title: {
                        display: true,
                        text: 'Количество'
                    }
                }
            }
        }
    });
</script>
@endpush
