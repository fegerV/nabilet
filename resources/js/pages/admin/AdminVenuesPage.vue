<script setup lang="ts">
/**
 * Площадки (админка).
 *
 * CRUD через /api/v1/venues (write-роуты под auth:sanctum + admin).
 * Модалка-форма: название, город, адрес, регион, страна, статус.
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
import type { Column } from '@/components/ui/NDataTable.vue'

const ui = useUiStore()

interface ApiVenue {
  id: number
  name: string
  city?: string | null
  region?: string | null
  country?: string | null
  address?: string | null
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
const editId = ref<number | null>(null)
const form = ref({ name: '', city: '', region: '', country: '', address: '', status: 'active' })

function openCreate(): void {
  editId.value = null
  form.value = { name: '', city: '', region: '', country: '', address: '', status: 'active' }
  formError.value = null
  modalOpen.value = true
}

function openEdit(row: { raw: ApiVenue }): void {
  const v = row.raw
  editId.value = v.id
  form.value = {
    name: v.name ?? '',
    city: v.city ?? '',
    region: v.region ?? '',
    country: v.country ?? '',
    address: v.address ?? '',
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
      country: form.value.country.trim() || null,
      address: form.value.address.trim() || null,
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
  if (!window.confirm(`Удалить площадку «${v.name}»? Связанные залы останутся.`)) return
  try {
    await send<unknown>(`/venues/${v.id}`, 'DELETE')
    ui.notify('sun', 'Площадка удалена', v.name)
    await load()
  } catch (e) {
    ui.notify('rose', 'Не удалось удалить', e instanceof Error ? e.message : String(e))
  }
}

const rows = computed(() =>
  venues.value.map((v) => ({
    id: String(v.id),
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
      <NButton variant="primary" @click="openCreate">Новая площадка</NButton>
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

        <!-- Действия в строке: редактировать / удалить — иконки не видны на мобильном, там есть мобильная карточка -->
        <template v-if="false" />
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
        <NInput v-model="form.country" label="Страна" placeholder="Россия" />
        <NInput v-model="form.address" label="Адрес" placeholder="ул. Ленина, 12" />
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