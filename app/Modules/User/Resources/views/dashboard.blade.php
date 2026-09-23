@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-8">Личный кабинет</h1>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Sidebar -->
        <div class="md:col-span-1">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="text-center mb-6">
                    <div class="w-20 h-20 bg-purple-600 rounded-full mx-auto flex items-center justify-center text-white text-2xl font-bold">
                        {{ substr(Auth::user()->name, 0, 1) }}
                    </div>
                    <h2 class="text-xl font-semibold mt-4">{{ Auth::user()->name }}</h2>
                    <p class="text-gray-600">{{ Auth::user()->email }}</p>
                </div>

                <nav class="space-y-2">
                    <a href="{{ route('user.dashboard') }}" class="block px-4 py-2 rounded hover:bg-gray-100 {{ request()->routeIs('user.dashboard') ? 'bg-purple-50 text-purple-600' : '' }}">
                        📋 Мои заказы
                    </a>
                    <a href="{{ route('user.profile') }}" class="block px-4 py-2 rounded hover:bg-gray-100 {{ request()->routeIs('user.profile') ? 'bg-purple-50 text-purple-600' : '' }}">
                        ⚙️ Профиль
                    </a>
                    <a href="{{ route('user.favorites') }}" class="block px-4 py-2 rounded hover:bg-gray-100">
                        ❤️ Избранное
                    </a>
                </nav>
            </div>
        </div>

        <!-- Main Content -->
        <div class="md:col-span-2">
            <!-- Orders History -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-2xl font-bold mb-4">История заказов</h2>

                @if($bookings->count() > 0)
                    <div class="space-y-4">
                        @foreach($bookings as $booking)
                            <div class="border rounded-lg p-4 hover:shadow-md transition">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h3 class="font-bold text-lg">{{ $booking->event_name }}</h3>
                                        <p class="text-gray-600 text-sm">
                                            📅 {{ \Carbon\Carbon::parse($booking->start_at)->format('d.m.Y H:i') }}
                                        </p>
                                        @if($booking->venue_name)
                                            <p class="text-gray-500 text-sm">📍 {{ $booking->venue_name }}</p>
                                        @endif
                                    </div>
                                    <div class="text-right">
                                        <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold
                                            @if($booking->status === 'confirmed') bg-green-100 text-green-800
                                            @elseif($booking->status === 'pending') bg-yellow-100 text-yellow-800
                                            @elseif($booking->status === 'cancelled') bg-red-100 text-red-800
                                            @else bg-gray-100 text-gray-800
                                            @endif">
                                            {{ $booking->status }}
                                        </span>
                                        <p class="mt-2 font-bold text-purple-600">{{ number_format($booking->total_price, 2) }} ₽</p>
                                    </div>
                                </div>
                                <div class="mt-4 flex gap-2">
                                    <a href="{{ route('user.order.details', $booking->id) }}" 
                                       class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700 text-sm">
                                        Подробнее
                                    </a>
                                    @if($booking->status === 'confirmed')
                                        <button onclick="downloadTicket({{ $booking->id }})" 
                                                class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 text-sm">
                                            📥 Билет
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <!-- Pagination -->
                    <div class="mt-6">
                        {{ $bookings->links() }}
                    </div>
                @else
                    <div class="text-center py-12">
                        <p class="text-gray-500 text-lg">У вас пока нет заказов</p>
                        <a href="{{ route('events.index') }}" class="mt-4 inline-block px-6 py-2 bg-purple-600 text-white rounded hover:bg-purple-700">
                            Смотреть события
                        </a>
                    </div>
                @endif
            </div>

            <!-- Favorites -->
            @if($favorites->count() > 0)
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-2xl font-bold mb-4">Избранные события</h2>
                    <div class="space-y-3">
                        @foreach($favorites as $favorite)
                            <div class="flex justify-between items-center border-b pb-3 last:border-0">
                                <div>
                                    <h3 class="font-semibold">{{ $favorite->name }}</h3>
                                    <p class="text-sm text-gray-600">
                                        {{ \Carbon\Carbon::parse($favorite->start_at)->format('d.m.Y') }}
                                        @if($favorite->venue_name)
                                            • {{ $favorite->venue_name }}
                                        @endif
                                    </p>
                                </div>
                                <a href="{{ route('events.show', $favorite->id) }}" 
                                   class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm">
                                    Купить билет
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function downloadTicket(bookingId) {
    window.location.href = `/api/tickets/${bookingId}/download`;
}
</script>
@endpush
