<x-filament-panels::page
    @class([
        'fi-resource-edit-record-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
        'fi-resource-record-' . $record->getKey(),
    ])
>
    {{-- ============================================================
         ЧЕК-ЛИСТ ГОТОВНОСТИ К ПУБЛИКАЦИИ
         ============================================================ --}}
    <div class="mb-6 rounded-xl border border-line bg-surface p-4">
        <div class="flex items-center gap-2 font-medium">
            <x-heroicon-o-document-check class="w-6 h-6 text-primary-500 shrink-0" />
            <h2 class="text-lg font-semibold">✅ Чек-лист перед публикацией</h2>
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
            Проверьте готовность мероприятия к продаже. Нажмите «Проверить» —
            система покажет, что заполнено, а что нужно доделать.
        </p>

        <div class="mt-3">
            <x-filament::button
                wire:click="runPublishChecklist"
                color="primary"
                icon="heroicon-o-play"
            >
                {{ __('Проверить готовность') }}
            </x-filament::button>
        </div>

        @if(isset($checklistStatus) && count($checklistStatus) > 0)
            <div class="mt-4 space-y-2">
                @foreach($checklistStatus as $item)
                    <div class="flex items-start gap-3 p-3 rounded-lg border {{ $item['ok'] ? 'border-success-400/50 bg-success-50/50' : 'border-warning-400/50 bg-warning-50/50' }}">
                        @if($item['ok'])
                            <x-heroicon-o-check-circle class="w-5 h-5 text-success-500 shrink-0" />
                        @else
                            <x-heroicon-o-x-circle class="w-5 h-5 text-warning-500 shrink-0" />
                        @endif
                        <div class="flex-1">
                            <p class="font-medium text-sm">{{ $item['title'] }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $item['detail'] }}</p>
                        </div>
                    </div>
                @endforeach

                @if($checklistOk)
                    <div class="p-3 rounded-lg bg-success-50 border border-success-400/50">
                        <p class="font-medium text-sm text-success-700">🎉 Всё готово! Можно публиковать — установите статус Published и сохраните.</p>
                    </div>
                @else
                    <div class="p-3 rounded-lg bg-warning-50 border border-warning-400/50">
                        <p class="font-medium text-sm text-warning-700">Незакрытых пунктов: {{ count(collect($checklistStatus)->filter(fn($i) => !$i['ok'])->toArray()) }}. Доделайте их перед публикацией.</p>
                    </div>
                @endif
            </div>
        @endif
    </div>

    {{-- ============================================================
         ФОРМА РЕДАКТИРОВАНИЯ (стандартная)
         ============================================================ --}}
    @capture($form)
        <x-filament-panels::form
            id="form"
            :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
            wire:submit="save"
        >
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>
    @endcapture

    @php
        $relationManagers = $this->getRelationManagers();
        $hasCombinedRelationManagerTabsWithContent = $this->hasCombinedRelationManagerTabsWithContent();
    @endphp

    @if ((! $hasCombinedRelationManagerTabsWithContent) || (! count($relationManagers)))
        {{ $form() }}
    @endif

    @if (count($relationManagers))
        <x-filament-panels::resources.relation-managers
            :active-locale="isset($activeLocale) ? $activeLocale : null"
            :active-manager="$this->activeRelationManager ?? ($hasCombinedRelationManagerTabsWithContent ? null : array_key_first($relationManagers))"
            :content-tab-label="$this->getContentTabLabel()"
            :content-tab-icon="$this->getContentTabIcon()"
            :content-tab-position="$this->getContentTabPosition()"
            :managers="$relationManagers"
            :owner-record="$record"
            :page-class="static::class"
        >
            @if ($hasCombinedRelationManagerTabsWithContent)
                <x-slot name="content">
                    {{ $form() }}
                </x-slot>
            @endif
        </x-filament-panels::resources.relation-managers>
    @endif

    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>