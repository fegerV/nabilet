<script setup lang="ts">
/**
 * Площадки (админка).
 *
 * CRUD через /api/v1/venues (write-роуты под auth:api + admin).
 * Модалка-форма: название, город, адрес, регион, описание, статус.
 *
 * Поле «Страна» из формы убрано по требованию: страна в каталоге пишется
 * сидерами, вручную её править не нужно. Бэкенд поле принимает и без него.
 * Описание площадки живёт здесь (как и адрес); сведения зала — на странице
 * «Залы», конструктор схемы сведениями зала не занимается.
 *
 * Адресация — по `public_id` (ULID), как у залов и событий. Числовой `id`
 * в пути приводился к int, поэтому `/venues/1abc` попадал в площадку 1.
 */
import { computed, ref, onMounted } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NModal from '@/components/ui/NModal.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import NDataTable from '@/components/ui/NDataTable.vue'
import { useUiStore } from '@/stores/ui'
import { get, send } from '@/lib/api'
import { money } from '@/lib/format'
import type { Column } from '@/components/ui/NDataTable.vue'

const ui = useUiStore()

interface ApiVenue {
  id: number
  public_id: string
  name: string
  city?: string | null
  region?: string | null
  country?: string | null
  address?: string | null
  description?: string | null
  status: string
  halls?: unknown[]
}

const venues = ref<ApiVenue[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

/* Форма */
const modalOpen = ref(false)
const saving = ref(false)
const formError = ref<string | null>(null)
const editId = ref<string | null>(null)
const form = ref({ name: '', city: '', region: '', address: '', description: '', status: 'active' })

function openCreate(): void {
  editId.value = null
  form.value = { name: '', city: '', region: '', address: '', description: '', status: 'active' }
  formError.value = null
  modalOpen.value = true
}

function openEdit(row: { raw: ApiVenue }): void {
  const v = row.raw
  editId.value = v.public_id
  form.value = {
    name: v.name ?? '',
    city: v.city ?? '',
    region: v.region ?? '',
    address: v.address ?? '',
    description: v.description ?? '',
    status: v.status ?? 'active',
  }
  formError.value = null
  modalOpen.value = true
}

function closeModal(): void {
  modalOpen.value = false
}

async function save(): Promise<void> {
  if (saving.value) return
  saving.value = true
  formError.value = null
  try {
    const payload = {
      name: form.value.name.trim(),
      city: form.value.city.trim() || null,
      region: form.value.region.trim() || null,
      address: form.value.address.trim() || null,
      description: form.value.description.trim() || null,
      status: form.value.status,
    }
    if (editId.value) {
      await send<unknown>(`/venues/${editId.value}`, 'PATCH', payload)
      ui.notify('mint', 'Площадка обновлена', payload.name)
    } else {
      await send<unknown>('/venues', 'POST', payload)
      ui.notify('mint', 'Площадка создана', payload.name)
    }
    modalOpen.value = false
    await load()
  } catch (e) {
    formError.value = e instanceof Error ? e.message : String(e)
  } finally {
    saving.value = false
  }
}

async function remove(v: ApiVenue): Promise<void> {
  // Залы уходят каскадом (`halls.venue_id` CASCADE), а площадку с сеансами
  // сервер не даст удалить — 409 VENUE_HAS_SESSIONS; сообщение показываем как есть.
  if (!window.confirm(`Удалить площадку «${v.name}»? Вместе с ней удалятся её залы и схемы залов.`)) return
  try {
    await send<unknown>(`/venues/${v.public_id}`, 'DELETE')
    ui.notify('sun', 'Площадка удалена', v.name)
    await load()
  } catch (e) {
    ui.notify('rose', 'Не удалось удалить', e instanceof Error ? e.message : String(e))
  }
}

/* ── Импорт с Яндекс.Афиши ──────────────────────────────────────────────
 * Ссылка на страницу события → разбор JSON-LD сервером (`POST
 * /venues/import-afisha`) → предпросмотр → поля правятся → создание через
 * обычный POST /venues. Схема зала в страницу события не входит (закрытый
 * API Яндекса), поэтому после импорта зал и схему рисуют в конструкторе —
 * см. раздел «Помощь». */
interface AfishaPreview {
  event: {
    title: string | null
    description: string | null
    image: string | null
    starts_at: string | null
    /**
     * Цены — в MINOR UNITS (копейках), как и всё остальное API (ТЗ §3).
     *
     * Было в рублях, причём двумя разными способами: JSON-LD разбирался во
     * `float`, а стейт сеанса — целочисленным делением на сто с отбрасыванием
     * остатка. Правило теперь одно (`Venues\Domain\ExternalPrice`), и наружу
     * уходит общий масштаб, поэтому показывать их надо через `money()`, а не
     * подставлять число как есть.
     */
    price_min: number | null
    price_max: number | null
    price_currency: string | null
  }
  /** Сеанс из SSR-стейта: id нужен админу, чтобы найти карту мест в окне покупки. */
  ticket: {
    id: string | null
    price_min: number | null
    price_max: number | null
    price_currency: string | null
  }
  venue: {
    name: string | null
    city: string | null
    address: string | null
    latitude: number | null
    longitude: number | null
    phones: string[]
  }
}

const importOpen = ref(false)
const importing = ref(false)
const parsing = ref(false)
const importError = ref<string | null>(null)
const importUrl = ref('')
const afisha = ref<AfishaPreview | null>(null)
const importForm = ref({ name: '', city: '', address: '', description: '' })

/**
 * Диапазон цен предпросмотра: minor units → «2 500 ₽–12 000 ₽».
 *
 * Форматирование — только через `money()` (`lib/format.ts`): она принимает
 * копейки и не работает с float, то есть ровно тот масштаб, в котором цены
 * теперь приходят. Подставлять число напрямую нельзя — админ увидел бы
 * «250000», а не «2 500 ₽».
 *
 * Символ подставляется для рубля (основная валюта источника), для остальных
 * кодов показывается сам код: врать знаком «₽» про чужую валюту хуже, чем
 * показать «KZT».
 */
function priceRange(min: number | null, max: number | null, code: string | null): string {
  if (min === null) return '—'

  const currency = code === null || code === 'RUB' ? '₽' : code
  const low = money(min, { currency })

  return max === null || max === min ? low : `${low}–${money(max, { currency })}`
}

function openImport(): void {
  importOpen.value = true
  importError.value = null
  importUrl.value = ''
  afisha.value = null
}

async function parseAfisha(): Promise<void> {
  if (parsing.value || !importUrl.value.trim()) return
  parsing.value = true
  importError.value = null
  try {
    const res = await send<AfishaPreview>('/venues/import-afisha', 'POST', {
      url: importUrl.value.trim(),
    })
    const preview = res.data
    afisha.value = preview
    const by = preview.event.title ? ` — по странице события «${preview.event.title}»` : ''
    importForm.value = {
      name: preview.venue.name ?? '',
      city: preview.venue.city ?? '',
      address: preview.venue.address ?? '',
      description: `Импортировано с Яндекс.Афиши${by}`,
    }
  } catch (e) {
    afisha.value = null
    importError.value = e instanceof Error ? e.message : String(e)
  } finally {
    parsing.value = false
  }
}

async function createImported(): Promise<void> {
  if (importing.value || !afisha.value) return
  if (!importForm.value.name.trim()) {
    importError.value = 'Укажите название площадки'
    return
  }
  importing.value = true
  importError.value = null
  try {
    await send<unknown>('/venues', 'POST', {
      name: importForm.value.name.trim(),
      city: importForm.value.city.trim() || null,
      address: importForm.value.address.trim() || null,
      description: importForm.value.description.trim() || null,
      latitude: afisha.value.venue.latitude,
      longitude: afisha.value.venue.longitude,
      status: 'active',
    })
    ui.notify('mint', 'Площадка импортирована', importForm.value.name)
    importOpen.value = false
    await load()
  } catch (e) {
    importError.value = e instanceof Error ? e.message : String(e)
  } finally {
    importing.value = false
  }
}

const rows = computed(() =>
  venues.value.map((v) => ({
    id: String(v.public_id),
    name: v.name,
    city: v.city ? `${v.city}${v.region ? ', ' + v.region : ''}` : '—',
    address: v.address ?? '—',
    hallsCount: Array.isArray(v.halls) ? v.halls.length : 0,
    status: v.status,
    raw: v,
  })),
)

const COLUMNS: Column[] = [
  { key: 'name', label: 'Площадка', sortable: true },
  { key: 'city', label: 'Город', hideOnMobile: true },
  { key: 'address', label: 'Адрес', hideOnMobile: true },
  { key: 'hallsCount', label: 'Залы', align: 'center', width: '70px' },
  { key: 'status', label: 'Статус', align: 'right', width: '110px' },
  { key: 'actions', label: '', align: 'right', width: '90px' },
]

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const res = await get<{ data: ApiVenue[] }>('/venues?per_page=100')
    const inner = res.data as unknown as { data?: ApiVenue[] } | ApiVenue[]
    venues.value = Array.isArray(inner) ? inner : (inner as { data: ApiVenue[] }).data ?? []
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-content">Площадки</h1>
        <p class="mt-1 text-sm text-muted">
          <template v-if="loading">Загрузка…</template>
          <template v-else>{{ rows.length }} площадок</template>
        </p>
      </div>
      <div class="flex gap-2">
        <NButton variant="secondary" @click="openImport">Импорт с Яндекс.Афиши</NButton>
        <NButton variant="primary" @click="openCreate">Новая площадка</NButton>
      </div>
    </div>

    <div v-if="loadError" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить площадки: {{ loadError }}
    </div>

    <div class="mt-4">
      <NDataTable :columns="COLUMNS" :rows="rows" sort="name" sort-dir="asc" :loading="loading" @row="openEdit">
        <template #cell-name="{ row }">
          <span class="block truncate font-medium text-content">{{ row.name }}</span>
          <span class="block truncate text-2xs text-subtle">ID {{ row.id }}</span>
        </template>
        <template #cell-city="{ row }">
          <span class="truncate text-muted">{{ row.city }}</span>
        </template>
        <template #cell-hallsCount="{ row }">{{ row.hallsCount }}</template>
        <template #cell-status="{ row }">
          <NStatusBadge kind="venue" :status="row.status" />
        </template>
        <!-- Слот действий: клик по строке открывает редактирование, удаление — отдельной кнопкой -->
        <template #cell-actions="{ row }">
          <NButton variant="ghost" size="sm" @click.stop="remove(row.raw)">Удалить</NButton>
        </template>

        <template #mobile-title="{ row }">
          <span class="block text-sm font-medium text-content">{{ row.name }}</span>
          <span class="block text-2xs text-subtle">ID {{ row.id }}</span>
        </template>
        <template #mobile-meta="{ row }">
          <span>{{ row.city }}</span>
          <span>{{ row.hallsCount }} залов</span>
          <NStatusBadge kind="venue" :status="row.status" />
        </template>
      </NDataTable>
    </div>

    <!-- Модалка импорта с Яндекс.Афиши -->
    <NModal :open="importOpen" title="Импорт площадки с Яндекс.Афиши" size="md" @update:open="importOpen = false">
      <p class="mb-3 text-xs text-subtle">
        Вставьте ссылку на страницу СОБЫТИЯ афиши (…/concert/…). Разбираются название площадки, адрес, город,
        координаты и цены события. Схему зала после импорта рисуют в конструкторе схем.
      </p>
      <form @submit.prevent="parseAfisha" class="mb-3 flex gap-2">
        <NInput v-model="importUrl" placeholder="https://afisha.yandex.ru/surgut/concert/…" />
        <NButton type="submit" variant="secondary" :loading="parsing">Разобрать</NButton>
      </form>

      <p v-if="importError" class="mb-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
        {{ importError }}
      </p>

      <template v-if="afisha">
        <div class="mb-3 rounded-lg bg-surface-2 px-3 py-2 text-xs text-muted">
          <template v-if="afisha.event.title">
            Событие: <b class="text-content">{{ afisha.event.title }}</b>
            <span v-if="afisha.event.price_min !== null">
              · цены {{ priceRange(afisha.event.price_min, afisha.event.price_max, afisha.event.price_currency) }}
            </span>
            <div v-if="afisha.ticket.id" class="mt-1">
              Сеанс <b class="text-content">{{ afisha.ticket.id }}</b>
              <span v-if="afisha.ticket.price_min !== null">
                · {{ priceRange(afisha.ticket.price_min, afisha.ticket.price_max, afisha.ticket.price_currency) }}
              </span>
              — по id ищется карта мест в окне покупки
            </div>
            <div v-if="afisha.venue.phones.length" class="mt-1">Телефоны: {{ afisha.venue.phones.join(', ') }}</div>
          </template>
        </div>

        <form class="space-y-3" @submit.prevent="createImported">
          <NInput v-model="importForm.name" label="Название" placeholder="Вавилон" required />
          <NInput v-model="importForm.city" label="Город" placeholder="Сургут" />
          <NInput v-model="importForm.address" label="Адрес" placeholder="ул. Университетская, 55" />
          <NInput v-model="importForm.description" label="Описание" placeholder="Пусто — можно не заполнять" />
          <div class="flex justify-end gap-2 border-t border-line pt-3">
            <NButton variant="secondary" @click="importOpen = false">Отмена</NButton>
            <NButton type="submit" variant="accent" :loading="importing">Создать площадку</NButton>
          </div>
        </form>
      </template>
    </NModal>

    <!-- Модалка формы -->
    <NModal
      :open="modalOpen"
      :title="editId ? 'Редактировать площадку' : 'Новая площадка'"
      size="md"
      @update:open="closeModal"
    >
      <div v-if="formError" class="mb-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
        {{ formError }}
      </div>

      <form class="space-y-3" @submit.prevent="save">
        <NInput v-model="form.name" label="Название" placeholder="ДК «Маяк»" required />
        <NInput v-model="form.city" label="Город" placeholder="Казань" />
        <NInput v-model="form.region" label="Регион" placeholder="Татарстан" />
        <NInput v-model="form.address" label="Адрес" placeholder="ул. Ленина, 12" />
        <NInput v-model="form.description" label="Описание" placeholder="Крупнейшая концертная площадка города" />
        <NSelect v-model="form.status" label="Статус" :options="[{ value: 'active', label: 'Активна' }, { value: 'inactive', label: 'Не активна' }]" />

        <div class="flex justify-end gap-2 border-t border-line pt-3">
          <NButton variant="secondary" @click="closeModal">Отмена</NButton>
          <NButton type="submit" variant="accent" :loading="saving">
            {{ editId ? 'Сохранить' : 'Создать' }}
          </NButton>
        </div>
      </form>
    </NModal>
  </div>
</template>