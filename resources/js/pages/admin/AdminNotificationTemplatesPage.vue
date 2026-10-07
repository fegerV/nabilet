<script setup lang="ts">
/**
 * Шаблоны транзакционных писем.
 *
 * Текст письма правится здесь, а не в коде: шаблон хранится в БД и ищется по
 * коду `order.<статус>`. Сидер создаёт стартовые тексты, дальше их меняет
 * администратор — без деплоя.
 *
 * Код, канал и локаль не редактируются: это идентичность шаблона, по коду его
 * находит обсервер заказа. Правка кода молча отключила бы рассылку.
 *
 * Предпросмотр рисуется в <iframe sandbox>, а не через v-html: шаблон — это
 * HTML, и именно его администратор должен увидеть, но скрипты из него
 * выполняться не должны.
 */
import { computed, onMounted, ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NCheckbox from '@/components/ui/NCheckbox.vue'
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

const selected = computed(() => templates.value.find((t) => t.id === selectedId.value) ?? null)

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
  preview.value = null
  status.value = null
}

async function save(): Promise<void> {
  if (selectedId.value === null) return

  saving.value = true
  error.value = null
  status.value = null
  try {
    const res = await send<Template>(
      `/notification-templates/${selectedId.value}`,
      'PUT',
      form.value,
    )
    const updated = res.data
    const index = templates.value.findIndex((t) => t.id === selectedId.value)
    if (index !== -1) templates.value[index] = updated
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
    const res = await send<Rendered>(
      `/notification-templates/${selectedId.value}/preview`,
      'POST',
      form.value,
    )
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
  <div class="space-y-4">
    <header>
      <h1 class="text-xl font-semibold">Шаблоны писем</h1>
      <p class="mt-1 text-sm text-muted">
        Письма покупателю по статусам заказа. Переменные подставляются как
        <code>&#123;&#123;имя&#125;&#125;</code>.
      </p>
    </header>

    <p v-if="error" class="rounded-lg bg-rose-500/10 px-3 py-2 text-sm text-rose-400">{{ error }}</p>
    <p v-if="status" class="rounded-lg bg-mint-500/10 px-3 py-2 text-sm text-mint-400">{{ status }}</p>

    <div v-if="loading" class="text-sm text-muted">Загрузка…</div>

    <div v-else class="grid gap-4 lg:grid-cols-[280px_1fr]">
      <nav class="space-y-1">
        <button
          v-for="template in templates"
          :key="template.id"
          type="button"
          class="w-full rounded-lg px-3 py-2 text-left text-sm transition"
          :class="
            template.id === selectedId
              ? 'bg-brand-500 text-white'
              : 'bg-surface hover:bg-surface-3'
          "
          @click="select(template)"
        >
          <span class="block font-medium">{{ template.code }}</span>
          <span class="block text-xs opacity-70">
            {{ template.active ? 'включён' : 'выключен' }}
          </span>
        </button>
      </nav>

      <section v-if="selected" class="space-y-3 rounded-xl border border-line p-4">
        <div>
          <h2 class="text-base font-semibold">{{ selected.code }}</h2>
          <p class="text-xs text-muted">
            Канал: {{ selected.channel }} · локаль: {{ selected.locale }}
          </p>
        </div>

        <NInput v-model="form.subject" label="Тема письма" />

        <div>
          <label class="mb-1 block text-sm font-medium">HTML-версия</label>
          <textarea
            v-model="form.body_html"
            rows="12"
            class="w-full rounded-lg border border-line px-3 py-2 font-mono text-xs"
          />
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium">Текстовая версия</label>
          <textarea
            v-model="form.body_text"
            rows="6"
            class="w-full rounded-lg border border-line px-3 py-2 font-mono text-xs"
          />
        </div>

        <NCheckbox v-model="form.active" label="Шаблон включён" />

        <div class="flex flex-wrap gap-2">
          <NButton :loading="saving" @click="save">Сохранить</NButton>
          <NButton variant="secondary" :loading="previewing" @click="renderPreview">
            Предпросмотр
          </NButton>
        </div>

        <div v-if="variables.length" class="text-xs text-muted">
          Доступные переменные:
          <code v-for="v in variables" :key="v" class="mr-1">&#123;&#123;{{ v }}&#125;&#125;</code>
        </div>

        <div v-if="preview" class="space-y-2">
          <h3 class="text-sm font-semibold">Предпросмотр</h3>
          <p class="text-sm"><b>Тема:</b> {{ preview.subject }}</p>
          <iframe
            sandbox=""
            :srcdoc="preview.html"
            class="h-[420px] w-full rounded-lg border border-line bg-white"
            title="Предпросмотр письма"
          />
        </div>
      </section>
    </div>
  </div>
</template>
