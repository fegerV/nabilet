<script setup lang="ts">
/**
 * Залы (админка).
 *
 * Список залов из /api/v1/halls; клик по строке → редактор схемы зала
 * (/#/admin/halls/{publicId}/editor).
 *
 * Сведения о зале (название, город, адрес, описание, фото снаружи/внутри)
 * правятся здесь — в модалке «Изменить зал». Конструктор схемы (редактор)
 * сведениями зала больше не занимается: там — только схема мест.
 */
import { computed, ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import NDataTable from '@/components/ui/NDataTable.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NModal from '@/components/ui/NModal.vue'
import { get, send } from '@/lib/api'
import type { Column } from '@/components/ui/NDataTable.vue'

const router = useRouter()

interface ApiHall {
  id: number
  public_id: string
  name: string
  venue_id?: number
  status?: string
  capacity?: number | null
  venue?: { id: number; name: string } | null
  city?: string | null
  address?: string | null
  description?: string | null
  exterior_photo_url?: string | null
  interior_photo_url?: string | null
}

const halls = ref<ApiHall[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

const rows = computed(() =>
  halls.value.map((h) => ({
    id: String(h.id),
    publicId: h.public_id,
    name: h.name,
    venue: h.venue?.name ?? '—',
    capacity: h.capacity ?? '—',
    status: h.status ?? 'active',
    raw: h,
  })),
)

const COLUMNS: Column[] = [
  { key: 'name', label: 'Зал', sortable: true },
  { key: 'venue', label: 'Площадка', hideOnMobile: true },
  { key: 'capacity', label: 'Вместимость', align: 'right', width: '120px' },
  { key: 'status', label: 'Статус', align: 'right', width: '110px' },
  { key: 'actions', label: '', align: 'right', width: '90px' },
]

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const res = await get<{ data: ApiHall[] }>('/halls')
    const inner = res.data
    halls.value = Array.isArray(inner) ? inner : (inner as { data: ApiHall[] }).data ?? []
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  load()
  loadVenues()
})

function openEditor(row: { raw: ApiHall }): void {
  router.push(`/admin/halls/${row.raw.public_id}/editor`)
}

/* ── Создание зала (§54: город/адрес/описание/фото) ────────────────── */

interface ApiVenue {
  id: number
  public_id?: string
  name: string
}

const showCreate = ref(false)
const creating = ref(false)
const createError = ref<string | null>(null)
const venues = ref<ApiVenue[]>([])
const createForm = ref({
  name: '',
  venue_id: '' as string,
  capacity: '' as string,
  city: '',
  address: '',
  description: '',
  exterior_photo_url: '',
  interior_photo_url: '',
})

async function loadVenues(): Promise<void> {
  try {
    const res = await get<{ data: ApiVenue[] }>('/venues')
    const inner = res.data
    venues.value = Array.isArray(inner) ? inner : (Array.isArray((inner as { data?: ApiVenue[] }).data) ? (inner as { data: ApiVenue[] }).data! : [])
  } catch {
    venues.value = []
  }
}

function venueOptions(): { value: string; label: string }[] {
  return venues.value.map((v) => ({ value: String(v.id), label: v.name }))
}

async function createHall(): Promise<void> {
  createError.value = null
  if (!createForm.value.name.trim()) {
    createError.value = 'Укажите название зала'
    return
  }
  if (!createForm.value.venue_id) {
    createError.value = 'Выберите площадку'
    return
  }
  creating.value = true
  try {
    await send('/halls', 'POST', {
      name: createForm.value.name.trim(),
      venue_id: Number(createForm.value.venue_id),
      capacity: createForm.value.capacity ? Number(createForm.value.capacity) : null,
      city: createForm.value.city.trim() || null,
      address: createForm.value.address.trim() || null,
      description: createForm.value.description.trim() || null,
      exterior_photo_url: createForm.value.exterior_photo_url.trim() || null,
      interior_photo_url: createForm.value.interior_photo_url.trim() || null,
    })
    showCreate.value = false
    createForm.value = {
      name: '', venue_id: '', capacity: '', city: '', address: '',
      description: '', exterior_photo_url: '', interior_photo_url: '',
    }
    await load()
  } catch (e) {
    createError.value = e instanceof Error ? e.message : String(e)
  } finally {
    creating.value = false
  }
}

/* ── Изменение зала (сведения: название/город/адрес/описание/фото) ──── */

interface EditableHall {
  name: string
  city: string
  address: string
  description: string
  capacity: string
  exterior_photo_url: string
  interior_photo_url: string
}

const editOpen = ref(false)
const editSaving = ref(false)
const editError = ref<string | null>(null)
const editPublicId = ref<string | null>(null)
const editForm = ref<EditableHall>({
  name: '', city: '', address: '', description: '',
  capacity: '', exterior_photo_url: '', interior_photo_url: '',
})

async function openEdit(row: { raw: ApiHall }): Promise<void> {
  editPublicId.value = row.raw.public_id
  editError.value = null
  editForm.value = {
    name: row.raw.name,
    city: row.raw.city ?? '',
    address: row.raw.address ?? '',
    description: row.raw.description ?? '',
    capacity: row.raw.capacity != null ? String(row.raw.capacity) : '',
    exterior_photo_url: row.raw.exterior_photo_url ?? '',
    interior_photo_url: row.raw.interior_photo_url ?? '',
  }
  editOpen.value = true
}

async function saveEdit(): Promise<void> {
  if (!editPublicId.value || editSaving.value) return
  if (!editForm.value.name.trim()) {
    editError.value = 'Укажите название зала'
    return
  }
  editSaving.value = true
  editError.value = null
  try {
    await send(`/halls/${editPublicId.value}`, 'PUT', {
      name: editForm.value.name.trim(),
      city: editForm.value.city.trim() || null,
      address: editForm.value.address.trim() || null,
      description: editForm.value.description.trim() || null,
      capacity: editForm.value.capacity ? Number(editForm.value.capacity) : null,
      exterior_photo_url: editForm.value.exterior_photo_url.trim() || null,
      interior_photo_url: editForm.value.interior_photo_url.trim() || null,
    })
    editOpen.value = false
    await load()
  } catch (e) {
    editError.value = e instanceof Error ? e.message : String(e)
  } finally {
    editSaving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-content">Залы</h1>
        <p class="mt-1 text-sm text-muted">
          <template v-if="loading">Загрузка…</template>
          <template v-else>{{ rows.length }} залов</template>
        </p>
      </div>
      <NButton variant="primary" @click="showCreate = !showCreate">
        {{ showCreate ? 'Отмена' : 'Создать зал' }}
      </NButton>
    </div>

    <div v-if="showCreate" class="surface-card mt-4 p-4">
      <h2 class="mb-3 text-sm font-semibold text-content">Новый зал</h2>
      <div class="grid gap-3 sm:grid-cols-2">
        <NInput v-model="createForm.name" label="Название зала" placeholder="Большой зал" />
        <NSelect
          :model-value="createForm.venue_id"
          :options="venueOptions()"
          label="Площадка"
          placeholder="Выберите площадку"
          @update:model-value="(v: string) => createForm.venue_id = v"
        />
        <NInput v-model="createForm.city" label="Город" placeholder="Москва" />
        <NInput v-model="createForm.address" label="Адрес" placeholder="ул. Тверская, 1" />
        <NInput v-model="createForm.capacity" label="Вместимость" type="number" :min="0" placeholder="400" />
        <NInput v-model="createForm.exterior_photo_url" label="Фото снаружи (URL)" placeholder="https://…" />
        <NInput v-model="createForm.interior_photo_url" label="Фото внутри (URL)" placeholder="https://…" />
      </div>
      <NInput v-model="createForm.description" label="Описание" placeholder="Описание зала" class="mt-3" />
      <p v-if="createError" class="mt-3 text-sm text-rose-400">{{ createError }}</p>
      <NButton variant="primary" class="mt-3" :disabled="creating" @click="createHall">
        {{ creating ? 'Создаём…' : 'Создать зал' }}
      </NButton>
    </div>

    <div v-if="loadError" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить залы: {{ loadError }}
    </div>

    <div class="mt-4">
      <NDataTable :columns="COLUMNS" :rows="rows" sort="name" sort-dir="asc" :loading="loading" @row="openEditor">
        <template #cell-name="{ row }">
          <span class="block truncate font-medium text-content">{{ row.name }}</span>
          <span class="block truncate text-2xs text-subtle">ID {{ row.id }}</span>
        </template>
        <template #cell-venue="{ row }">
          <span class="truncate text-muted">{{ row.venue }}</span>
        </template>
        <template #cell-capacity="{ row }">
          <span class="tabular-nums text-muted">{{ row.capacity }}</span>
        </template>
        <template #cell-status="{ row }">
          <NStatusBadge kind="hall" :status="row.status" />
        </template>
        <!-- Клик по строке открывает конструктор схемы, правка сведений — отдельной кнопкой -->
        <template #cell-actions="{ row }">
          <NButton variant="ghost" size="sm" @click.stop="openEdit(row)">Изменить</NButton>
        </template>

        <template #mobile-title="{ row }">
          <span class="block text-sm font-medium text-content">{{ row.name }}</span>
        </template>
        <template #mobile-meta="{ row }">
          <span>{{ row.venue }}</span>
          <NStatusBadge kind="hall" :status="row.status" />
          <NButton variant="ghost" size="sm" @click.stop="openEdit(row)">Изменить</NButton>
        </template>
      </NDataTable>
    </div>

    <!-- Модалка правки сведений о зале -->
    <NModal :open="editOpen" title="Изменить зал" size="md" @update:open="editOpen = false">
      <p class="mb-3 text-xs text-subtle">
        Адрес, описание и фото хранятся здесь. Схема мест правится в конструкторе схемы (клик по строке).
      </p>
      <p v-if="editError" class="mb-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-400">
        {{ editError }}
      </p>
      <form class="space-y-3" @submit.prevent="saveEdit">
        <NInput v-model="editForm.name" label="Название зала" placeholder="Большой зал" required />
        <div class="grid grid-cols-2 gap-2">
          <NInput v-model="editForm.city" label="Город" placeholder="Москва" />
          <NInput v-model="editForm.capacity" label="Вместимость" type="number" :min="0" placeholder="400" />
        </div>
        <NInput v-model="editForm.address" label="Адрес" placeholder="ул. Тверская, 1" />
        <NInput v-model="editForm.description" label="Описание" placeholder="Описание зала" />
        <NInput v-model="editForm.exterior_photo_url" label="Фото снаружи (URL)" placeholder="https://…" />
        <NInput v-model="editForm.interior_photo_url" label="Фото внутри (URL)" placeholder="https://…" />
        <div class="flex justify-end gap-2 border-t border-line pt-3">
          <NButton variant="secondary" @click="editOpen = false">Отмена</NButton>
          <NButton type="submit" variant="accent" :loading="editSaving">Сохранить</NButton>
        </div>
      </form>
    </NModal>
  </div>
</template>