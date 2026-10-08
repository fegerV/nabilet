<script setup lang="ts">
/**
 * Шаблоны билетов — раздел админки.
 *
 * Два экрана в одном: список шаблонов и конструктор выбранного. Пока шаблон не
 * выбран, показываем список — иначе администратор попадает сразу на пустой холст
 * и не понимает, что редактирует.
 *
 * `templateId` держится в query-параметре (`?template=12`), а не в отдельном
 * маршруте: конструктор — это тот же раздел, просто с открытым макетом. Так
 * ссылку можно переслать коллеге, и F5 не теряет контекст.
 */
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import TicketBuilder from '@/components/TicketBuilder.vue'
import NButton from '@/components/ui/NButton.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import { get, send, ApiError } from '@/lib/api'
import { useUiStore } from '@/stores/ui'

interface TicketTemplate {
  id: number
  public_id: string
  name: string
  format: string
  width: number
  height: number
  template_json: { backgroundColor?: string; elements?: unknown[] }
  active: boolean
  updated_at: string | null
}

const ui = useUiStore()
const route = useRoute()
const router = useRouter()

const templates = ref<TicketTemplate[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const selectedId = computed<number | null>(() => {
  const raw = route.query.template
  if (typeof raw !== 'string' || raw === '') return null
  const parsed = Number(raw)
  return Number.isFinite(parsed) ? parsed : null
})

const selected = computed(() => templates.value.find((t) => t.id === selectedId.value) ?? null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const res = await get<TicketTemplate[]>('/ticket-templates')
    const payload = res.data as unknown as TicketTemplate[] | { data: TicketTemplate[] }
    templates.value = Array.isArray(payload) ? payload : (payload.data ?? [])
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Не удалось загрузить шаблоны'
  } finally {
    loading.value = false
  }
}

load()

function openTemplate(id: number): void {
  router.push({ query: { ...route.query, template: String(id) } })
}

function closeTemplate(): void {
  const query = { ...route.query }
  delete query.template
  router.push({ query })
}

function onSaved(): void {
  ui.notify('mint', 'Шаблон сохранён', 'Новый макет применён к билетам этого шаблона.')
  load()
}

function onDeleted(): void {
  ui.notify('sun', 'Шаблон удалён', 'Мероприятия, ссылавшиеся на него, вернутся к шаблону по умолчанию.')
  closeTemplate()
  load()
}

async function removeFromList(template: TicketTemplate): Promise<void> {
  if (!window.confirm(`Удалить шаблон «${template.name}»? Действие необратимо.`)) return
  try {
    await send(`/ticket-templates/${template.id}`, 'DELETE')
    if (selectedId.value === template.id) closeTemplate()
    ui.notify('sun', 'Шаблон удалён', template.name)
    await load()
  } catch (e) {
    ui.notify('rose', 'Не удалось удалить', e instanceof ApiError ? e.message : String(e))
  }
}
</script>

<template>
  <div class="mx-auto max-w-[1400px]">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-content">Шаблоны билетов</h1>
        <p class="mt-1 text-sm text-muted">
          Макет билета: расположение QR, текста и афиши. Шаблон можно назначить мероприятию
          — тогда его билеты приходят покупателю в этом виде.
        </p>
      </div>
      <NButton v-if="selected" variant="secondary" @click="closeTemplate">← К списку</NButton>
    </div>

    <div v-if="error" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить шаблоны: {{ error }}
      <NButton variant="secondary" class="mt-3" @click="load">Повторить</NButton>
    </div>

    <!-- Конструктор открытого шаблона -->
    <div v-else-if="selected" class="mt-5">
      <TicketBuilder
        :key="selected.id"
        :template-id="selected.id"
        @template-saved="onSaved"
        @template-deleted="onDeleted"
      />
    </div>

    <!-- Список -->
    <template v-else>
      <p v-if="loading" class="mt-6 text-sm text-subtle">Загружаем шаблоны…</p>

      <NEmptyState
        v-else-if="!templates.length"
        class="surface-card mt-6"
        icon="🎫"
        title="Шаблонов пока нет"
        description="Создайте первый шаблон: откройте любой и соберите макет билета. Без шаблона билеты уходят в стандартном виде."
        action-label="Обновить"
        @action="load"
      />

      <div v-else class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <article
          v-for="template in templates"
          :key="template.id"
          class="surface-card flex flex-col overflow-hidden p-0"
        >
          <!-- Миниатюра: те же элементы, что на холсте, в масштабе карточки -->
          <button
            type="button"
            class="relative block h-36 w-full overflow-hidden border-b border-line text-left"
            :style="{ background: template.template_json?.backgroundColor || '#ffffff' }"
            @click="openTemplate(template.id)"
          >
            <span
              v-for="(el, i) in (template.template_json?.elements ?? []).slice(0, 12)"
              :key="i"
              class="absolute"
              :style="{
                left: `${((el as any).x / template.width) * 100}%`,
                top: `${((el as any).y / template.height) * 100}%`,
                width: (el as any).type === 'rectangle' ? '40%' : 'auto',
                height: (el as any).type === 'rectangle' ? '30%' : 'auto',
                background: (el as any).type === 'rectangle' ? ((el as any).fill ?? 'transparent') : 'transparent',
                border: (el as any).type === 'rectangle' && (el as any).stroke ? `1px solid ${(el as any).stroke}` : 'none',
                color: (el as any).color || '#334155',
                fontSize: '9px',
                fontWeight: (el as any).fontWeight || 'normal',
                whiteSpace: 'nowrap',
              }"
            >
              <template v-if="(el as any).type === 'text'">
                {{ String((el as any).content || '').slice(0, 18) }}
              </template>
              <span
                v-else-if="(el as any).type === 'qr'"
                class="grid h-8 w-8 place-items-center bg-white/90 text-[8px] text-slate-700"
              >QR</span>
            </span>
          </button>

          <div class="flex flex-1 flex-col p-4">
            <div class="flex items-start justify-between gap-2">
              <h2 class="min-w-0 truncate text-sm font-semibold text-content">{{ template.name }}</h2>
              <span
                class="flex-none rounded-full px-2 py-0.5 text-2xs font-medium"
                :class="template.active ? 'bg-mint-500/15 text-mint-400' : 'bg-sun-500/15 text-sun-400'"
              >{{ template.active ? 'активен' : 'выключен' }}</span>
            </div>
            <p class="mt-1 text-xs text-subtle">
              {{ template.width }}×{{ template.height }} · элементов:
              {{ (template.template_json?.elements ?? []).length }}
            </p>

            <div class="mt-4 flex flex-wrap items-center gap-2">
              <NButton @click="openTemplate(template.id)">Открыть</NButton>
              <button
                type="button"
                class="text-xs text-rose-400 underline decoration-dotted hover:text-rose-300"
                @click="removeFromList(template)"
              >Удалить</button>
            </div>
          </div>
        </article>
      </div>
    </template>
  </div>
</template>
