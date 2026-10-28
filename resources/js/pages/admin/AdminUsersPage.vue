<script setup lang="ts">
/**
 * Пользователи (админка).
 *
 * Сотрудники площадки: кто имеет доступ и с какой ролью. Создание — сразу
 * здесь, иначе администратор уходит в консоль и создаёт пользователя с
 * левым паролем. Пароль не показываем никогда: поле только на запись.
 *
 * Раньше страница умела только список и создание: пользователя нельзя было
 * открыть, изменить или удалить, а роль назначалась разве что SQL-запросом
 * (жалобы «нельзя нажать и отредактировать/удалить», «как выбрать роль»).
 * Эндпоинты `PUT/DELETE /users/{publicId}` и `POST /users/{publicId}/roles`
 * существовали с самого начала — не хватало интерфейса и справочника ролей.
 */
import { computed, onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import NDataTable from '@/components/ui/NDataTable.vue'
import NModal from '@/components/ui/NModal.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import { useUiStore } from '@/stores/ui'
import { get, send } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { statusOptions } from '@/lib/statusLabels'
import type { Column } from '@/components/ui/NDataTable.vue'

interface ApiRole {
  id: number
  name?: string | null
  slug?: string | null
  description?: string | null
}

interface ApiUser {
  id: number
  public_id?: string
  email: string
  first_name?: string | null
  last_name?: string | null
  phone?: string | null
  status?: string | null
  created_at?: string | null
  roles?: ApiRole[]
}

const ui = useUiStore()

const users = ref<ApiUser[]>([])
const roles = ref<ApiRole[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const search = ref('')

/** Развернуть ответ API: и `{data:[…]}`, и голый массив. */
function unwrap<T>(value: unknown): T[] {
  if (Array.isArray(value)) return value as T[]
  const inner = (value as { data?: unknown } | null)?.data
  return Array.isArray(inner) ? (inner as T[]) : []
}

/**
 * Идентификатор для маршрутов. `/users/{publicId}` принимает именно ULID
 * (`public_id`), а не числовой `id` — раньше в UI этого не было, потому что
 * правки/удаления не существовало. Фолбэк на `id` — на случай старого ответа.
 */
function userKey(u: ApiUser): string {
  return u.public_id ?? String(u.id)
}

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
      // `id` — строка (требование NDataTable) и одновременно ключ маршрута.
      id: userKey(u),
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
  { key: 'role', label: 'Роль', sortable: true, width: '160px' },
  { key: 'createdAt', label: 'Создан', hideOnMobile: true },
  { key: 'status', label: 'Статус', width: '120px' },
  { key: 'actions', label: '', align: 'right', width: '170px' },
]

function roleLabel(user: ApiUser): string {
  const names = (user.roles ?? []).map((r) => r.name ?? '')
  return names.filter(Boolean).join(', ') || '—'
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await get<unknown>('/users?limit=100')
    users.value = unwrap<ApiUser>(res.data)
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

/** Справочник ролей — из нового `/users/roles`. Недоступность не блокирует список. */
async function loadRoles(): Promise<void> {
  try {
    const res = await get<unknown>('/users/roles')
    roles.value = unwrap<ApiRole>(res.data)
  } catch {
    roles.value = []
  }
}

onMounted(() => {
  load()
  loadRoles()
})

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

/* ── Редактирование и роли ──────────────────────────────────────────────── */

const editOpen = ref(false)
const savingEdit = ref(false)
const editError = ref<string | null>(null)
const editTarget = ref<ApiUser | null>(null)
const editForm = ref({
  email: '',
  first_name: '',
  last_name: '',
  phone: '',
  status: 'active',
  password: '',
})
const roleToAdd = ref('')

/** Тот же словарь, что и у бейджа: `users.status` — три состояния `UserStatus`. */
const STATUS_OPTIONS = statusOptions('user')

const roleOptions = computed(() =>
  roles.value.map((r) => ({ value: String(r.id), label: r.name ?? r.slug ?? `#${r.id}` })),
)

const editRoles = computed<ApiRole[]>(() => editTarget.value?.roles ?? [])

function openEdit(user: ApiUser): void {
  editTarget.value = user
  editForm.value = {
    email: user.email ?? '',
    first_name: user.first_name ?? '',
    last_name: user.last_name ?? '',
    phone: user.phone ?? '',
    status: user.status ?? 'active',
    password: '',
  }
  roleToAdd.value = ''
  editError.value = null
  editOpen.value = true
}

async function saveEdit(): Promise<void> {
  const target = editTarget.value
  if (!target || savingEdit.value) return
  savingEdit.value = true
  editError.value = null
  try {
    const payload: Record<string, unknown> = {
      email: editForm.value.email,
      first_name: editForm.value.first_name || null,
      last_name: editForm.value.last_name || null,
      phone: editForm.value.phone || null,
      status: editForm.value.status,
    }
    // Пустой пароль = «не менять» (см. UserService::updateUser).
    if (editForm.value.password.trim()) payload.password = editForm.value.password

    await send<unknown>(`/users/${userKey(target)}`, 'PUT', payload)
    ui.notify('mint', 'Пользователь обновлён', editForm.value.email)
    editOpen.value = false
    await load()
  } catch (e) {
    editError.value = e instanceof Error ? e.message : String(e)
  } finally {
    savingEdit.value = false
  }
}

async function removeUser(user: ApiUser): Promise<void> {
  const label = user.email || userKey(user)
  if (!window.confirm(`Удалить пользователя «${label}»? Действие необратимо.`)) return
  try {
    await send<unknown>(`/users/${userKey(user)}`, 'DELETE')
    ui.notify('sun', 'Пользователь удалён', label)
    if (editTarget.value && userKey(editTarget.value) === userKey(user)) editOpen.value = false
    await load()
  } catch (e) {
    ui.notify('rose', 'Не удалось удалить', e instanceof Error ? e.message : String(e))
  }
}

async function addRole(): Promise<void> {
  const target = editTarget.value
  if (!target || !roleToAdd.value) return
  try {
    await send<unknown>(`/users/${userKey(target)}/roles`, 'POST', { role_id: Number(roleToAdd.value) })
    roleToAdd.value = ''
    await refreshTarget()
    ui.notify('mint', 'Роль назначена', target.email)
  } catch (e) {
    editError.value = e instanceof Error ? e.message : String(e)
  }
}

async function removeRole(role: ApiRole): Promise<void> {
  const target = editTarget.value
  if (!target) return
  try {
    await send<unknown>(`/users/${userKey(target)}/roles/${role.id}`, 'DELETE')
    await refreshTarget()
    ui.notify('sun', 'Роль снята', role.name ?? '')
  } catch (e) {
    editError.value = e instanceof Error ? e.message : String(e)
  }
}

/** Перечитать список и переустановить ссылку на редактируемого (роли обновились). */
async function refreshTarget(): Promise<void> {
  const target = editTarget.value
  if (!target) return
  const key = userKey(target)
  await load()
  const fresh = users.value.find((u) => userKey(u) === key)
  if (fresh) editTarget.value = fresh
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
        @row="openEdit($event.raw)"
      >
        <template #cell-name="{ row }">
          <span class="block text-content">{{ row.name }}</span>
          <span class="block text-2xs text-subtle">{{ dateTime(row.createdAt) }}</span>
        </template>
        <template #cell-email="{ row }">
          <span class="text-muted">{{ row.email }}</span>
        </template>
        <template #cell-role="{ row }">
          <span class="text-sm text-muted">{{ row.role }}</span>
        </template>
        <template #cell-createdAt="{ row }">
          <span class="text-sm text-muted">{{ dateTime(row.createdAt) }}</span>
        </template>
        <template #cell-status="{ row }">
          <NStatusBadge kind="user" :status="row.status" />
        </template>
        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-3">
            <button type="button" class="text-xs text-brand-400 hover:text-brand-300" @click.stop="openEdit(row.raw)">
              Изменить
            </button>
            <button type="button" class="text-xs text-rose-400 hover:text-rose-300" @click.stop="removeUser(row.raw)">
              Удалить
            </button>
          </div>
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

    <!-- Создание -->
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

    <!-- Редактирование -->
    <NModal
      :open="editOpen"
      title="Редактировать пользователя"
      description="Пароль можно оставить пустым — тогда он не изменится"
      @update:open="editOpen = false"
    >
      <div v-if="editTarget" class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-2">
          <NInput v-model="editForm.first_name" label="Имя" />
          <NInput v-model="editForm.last_name" label="Фамилия" />
        </div>
        <NInput v-model="editForm.email" label="E-mail" type="email" autocomplete="off" />
        <NInput v-model="editForm.phone" label="Телефон" type="tel" />

        <div class="grid gap-3 sm:grid-cols-2">
          <NSelect v-model="editForm.status" label="Статус" :options="STATUS_OPTIONS" />
          <NInput
            v-model="editForm.password"
            label="Новый пароль"
            type="password"
            hint="Пусто — не менять"
            autocomplete="new-password"
          />
        </div>

        <!-- Роли -->
        <div class="rounded-lg border border-line p-3">
          <p class="mb-2 text-sm font-medium text-content">Роли</p>

          <div v-if="editRoles.length" class="mb-3 flex flex-wrap gap-2">
            <span
              v-for="role in editRoles"
              :key="role.id"
              class="inline-flex items-center gap-2 rounded-full border border-line bg-surface-2 px-3 py-1 text-xs text-muted"
            >
              {{ role.name ?? role.slug }}
              <button type="button" class="text-rose-400 hover:text-rose-300" aria-label="Снять роль" @click="removeRole(role)">✕</button>
            </span>
          </div>
          <p v-else class="mb-3 text-xs text-subtle">Роли не назначены.</p>

          <div class="flex items-end gap-2">
            <NSelect
              v-model="roleToAdd"
              label="Добавить роль"
              class="flex-1"
              :options="[{ value: '', label: '— выберите роль —' }, ...roleOptions]"
            />
            <NButton variant="secondary" :disabled="!roleToAdd" @click="addRole">Назначить</NButton>
          </div>
        </div>

        <p v-if="editError" class="flex items-start gap-1 text-xs text-rose-400">
          <span aria-hidden="true">!</span><span>{{ editError }}</span>
        </p>
      </div>

      <template #footer>
        <NButton variant="ghost" @click="editOpen = false">Отмена</NButton>
        <NButton variant="primary" :loading="savingEdit" @click="saveEdit">Сохранить</NButton>
      </template>
    </NModal>
  </div>
</template>
