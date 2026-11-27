<script setup lang="ts">
/**
 * Раздел «ИИ-агент»: выпуск токенов без терминала + одобрение действий.
 *
 * На шаред-хостинге нет SSH, поэтому `php artisan nabilet:agent-token`
 * недоступен — токены выпускаются здесь. Plaintext показывается один раз,
 * как у токенов киоска: в списке живут только имя, способности и даты.
 *
 * Write-инструменты агента не меняют данные сразу: они сохраняют снимок в
 * agent_actions. Человек видит резюме здесь и одобряет; возврат требует
 * дополнительной фразы REFUND. Никаких «агент сам решил» — только явное
 * подтверждение ответственного сотрудника.
 */
import { onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NModal from '@/components/ui/NModal.vue'
import NBadge from '@/components/ui/NBadge.vue'
import { get, send } from '@/lib/api'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()

interface AgentToken {
  id: number
  name: string
  abilities: string[]
  created_at: string | null
  last_used_at: string | null
  expires_at: string | null
}

interface AgentAction {
  id: number
  public_id: string
  action: string
  summary: string
  status: string
  capability: string
  created_at: string
  expires_at: string
  result_json: Record<string, unknown> | null
  error: string | null
}

const CAPABILITIES = [
  { value: 'agent:events:write', label: 'Создавать черновики мероприятий', hint: 'Новая карточка сохраняется как черновик, без публикации.' },
  { value: 'agent:schemas:publish', label: 'Публиковать схемы залов', hint: 'Фиксирует геометрию версии после одобрения человеком.' },
  { value: 'agent:refunds:write', label: 'Готовить возвраты', hint: 'Возврат требует отдельного подтверждения REFUND.' },
  { value: 'agent:actions:confirm', label: 'Исполнять подтверждённые действия', hint: 'Без неё агент не сможет выполнить даже одобренное.' },
]

interface AgentConnection {
  endpoint: string
  transport: string
  auth_header: string
  capabilities: Record<string, string>
  groups: Record<string, Array<{ name: string; description: string; read_only: boolean }>>
  client_config: Record<string, unknown>
  rate_limit: string
  checks: { route_registered: boolean; active_tokens: number; ok: boolean; message: string }
  docs: string
}

const connection = ref<AgentConnection | null>(null)
const tokens = ref<AgentToken[]>([])
const actions = ref<AgentAction[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const issueOpen = ref(false)
const issueName = ref('Дежурный агент')
const issueEmail = ref('')
const selectedCapabilities = ref<string[]>(['agent:actions:confirm'])
const oneTimeToken = ref<string | null>(null)

const approving = ref<AgentAction | null>(null)
const confirmPhrase = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [c, t, a] = await Promise.all([
      get<AgentConnection>('/admin/agent/connection'),
      get<AgentToken[]>('/admin/agent/tokens'),
      get<AgentAction[]>('/admin/agent/actions?per_page=50'),
    ])
    connection.value = c.data
    tokens.value = t.data
    actions.value = a.data
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не удалось загрузить данные агента'
  } finally {
    loading.value = false
  }
}

function openIssue(): void {
  issueName.value = 'Дежурный агент'
  issueEmail.value = ''
  selectedCapabilities.value = ['agent:actions:confirm']
  oneTimeToken.value = null
  issueOpen.value = true
}

async function issue(): Promise<void> {
  try {
    const res = await send<{ token: string }>('/admin/agent/tokens', 'POST', {
      name: issueName.value,
      email: issueEmail.value || null,
      abilities: selectedCapabilities.value,
    })
    oneTimeToken.value = res.data.token
    ui.notify('mint', 'Токен выпущен', 'Скопируйте его сейчас — позже он не показывается.')
    await load()
  } catch (e) {
    ui.notify('rose', 'Ошибка', e instanceof Error ? e.message : 'Не удалось выпустить токен')
  }
}

async function rotate(token: AgentToken): Promise<void> {
  if (!window.confirm(`Отозвать токен «${token.name}» и выпустить новый? Старый перестанет работать.`)) return
  try {
    const res = await send<{ token: string }>(`/admin/agent/tokens/${token.id}/rotate`, 'POST', {})
    oneTimeToken.value = res.data.token
    issueOpen.value = true
    ui.notify('mint', 'Токен обновлён', 'Старый токен отозван; скопируйте новый.')
    await load()
  } catch (e) {
    ui.notify('rose', 'Ошибка', e instanceof Error ? e.message : 'Не удалось обновить токен')
  }
}

async function revoke(token: AgentToken): Promise<void> {
  if (!window.confirm(`Отозвать токен «${token.name}»? Агент потеряет доступ сразу.`)) return
  try {
    await send(`/admin/agent/tokens/${token.id}`, 'DELETE')
    ui.notify('mint', 'Токен отозван', token.name)
    await load()
  } catch (e) {
    ui.notify('rose', 'Ошибка', e instanceof Error ? e.message : 'Не удалось отозвать токен')
  }
}

async function copyToken(): Promise<void> {
  if (!oneTimeToken.value) return
  try {
    await navigator.clipboard.writeText(oneTimeToken.value)
    ui.notify('mint', 'Скопировано', 'Токен в буфере обмена.')
  } catch {
    ui.notify('sun', 'Скопируйте вручную', oneTimeToken.value)
  }
}

function openApprove(action: AgentAction): void {
  approving.value = action
  confirmPhrase.value = ''
}

async function approve(): Promise<void> {
  if (!approving.value) return
  try {
    await send(`/admin/agent/actions/${approving.value.id}/approve`, 'POST', {
      confirm_phrase: confirmPhrase.value,
    })
    ui.notify('mint', 'Действие одобрено', 'Агент сможет исполнить его вызовом confirm_agent_action.')
    approving.value = null
    await load()
  } catch (e) {
    ui.notify('rose', 'Ошибка', e instanceof Error ? e.message : 'Не удалось одобрить')
  }
}

async function reject(action: AgentAction): Promise<void> {
  if (!window.confirm('Отклонить это действие?')) return
  try {
    await send(`/admin/agent/actions/${action.id}/reject`, 'POST', {})
    ui.notify('neutral', 'Действие отклонено', action.summary)
    await load()
  } catch (e) {
    ui.notify('rose', 'Ошибка', e instanceof Error ? e.message : 'Не удалось отклонить')
  }
}

const pending = () => actions.value.filter(a => a.status === 'pending' || a.status === 'approved')
const statusTone = (s: string): 'sun' | 'mint' | 'rose' | 'neutral' => {
  if (s === 'pending') return 'sun'
  if (s === 'approved') return 'mint'
  if (s === 'failed' || s === 'rejected') return 'rose'
  return 'neutral'
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-content">ИИ-агент</h1>
        <p class="mt-1 max-w-3xl text-sm text-subtle">
          Токены выпускаются здесь — терминал на хостинге не нужен. Изменения агент
          только подготавливает: вы видите резюме и одобряете его вручную. Возврат
          дополнительно требует фразы <code class="rounded bg-surface-2 px-1">REFUND</code>.
        </p>
      </div>
      <div class="flex gap-2">
        <NButton variant="ghost" size="sm" @click="load">Обновить</NButton>
        <NButton variant="primary" size="sm" @click="openIssue">Выпустить токен</NButton>
      </div>
    </header>

    <p v-if="error" role="alert" class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-3 text-sm text-rose-300">{{ error }}</p>

    <section v-if="connection" class="rounded-xl border border-line bg-surface p-4">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-base font-semibold text-content">Подключение MCP</h2>
          <p class="mt-1 text-sm text-subtle">{{ connection.transport }} · {{ connection.rate_limit }}</p>
        </div>
        <NBadge :tone="connection.checks.ok ? 'mint' : 'sun'">
          {{ connection.checks.ok ? 'Готово к подключению' : 'Требуется настройка' }}
        </NBadge>
      </div>
      <p class="mt-3 text-sm" :class="connection.checks.ok ? 'text-mint-300' : 'text-sun-300'">
        {{ connection.checks.message }}
      </p>
      <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-xs uppercase tracking-wide text-subtle">Адрес сервера</dt>
          <dd class="mt-1 break-all font-mono text-content">{{ connection.endpoint }}</dd>
        </div>
        <div>
          <dt class="text-xs uppercase tracking-wide text-subtle">Заголовок авторизации</dt>
          <dd class="mt-1 break-all font-mono text-content">{{ connection.auth_header }}</dd>
        </div>
      </dl>
      <details class="mt-4 text-sm">
        <summary class="cursor-pointer text-subtle">Конфигурация клиента (OpenCode / Codex)</summary>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-surface-2 p-3 text-xs text-content">{{ JSON.stringify(connection.client_config, null, 2) }}</pre>
      </details>
      <div class="mt-4 grid gap-4 text-sm md:grid-cols-2">
        <div v-for="(tools, group) in connection.groups" :key="group">
          <h3 class="text-xs uppercase tracking-wide text-subtle">
            {{ group === 'diagnostics' ? 'Диагностика (read-only)' : (group === 'content' ? 'Контент и проверки (read-only)' : 'Данные и подготовка действий') }}
          </h3>
          <ul class="mt-2 space-y-1">
            <li v-for="tool in tools" :key="tool.name" class="text-content">
              <code class="text-xs">{{ tool.name }}</code>
              <span v-if="tool.read_only" class="ml-1 text-xs text-subtle">· только чтение</span>
            </li>
          </ul>
        </div>
      </div>
      <p class="mt-4 text-xs text-subtle">
        Инструкция: <code>{{ connection.docs }}</code>. Назначение полномочий — выше в списке токенов.
      </p>
    </section>

    <section>
      <h2 class="mb-3 text-base font-semibold text-content">Ожидают подтверждения</h2>
      <div v-if="loading" class="text-sm text-subtle">Загрузка…</div>
      <div v-else-if="pending().length === 0" class="rounded-xl border border-line p-6 text-center text-sm text-subtle">
        Нет действий, ожидающих одобрения.
      </div>
      <ul v-else class="space-y-2">
        <li v-for="action in pending()" :key="action.id"
            class="rounded-xl border border-line bg-surface p-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <code class="text-xs text-subtle">{{ action.action }}</code>
                <NBadge :tone="statusTone(action.status)">{{ action.status }}</NBadge>
              </div>
              <p class="mt-1 text-sm text-content">{{ action.summary }}</p>
              <p class="mt-1 text-xs text-subtle">
                Создано {{ action.created_at }} · действует до {{ action.expires_at }}
              </p>
              <p v-if="action.error" class="mt-1 text-xs text-rose-400">Ошибка: {{ action.error }}</p>
            </div>
            <div v-if="action.status === 'pending'" class="flex shrink-0 gap-2">
              <NButton variant="ghost" size="sm" @click="reject(action)">Отклонить</NButton>
              <NButton variant="primary" size="sm" @click="openApprove(action)">Одобрить</NButton>
            </div>
          </div>
        </li>
      </ul>
    </section>

    <section>
      <h2 class="mb-3 text-base font-semibold text-content">Токены</h2>
      <div v-if="tokens.length === 0" class="rounded-xl border border-line p-6 text-center text-sm text-subtle">
        Токенов пока нет. Выпустите первый, чтобы подключить агент к <code>/mcp</code>.
      </div>
      <div v-else class="overflow-x-auto rounded-xl border border-line">
        <table class="w-full text-sm">
          <thead class="bg-surface-2 text-left text-xs uppercase tracking-wide text-subtle">
            <tr><th class="p-3">Название</th><th class="p-3">Полномочия</th><th class="p-3">Создан</th><th class="p-3">Использован</th><th class="p-3"></th></tr>
          </thead>
          <tbody>
            <tr v-for="token in tokens" :key="token.id" class="border-t border-line">
              <td class="p-3 font-medium text-content">{{ token.name }}</td>
              <td class="p-3">
                <div class="flex flex-wrap gap-1">
                  <NBadge v-for="a in token.abilities" :key="a" tone="neutral">{{ a }}</NBadge>
                </div>
              </td>
              <td class="p-3 text-xs text-subtle">{{ token.created_at }}</td>
              <td class="p-3 text-xs text-subtle">{{ token.last_used_at ?? 'никогда' }}</td>
              <td class="p-3">
                <div class="flex gap-2">
                  <NButton variant="ghost" size="sm" @click="rotate(token)">Обновить</NButton>
                  <NButton variant="ghost" size="sm" @click="revoke(token)">Отозвать</NButton>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <NModal :open="issueOpen" title="Выпустить токен ИИ-агента" @close="issueOpen = false">
      <div v-if="oneTimeToken" class="space-y-3">
        <p class="text-sm text-content">Скопируйте токен сейчас — он больше не покажется.</p>
        <code class="block break-all rounded-lg bg-surface-2 p-3 text-xs text-brand-300">{{ oneTimeToken }}</code>
        <NButton variant="primary" size="sm" @click="copyToken">Скопировать</NButton>
      </div>
      <form v-else class="space-y-4" @submit.prevent="issue">
        <NInput v-model="issueName" label="Название токена" required />
        <NInput v-model="issueEmail" label="E-mail сотрудника (необязательно)" placeholder="Оставьте пустым — токен будет от вашего имени" />
        <div>
          <p class="mb-2 text-sm font-medium text-content">Полномочия</p>
          <label v-for="cap in CAPABILITIES" :key="cap.value"
                 class="mb-2 flex cursor-pointer items-start gap-3 rounded-xl border border-line p-3">
            <input v-model="selectedCapabilities" type="checkbox" :value="cap.value"
                   class="mt-0.5 h-4 w-4 rounded border-line text-brand-500 accent-brand-500" />
            <span>
              <span class="block text-sm text-content">{{ cap.label }}</span>
              <span class="block text-xs text-subtle">{{ cap.hint }}</span>
            </span>
          </label>
          <p class="text-xs text-subtle">Чтение данных (<code>agent:read</code>) добавляется автоматически.</p>
        </div>
        <div class="flex justify-end gap-2">
          <NButton variant="ghost" size="sm" type="button" @click="issueOpen = false">Отмена</NButton>
          <NButton variant="primary" size="sm" type="submit">Выпустить</NButton>
        </div>
      </form>
    </NModal>

    <NModal :open="approving !== null" :title="`Одобрить: ${approving?.action ?? ''}`" @close="approving = null">
      <div class="space-y-4">
        <p class="text-sm text-content">{{ approving?.summary }}</p>
        <NInput v-if="approving?.action === 'refund'" v-model="confirmPhrase"
                label="Для возврата введите REFUND" placeholder="REFUND" />
        <div class="flex justify-end gap-2">
          <NButton variant="ghost" size="sm" @click="approving = null">Отмена</NButton>
          <NButton variant="primary" size="sm" @click="approve">Одобрить</NButton>
        </div>
      </div>
    </NModal>
  </div>
</template>
