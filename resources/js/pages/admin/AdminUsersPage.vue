<script setup lang="ts">
/**
 * Пользователи (админка).
 *
 * Сотрудники площадки: кто имеет доступ и с какой ролью. Создание — сразу
 * здесь, иначе администратор уходит в консоль и создаёт пользователя с
 * левым паролем. Пароль не показываем никогда: поле только на запись.
 */
import { computed, onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import NDataTable from '@/components/ui/NDataTable.vue'
import NModal from '@/components/ui/NModal.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { get, send } from '@/lib/api'
import { dateTime } from '@/lib/format'
import type { Column } from '@/components/ui/NDataTable.vue'

interface ApiUser {
  id: string
  public_id?: string
  email: string
  first_name?: string | null
  last_name?: string | null
  phone?: string | null
  status?: string | null
  created_at?: string | null
  roles?: Array<{ name?: string } | string>
}

const ui = useUiStore()

const users = ref<ApiUser[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const search = ref('')

const rows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return users.value
    .filter(
      (u) =>
        !q ||
        u.email.toLowerCase().includes(q) ||
        `${u.first_name ?? ''} ${u.last_name ?? ''}`.toLowerCase().includes(q),
    )
    .map((u) => ({
      id: u.id,
      name: [u.first_name, u.last_name].filter(Boolean).join(' ') || '—',
      email: u.email,
      role: roleLabel(u),
      createdAt: u.created_at ?? '',
      status: u.status ?? 'active',
      raw: u,
    }))
})

const COLUMNS: Column[] = [
  { key: 'name', label: 'Пользователь', sortable: true },
  { key: 'email', label: 'E-mail', sortable: true },
  { key: 'role', label: 'Роль', sortable: true, width: '150px' },
  { key: 'createdAt', label: 'Создан', hideOnMobile: true },
  { key: 'status', label: 'Статус', align: 'right', width: '150px' },
]

function roleLabel(user: ApiUser): string {
  const names = (user.roles ?? []).map((r) => (typeof r === 'string' ? r : r.name ?? ''))
  return names.filter(Boolean).join(', ') || '—'
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await get<{ data: ApiUser[] }>('/users?limit=100')
    const inner = res.data as unknown as { data?: ApiUser[] } | ApiUser[]
    users.value = Array.isArray(inner) ? inner : (inner as { data: ApiUser[] }).data ?? []
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(load)

/* ── Создание пользователя ──────────────────────────────────────────────── */

const createOpen = ref(false)
const creating = ref(false)
const formError = ref<string | null>(null)
const form = ref({ email: '', password: '', first_name: '', last_name: '', phone: '' })

function openCreate(): void {
  form.value = { email: '', password: '', first_name: '', last_name: '', phone: '' }
  formError.value = null
  createOpen.value = true
}

async function createUser(): Promise<void> {
  if (creating.value) return
  if (!form.value.email.includes('@')) {
    formError.value = 'Укажите корректный e-mail.'
    return
  }
  if (form.value.password.length < 8) {
    formError.value = 'Пароль короче 8 символов.'
    return
  }
  creating.value = true
  formError.value = null
  try {
    await send<unknown>('/users', 'POST', form.value)
    ui.notify('mint', 'Пользователь создан', form.value.email)
    createOpen.value = false
    await load()
  } catch (e) {
    formError.value = e instanceof Error ? e.message : String(e)
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-content">Пользователи</h1>
        <p class="mt-1 text-sm text-muted">
          <template v-if="loading">Загрузка…</template>
          <template v-else>{{ rows.length }} из {{ users.length }}</template>
        </p>
      </div>
      <NButton variant="primary" icon="+" @click="openCreate">Добавить пользователя</NButton>
    </div>

    <div v-if="error" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить пользователей: {{ error }}
    </div>

    <div class="surface-card mt-5 flex flex-wrap items-end gap-3 p-3">
      <NInput v-model="search" placeholder="Имя или e-mail" icon="⌕" class="max-w-xs" aria-label="Поиск по пользователям" />
    </div>

    <div class="mt-4">
      <NDataTable
        v-if="rows.length"
        :columns="COLUMNS"
        :rows="rows"
        sort="createdAt"
        sort-dir="desc"
        :loading="loading"
      >
        <template #cell-name="{ row }">
          <span class="block text-content">{{ row.name }}</span>
          <span class="block text-2xs text-subtle">{{ dateTime(row.createdAt) }}</span>
        </template>
        <template #cell-email="{ row }">
          <span class="text-muted">{{ row.email }}</span>
        </template>
        <template #cell-role="{ row }">
          <span class="text-sm capitalize text-muted">{{ row.role }}</span>
        </template>
        <template #cell-createdAt="{ row }">
          <span class="text-sm text-muted">{{ dateTime(row.createdAt) }}</span>
        </template>
        <template #cell-status="{ row }">
          <NStatusBadge kind="user" :status="row.status" />
        </template>
        <template #mobile-title="{ row }">
          <span class="block text-sm font-medium text-content">{{ row.name }}</span>
        </template>
        <template #mobile-meta="{ row }">
          <span>{{ row.email }}</span>
          <NStatusBadge kind="user" :status="row.status" />
        </template>
      </NDataTable>

      <NEmptyState
        v-else-if="!loading"
        class="surface-card"
        icon="☺"
        title="Пользователей не найдено"
        description="Добавьте сотрудника, чтобы он мог работать с заказами и афишей."
      />
    </div>

    <NModal :open="createOpen" title="Новый пользователь" description="Пароль можно сменить позже" @update:open="createOpen = false">
      <div class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2">
          <NInput v-model="form.first_name" label="Имя" />
          <NInput v-model="form.last_name" label="Фамилия" />
        </div>
        <NInput v-model="form.email" label="E-mail" type="email" required autocomplete="off" />
        <NInput v-model="form.phone" label="Телефон" type="tel" />
        <NInput v-model="form.password" label="Пароль" type="password" hint="Минимум 8 символов" autocomplete="new-password" />

        <p v-if="formError" class="flex items-start gap-1 text-xs text-rose-400">
          <span aria-hidden="true">!</span><span>{{ formError }}</span>
        </p>
      </div>

      <template #footer>
        <NButton variant="ghost" @click="createOpen = false">Отмена</NButton>
        <NButton variant="primary" :loading="creating" @click="createUser">Создать</NButton>
      </template>
    </NModal>
  </div>
</template>
