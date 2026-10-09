<script setup lang="ts">
/**
 * Шаблоны транзакционных писем.
 *
 * Текст письма правится здесь, а не в коде: шаблон хранится в БД и ищется по
 * коду (`order.<статус>`, `order.reminder`, `account.*`). Сидер создаёт стартовые
 * тексты, дальше их меняет администратор — без деплоя.
 *
 * Код, канал и локаль не редактируются: это идентичность шаблона, по коду его
 * находит обсервер заказа. Правка кода молча отключила бы рассылку.
 *
 * Предпросмотр рисуется в <iframe sandbox>, а не через v-html: шаблон — это
 * HTML, и именно его администратор должен увидеть, но скрипты из него
 * выполняться не должны.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NCheckbox from '@/components/ui/NCheckbox.vue'
import NBadge from '@/components/ui/NBadge.vue'
import NCard from '@/components/ui/NCard.vue'
import NPageHeader from '@/components/ui/NPageHeader.vue'
import { get, send } from '@/lib/api'

interface Template {
  id: number
  code: string
  channel: string
  locale: string
  subject: string | null
  body_html: string | null
  body_text: string | null
  active: boolean
}

interface Rendered {
  subject: string
  html: string
  text: string
}

/**
 * Человекочитаемые имена событий. Код — это идентичность (`order.paid`), но
 * администратор думает «заказ оплачен», а не «order.paid», поэтому в списке
 * показываем имя, а код оставляем мелкой подписью для сверки с логами.
 */
const LABELS: Record<string, string> = {
  'order.awaiting_payment': 'Ожидает оплаты',
  'order.paid': 'Заказ оплачен',
  'order.cancelled': 'Заказ отменён',
  'order.refunded': 'Возврат средств',
  'order.partially_refunded': 'Частичный возврат',
  'order.payment_failed': 'Ошибка оплаты',
  'order.expired': 'Срок оплаты истёк',
  'order.reminder': 'Напоминание за сутки',
  'account.password_reset': 'Смена пароля',
  'account.email_verification': 'Подтверждение адреса',
}

function labelFor(code: string): string {
  return LABELS[code] ?? code
}

const templates = ref<Template[]>([])
const variables = ref<string[]>([])
const selectedId = ref<number | null>(null)
const form = ref({ subject: '', body_html: '', body_text: '', active: true })
const preview = ref<Rendered | null>(null)

const loading = ref(true)
const saving = ref(false)
const previewing = ref(false)
const error = ref<string | null>(null)
const status = ref<string | null>(null)
const savedSnapshot = ref('')

const selected = computed(() => templates.value.find((t) => t.id === selectedId.value) ?? null)
const isDirty = computed(() => JSON.stringify(form.value) !== savedSnapshot.value)

/** Шаблоны, сгруппированные по семейству событий — так список читается. */
const GROUPS = computed(() => {
  const order = templates.value.filter((t) => t.code.startsWith('order.'))
  const account = templates.value.filter((t) => t.code.startsWith('account.'))
  const other = templates.value.filter((t) => !t.code.startsWith('order.') && !t.code.startsWith('account.'))
  const groups: Array<{ label: string; items: Template[] }> = []
  if (order.length) groups.push({ label: 'Заказы', items: order })
  if (account.length) groups.push({ label: 'Доступ к аккаунту', items: account })
  if (other.length) groups.push({ label: 'Прочее', items: other })
  return groups
})

const variableTokens = computed(() => variables.value.map((name) => ({ name, token: `{{${name}}}` })))

/** Куда вставлять переменную: последнее поле, в котором был курсор. */
const lastField = ref<'subject' | 'html' | 'text'>('html')
const htmlEl = ref<HTMLTextAreaElement | null>(null)
const textEl = ref<HTMLTextAreaElement | null>(null)

function insertVariable(name: string): void {
  const token = `{{${name}}}`

  if (lastField.value === 'subject') {
    form.value.subject = `${form.value.subject}${token}`
    return
  }

  const el = lastField.value === 'html' ? htmlEl.value : textEl.value
  if (!el) {
    if (lastField.value === 'html') form.value.body_html += token
    else form.value.body_text += token
    return
  }

  // Вставляем по позиции курсора, а не в конец: администратор часто дописывает
  // текст в середину, и переменная должна встать туда, где он стоит.
  const start = el.selectionStart ?? el.value.length
  const end = el.selectionEnd ?? start
  const next = el.value.slice(0, start) + token + el.value.slice(end)

  if (lastField.value === 'html') form.value.body_html = next
  else form.value.body_text = next

  void nextTick(() => {
    el.focus()
    const pos = start + token.length
    el.setSelectionRange(pos, pos)
  })
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await get<{ templates: Template[]; variables: string[] }>('/notification-templates')
    templates.value = res.data.templates ?? []
    variables.value = res.data.variables ?? []

    if (selectedId.value === null && templates.value.length > 0) {
      select(templates.value[0] as Template)
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

function select(template: Template): void {
  selectedId.value = template.id
  form.value = {
    subject: template.subject ?? '',
    body_html: template.body_html ?? '',
    body_text: template.body_text ?? '',
    active: template.active,
  }
  savedSnapshot.value = JSON.stringify(form.value)
  preview.value = null
  status.value = null
  error.value = null
}

async function save(): Promise<void> {
  if (selectedId.value === null) return

  saving.value = true
  error.value = null
  status.value = null
  try {
    const res = await send<Template>(`/notification-templates/${selectedId.value}`, 'PUT', form.value)
    const updated = res.data
    const index = templates.value.findIndex((t) => t.id === selectedId.value)
    if (index !== -1) templates.value[index] = updated
    savedSnapshot.value = JSON.stringify(form.value)
    status.value = 'Шаблон сохранён.'
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    saving.value = false
  }
}

async function renderPreview(): Promise<void> {
  if (selectedId.value === null) return

  previewing.value = true
  error.value = null
  try {
    const res = await send<Rendered>(`/notification-templates/${selectedId.value}/preview`, 'POST', form.value)
    preview.value = res.data
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    previewing.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-[1200px]">
    <NPageHeader
      title="Шаблоны писем"
      subtitle="Письма покупателю по событиям заказа и доступа к аккаунту. Переменные подставляются как &#123;&#123;имя&#125;&#125;."
    />

    <p v-if="error" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">{{ error }}</p>
    <p v-if="status" class="surface-card mt-5 border-mint-500/30 px-4 py-3 text-sm text-mint-400">{{ status }}</p>

    <div v-if="loading" class="mt-6 space-y-2">
      <div v-for="n in 4" :key="n" class="skeleton h-10 w-full" />
    </div>

    <div v-else class="mt-5 grid gap-5 lg:grid-cols-[320px_1fr]">
      <!-- Список событий -->
      <NCard title="События" :description="`${templates.length} шаблонов`" padding="none">
        <nav class="p-2">
          <div v-for="group in GROUPS" :key="group.label" class="mb-2 last:mb-0">
            <p class="px-2.5 pb-1 pt-1 text-2xs font-semibold uppercase tracking-wider text-subtle">
              {{ group.label }}
            </p>
            <button
              v-for="template in group.items"
              :key="template.id"
              type="button"
              class="mb-0.5 flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left transition-colors"
              :class="template.id === selectedId ? 'bg-brand-500/12 text-brand-300' : 'text-muted hover:bg-surface-3 hover:text-content'"
              @click="select(template)"
            >
              <span
                class="h-1.5 w-1.5 flex-none rounded-full"
                :class="template.active ? 'bg-mint-400' : 'bg-content-subtle'"
                aria-hidden="true"
              />
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm">{{ labelFor(template.code) }}</span>
                <span class="block truncate font-mono text-2xs text-subtle">{{ template.code }}</span>
              </span>
            </button>
          </div>
        </nav>
      </NCard>

      <!-- Редактор -->
      <NCard
        v-if="selected"
        :title="labelFor(selected.code)"
        :description="`${selected.code} · ${selected.channel} · ${selected.locale}`"
      >
        <template #actions>
          <NBadge :tone="form.active ? 'mint' : 'neutral'" dot>{{ form.active ? 'отправляется' : 'выключен' }}</NBadge>
        </template>

        <div class="space-y-4" @focusin="lastField = 'subject'">
          <NInput v-model="form.subject" label="Тема письма" placeholder="Например: Ваши билеты на &#123;&#123;event_name&#125;&#125;" />
        </div>

        <div class="mt-4">
          <label for="tpl-html" class="mb-1.5 block text-sm font-medium text-content">HTML-версия</label>
          <textarea
            id="tpl-html"
            ref="htmlEl"
            v-model="form.body_html"
            rows="14"
            spellcheck="false"
            class="w-full rounded-lg border border-line bg-surface px-3 py-2 font-mono text-xs text-content transition-colors focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/25"
            @focus="lastField = 'html'"
          />
        </div>

        <div class="mt-4">
          <label for="tpl-text" class="mb-1.5 block text-sm font-medium text-content">Текстовая версия</label>
          <textarea
            id="tpl-text"
            ref="textEl"
            v-model="form.body_text"
            rows="7"
            spellcheck="false"
            class="w-full rounded-lg border border-line bg-surface px-3 py-2 font-mono text-xs text-content transition-colors focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/25"
            @focus="lastField = 'text'"
          />
        </div>

        <div class="mt-4">
          <NCheckbox v-model="form.active" label="Письмо отправляется" description="Выключенный шаблон не уйдёт покупателю" />
        </div>

        <div v-if="variableTokens.length" class="mt-5 rounded-lg border border-line bg-surface-2 p-3">
          <p class="text-xs text-muted">
            Вставьте переменную — она подставится в то поле, где стоит курсор:
          </p>
          <div class="mt-2 flex flex-wrap gap-1.5">
            <button
              v-for="item in variableTokens"
              :key="item.name"
              type="button"
              class="rounded-full border border-line bg-surface px-2.5 py-1 font-mono text-2xs text-muted transition-colors hover:border-brand-400/50 hover:text-content"
              @click="insertVariable(item.name)"
            >
              {{ item.token }}
            </button>
          </div>
        </div>

        <template #footer>
          <div class="flex w-full flex-wrap items-center gap-3">
            <NButton :loading="saving" :disabled="!isDirty" @click="save">Сохранить</NButton>
            <NButton variant="secondary" :loading="previewing" @click="renderPreview">Предпросмотр</NButton>
            <span v-if="isDirty" class="text-xs text-sun-400">Есть несохранённые изменения</span>
          </div>
        </template>
      </NCard>

      <NCard v-else title="Выберите событие" description="Слева — список писем, которые уходят покупателю.">
        <p class="text-sm text-subtle">Шаблон не выбран.</p>
      </NCard>
    </div>

    <!-- Предпросмотр -->
    <NCard v-if="preview" class="mt-5" title="Предпросмотр письма">
      <p class="text-sm text-content"><span class="text-subtle">Тема:</span> {{ preview.subject }}</p>
      <iframe
        sandbox=""
        :srcdoc="preview.html"
        class="mt-3 h-[460px] w-full rounded-lg border border-line bg-white"
        title="Предпросмотр письма"
      />
    </NCard>
  </div>
</template>
