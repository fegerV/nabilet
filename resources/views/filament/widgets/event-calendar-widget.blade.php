<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-calendar-days class="w-6 h-6" />
                <h2 class="text-lg font-semibold">
                    {{ __('Event Calendar') }}
                </h2>
            </div>
        </x-slot>

        <x-slot name="description">
            {{ __('Upcoming event dates and sessions') }}
        </x-slot>

        <div class="space-y-4">
            @php
                $upcomingDates = $this->getUpcomingDates();
            @endphp

            @if(count($upcomingDates) > 0)
                <div class="space-y-3">
                    @foreach($upcomingDates as $date)
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg dark:bg-gray-800">
                            <div class="flex-1">
                                <div class="font-medium text-gray-900 dark:text-white">
                                    {{ $date['event_title'] }}
                                    @if($date['name'])
                                        <span class="text-sm text-gray-500">— {{ $date['name'] }}</span>
                                    @endif
                                </div>
                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ $date['start_at']->format('M d, Y \a\t H:i') }}
                                    @if($date['end_at'])
                                        - {{ $date['end_at']->format('H:i') }}
                                    @endif
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-2">
                                @if($date['is_sold_out'])
                                    <x-filament::badge color="danger">
                                        {{ __('Sold Out') }}
                                    </x-filament::badge>
                                @else
                                    <x-filament::badge color="success">
                                        {{ __('Available') }}
                                    </x-filament::badge>
                                @endif
                                
                                <x-filament::badge :color="match($date['status']) {
                                    'scheduled' => 'success',
                                    'completed' => 'primary',
                                    'cancelled' => 'danger',
                                    'postponed' => 'warning',
                                    default => 'gray',
                                }">
                                    {{ ucfirst($date['status']) }}
                                </x-filament::badge>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4 text-center">
                    <a href="{{ route('filament.resources.event-dates.index') }}" 
                       class="text-sm text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300">
                        {{ __('View all event dates') }} →
                    </a>
                </div>
            @else
                <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                    <x-heroicon-o-calendar class="w-12 h-12 mx-auto mb-3 opacity-50" />
                    <p>{{ __('No upcoming events scheduled') }}</p>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
