<script setup lang="ts">
/**
 * Инспектор секции витрины: поля выбранного виджета.
 *
 * Поля меняются по типу секции — держать их в одной форме значило бы
 * показывать организатору «число колонок» у героя. Черновик правится
 * напрямую (объект конфига реактивен), поэтому предпросмотр обновляется
 * на каждое нажатие клавиши, без «Применить».
 */
import { computed } from 'vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NCheckbox from '@/components/ui/NCheckbox.vue'
import NButton from '@/components/ui/NButton.vue'
import { useCatalogStore } from '@/stores/catalog'
import { WIDGET_LABELS, setting, type StorefrontSection } from '@/lib/storefront'

const props = defineProps<{ section: StorefrontSection }>()

const catalog = useCatalogStore()
void catalog.load()

const settings = computed(() => props.section.settings)

function get<T>(key: string, fallback: T): T {
  return setting<T>(settings.value, key, fallback)
}

function set(key: string, value: unknown): void {
  settings.value[key] = value
}

/* ── Списковые поля ─────────────────────────────────────────────────────── */

interface LabeledItem {
  label: string
  value: string
  icon?: string
  query?: string
}

interface FaqItem {
  q: string
  a: string
}

function listItems<T>(key: string): T[] {
  const value = settings.value[key]
  return Array.isArray(value) ? (value as T[]) : []
}

function writeList(key: string, items: unknown[]): void {
  set(key, items)
}

function addItem(key: string, blank: Record<string, string>): void {
  writeList(key, [...listItems(key), blank])
}

function removeItem(key: string, index: number): void {
  writeList(key, listItems(key).filter((_, i) => i !== index))
}

function patchItem<T extends Record<string, unknown>>(key: string, index: number, patch: Partial<T>): void {
  const items = listItems<T>(key).map((item, i) => (i === index ? { ...item, ...patch } : item))
  writeList(key, items)
}

/* ── Выбор событий для ручной подборки ──────────────────────────────────── */

const pickedIds = computed(() => get<string[]>('ids', []))

function toggleEvent(slug: string | undefined, id: string): void {
  const key = slug || id
  const next = pickedIds.value.includes(key) ? pickedIds.value.filter((v) => v !== key) : [...pickedIds.value, key]
  set('ids', next)
}

const OPTIONS = {
  align: [
    { value: 'left', label: 'По левому краю' },
    { value: 'center', label: 'По центру' },
  ],
  height: [
    { value: 'compact', label: 'Компактный' },
    { value: 'medium', label: 'Средний' },
    { value: 'tall', label: 'Высокий' },
  ],
  layout: [
    { value: 'grid', label: 'Сетка' },
    { value: 'list', label: 'Список' },
  ],
  carousel: [
    { value: 'carousel', label: 'Карусель' },
    { value: 'grid', label: 'Сетка' },
  ],
  columns: [
    { value: '2', label: '2 колонки' },
    { value: '3', label: '3 колонки' },
    { value: '4', label: '4 колонки' },
  ],
  sort: [
    { value: 'date', label: 'По дате' },
    { value: 'price', label: 'По цене' },
    { value: 'title', label: 'По названию' },
  ],
  source: [
    { value: 'upcoming', label: 'Ближайшие события' },
    { value: 'manual', label: 'Выбрать вручную' },
  ],
  featuredSource: [
    { value: 'upcoming', label: 'Ближайшие события' },
    { value: 'manual', label: 'Выбранные вручную' },
  ],
  tiles: [
    { value: 'chips', label: 'Чипсы' },
    { value: 'tiles', label: 'Плитки' },
  ],
  tone: [
    { value: 'brand', label: 'Бренд' },
    { value: 'accent', label: 'Акцент' },
    { value: 'dark', label: 'Тёмный' },
  ],
  width: [
    { value: 'wide', label: 'Во всю ширину' },
    { value: 'narrow', label: 'Узкая колонка' },
  ],
  categoriesSource: [
    { value: 'auto', label: 'Из мероприятий' },
    { value: 'manual', label: 'Свой список' },
  ],
  statsSource: [
    { value: 'auto', label: 'Считать по афише' },
    { value: 'manual', label: 'Свои значения' },
  ],
}
</script>

<template>
  <div class="space-y-4">
    <!-- Общие поля -->
    <NInput
      :model-value="section.title"
      label="Заголовок секции"
      placeholder="Например: Ближайшие премьеры"
      @update:model-value="section.title = $event"
    />
    <NInput
      :model-value="section.subtitle"
      label="Подзаголовок"
      placeholder="Короткое пояснение под заголовком"
      @update:model-value="section.subtitle = $event"
    />

    <div class="border-t border-line pt-4">
      <p class="mb-2.5 text-xs font-semibold uppercase tracking-wide text-subtle">
        Настройки виджета «{{ WIDGET_LABELS[section.type] ?? section.type }}»
      </p>

      <!-- Герой -->
      <template v-if="section.type === 'hero'">
        <div class="space-y-4">
          <NInput :model-value="get('badge', '')" label="Плашка над заголовком" placeholder="Билеты без наценки" @update:model-value="set('badge', $event)" />
          <NInput :model-value="get('title', '')" label="Заголовок" @update:model-value="set('title', $event)" />
          <NInput :model-value="get('subtitle', '')" label="Подзаголовок" @update:model-value="set('subtitle', $event)" />
          <div class="grid gap-3 sm:grid-cols-2">
            <NInput :model-value="get('ctaLabel', '')" label="Кнопка: подпись" @update:model-value="set('ctaLabel', $event)" />
            <NInput :model-value="get('ctaLink', '/')" label="Кнопка: ссылка" hint="/ или https://" @update:model-value="set('ctaLink', $event)" />
          </div>
          <NInput :model-value="get('image', '')" label="Фон (URL картинки)" placeholder="https://…" @update:model-value="set('image', $event)" />
          <div class="grid gap-3 sm:grid-cols-2">
            <NSelect :model-value="get('align', 'left')" label="Выравнивание" :options="OPTIONS.align" @update:model-value="set('align', $event)" />
            <NSelect :model-value="get('height', 'medium')" label="Высота" :options="OPTIONS.height" @update:model-value="set('height', $event)" />
          </div>
          <NCheckbox :model-value="get('showSearch', false)" label="Показывать поиск" description="Строка поиска прямо в герое" @update:model-value="set('showSearch', $event)" />
        </div>
      </template>

      <!-- Афиша -->
      <template v-else-if="section.type === 'posters'">
        <div class="space-y-4">
          <div class="grid gap-3 sm:grid-cols-2">
            <NSelect :model-value="get('layout', 'grid')" label="Раскладка" :options="OPTIONS.layout" @update:model-value="set('layout', $event)" />
            <NSelect :model-value="String(get('columns', 3))" label="Колонок" :options="OPTIONS.columns" @update:model-value="set('columns', Number($event))" />
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <NInput :model-value="String(get('limit', 12))" label="Сколько событий" type="number" min="1" max="60" @update:model-value="set('limit', Number($event))" />
            <NSelect :model-value="get('sort', 'date')" label="Сортировка" :options="OPTIONS.sort" @update:model-value="set('sort', $event)" />
          </div>
          <NInput :model-value="get('category', '')" label="Только категория" hint="Пусто — все категории" @update:model-value="set('category', $event)" />
          <NCheckbox :model-value="get('showFilters', true)" label="Показывать фильтр по категориям" @update:model-value="set('showFilters', $event)" />
        </div>
      </template>

      <!-- Подборка -->
      <template v-else-if="section.type === 'featured'">
        <div class="space-y-4">
          <div class="grid gap-3 sm:grid-cols-2">
            <NSelect :model-value="get('source', 'upcoming')" label="Источник" :options="OPTIONS.featuredSource" @update:model-value="set('source', $event)" />
            <NSelect :model-value="get('layout', 'carousel')" label="Раскладка" :options="OPTIONS.carousel" @update:model-value="set('layout', $event)" />
          </div>
          <NInput :model-value="String(get('limit', 6))" label="Сколько событий" type="number" min="1" max="24" @update:model-value="set('limit', Number($event))" />

          <div v-if="get<string>('source', 'upcoming') === 'manual'">
            <p class="mb-2 text-sm text-content">Выберите события</p>
            <div class="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-line p-2">
              <label
                v-for="event in catalog.events"
                :key="event.id"
                class="flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-sm hover:bg-surface-2"
              >
                <input
                  type="checkbox"
                  class="h-4 w-4 rounded border-line-strong"
                  :checked="pickedIds.includes(event.slug || event.id)"
                  @change="toggleEvent(event.slug, event.id)"
                />
                <span class="min-w-0 flex-1 truncate text-content">{{ event.title }}</span>
              </label>
              <p v-if="!catalog.events.length" class="px-2 py-3 text-xs text-subtle">Нет опубликованных событий.</p>
            </div>
          </div>
        </div>
      </template>

      <!-- Категории -->
      <template v-else-if="section.type === 'categories'">
        <div class="space-y-4">
          <div class="grid gap-3 sm:grid-cols-2">
            <NSelect :model-value="get('source', 'auto')" label="Источник" :options="OPTIONS.categoriesSource" @update:model-value="set('source', $event)" />
            <NSelect :model-value="get('style', 'chips')" label="Вид" :options="OPTIONS.tiles" @update:model-value="set('style', $event)" />
          </div>

          <div v-if="get<string>('source', 'auto') === 'manual'">
            <p class="mb-2 text-sm text-content">Рубрики</p>
            <div v-for="(item, index) in listItems<LabeledItem>('items')" :key="index" class="mb-2 flex items-start gap-2">
              <NInput :model-value="item.icon ?? ''" class="max-w-[4.5rem]" placeholder="★" aria-label="Иконка" @update:model-value="patchItem('items', index, { icon: $event })" />
              <NInput :model-value="item.label" placeholder="Название рубрики" class="flex-1" @update:model-value="patchItem('items', index, { label: $event })" />
              <NButton variant="ghost" size="sm" aria-label="Удалить рубрику" @click="removeItem('items', index)">✕</NButton>
            </div>
            <NButton variant="secondary" size="sm" icon="+" @click="addItem('items', { label: '', icon: '', query: '' })">
              Добавить рубрику
            </NButton>
          </div>
        </div>
      </template>

      <!-- Отсчёт -->
      <template v-else-if="section.type === 'countdown'">
        <div class="space-y-4">
          <NInput :model-value="get('label', 'До начала')" label="Подпись" @update:model-value="set('label', $event)" />
          <NSelect
            :model-value="get('eventId', '')"
            label="Событие"
            :options="[
              { value: '', label: 'Ближайшее событие' },
              ...catalog.events.map((e) => ({ value: e.slug || e.id, label: e.title })),
            ]"
            @update:model-value="set('eventId', $event)"
          />
          <NInput
            :model-value="get('target', '')"
            label="Или своя дата"
            type="datetime-local"
            hint="Перекрывает выбор события"
            @update:model-value="set('target', $event)"
          />
        </div>
      </template>

      <!-- Промо -->
      <template v-else-if="section.type === 'promo'">
        <div class="space-y-4">
          <NSelect :model-value="get('tone', 'brand')" label="Цвет плашки" :options="OPTIONS.tone" @update:model-value="set('tone', $event)" />
          <NInput :model-value="get('title', '')" label="Заголовок" @update:model-value="set('title', $event)" />
          <NInput :model-value="get('text', '')" label="Текст" @update:model-value="set('text', $event)" />
          <div class="grid gap-3 sm:grid-cols-2">
            <NInput :model-value="get('buttonLabel', '')" label="Кнопка: подпись" @update:model-value="set('buttonLabel', $event)" />
            <NInput :model-value="get('buttonLink', '/')" label="Кнопка: ссылка" @update:model-value="set('buttonLink', $event)" />
          </div>
          <NInput :model-value="get('code', '')" label="Промокод" hint="Показывается плашкой, копируется по клику" @update:model-value="set('code', $event)" />
        </div>
      </template>

      <!-- Текст -->
      <template v-else-if="section.type === 'richtext'">
        <div class="space-y-4">
          <label class="block">
            <span class="mb-1.5 block text-sm font-medium text-content">Текст</span>
            <textarea
              :value="get('text', '')"
              rows="6"
              class="w-full rounded-lg border border-line bg-surface px-3.5 py-2.5 text-sm text-content placeholder:text-subtle focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/25"
              placeholder="О площадке, правила посещения, оферта…"
              @input="set('text', ($event.target as HTMLTextAreaElement).value)"
            />
          </label>
          <div class="grid gap-3 sm:grid-cols-2">
            <NSelect :model-value="get('align', 'left')" label="Выравнивание" :options="OPTIONS.align" @update:model-value="set('align', $event)" />
            <NSelect :model-value="get('width', 'wide')" label="Ширина" :options="OPTIONS.width" @update:model-value="set('width', $event)" />
          </div>
        </div>
      </template>

      <!-- Вопросы -->
      <template v-else-if="section.type === 'faq'">
        <div class="space-y-3">
          <div v-for="(item, index) in listItems<FaqItem>('items')" :key="index" class="rounded-lg border border-line p-3">
            <div class="flex items-start gap-2">
              <NInput :model-value="item.q" placeholder="Вопрос" class="flex-1" @update:model-value="patchItem('items', index, { q: $event })" />
              <NButton variant="ghost" size="sm" aria-label="Удалить вопрос" @click="removeItem('items', index)">✕</NButton>
            </div>
            <textarea
              :value="item.a"
              rows="3"
              placeholder="Ответ"
              class="mt-2 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-content focus:border-brand-400 focus:outline-none"
              @input="patchItem('items', index, { a: ($event.target as HTMLTextAreaElement).value })"
            />
          </div>
          <NButton variant="secondary" size="sm" icon="+" @click="addItem('items', { q: '', a: '' })">Добавить вопрос</NButton>
        </div>
      </template>

      <!-- Цифры -->
      <template v-else-if="section.type === 'stats'">
        <div class="space-y-4">
          <NSelect :model-value="get('source', 'auto')" label="Источник" :options="OPTIONS.statsSource" @update:model-value="set('source', $event)" />
          <div v-if="get<string>('source', 'auto') === 'manual'">
            <div v-for="(item, index) in listItems<LabeledItem>('items')" :key="index" class="mb-2 flex items-start gap-2">
              <NInput :model-value="item.value" class="max-w-[6rem]" placeholder="42" @update:model-value="patchItem('items', index, { value: $event })" />
              <NInput :model-value="item.label" class="flex-1" placeholder="Подпись" @update:model-value="patchItem('items', index, { label: $event })" />
              <NButton variant="ghost" size="sm" aria-label="Удалить показатель" @click="removeItem('items', index)">✕</NButton>
            </div>
            <NButton variant="secondary" size="sm" icon="+" @click="addItem('items', { label: '', value: '' })">
              Добавить показатель
            </NButton>
          </div>
        </div>
      </template>

      <!-- Подписка -->
      <template v-else-if="section.type === 'subscribe'">
        <div class="space-y-4">
          <NInput :model-value="get('title', '')" label="Заголовок" @update:model-value="set('title', $event)" />
          <NInput :model-value="get('text', '')" label="Текст" @update:model-value="set('text', $event)" />
          <div class="grid gap-3 sm:grid-cols-2">
            <NInput :model-value="get('placeholder', 'E-mail')" label="Подсказка в поле" @update:model-value="set('placeholder', $event)" />
            <NInput :model-value="get('buttonLabel', 'Подписаться')" label="Кнопка" @update:model-value="set('buttonLabel', $event)" />
          </div>
          <NInput :model-value="get('privacy', '')" label="Строка согласия" @update:model-value="set('privacy', $event)" />
        </div>
      </template>

      <p v-else class="text-sm text-subtle">У этого виджета нет дополнительных настроек.</p>
    </div>
  </div>
</template>
