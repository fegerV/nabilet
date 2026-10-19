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

interface TicketTemplateEvent {
  id: number
  title: string
}

interface TicketTemplate {
  id: number
  public_id: string
  name: string
  format: string
  width: number
  height: number
  template_json: {
    backgroundColor?: string
    backgroundGradient?: { from?: string; to?: string; direction?: string } | null
    elements?: unknown[]
  }
  active: boolean
  events?: TicketTemplateEvent[]
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
  if (typeof raw !== 'string' || raw === '' || raw === 'new') return null
  const parsed = Number(raw)
  return Number.isFinite(parsed) ? parsed : null
})

const selected = computed(() => templates.value.find((t) => t.id === selectedId.value) ?? null)

// Режим создания: конструктор с пустым холстом (старт из заготовок,
// сохранение идёт POST'ом). Раньше в него было не попасть вообще:
// конструктор монтировался только для существующего id, а при пустом списке
// открывать было нечего — первый шаблон создать было нельзя.
/** Открыт конструктор НОВОГО шаблона (`?template=new`). */
const isCreating = computed(() => route.query.template === 'new')
/** Конструктор открыт — либо новый шаблон, либо выбранный существующий. */
const builderOpen = computed(() => isCreating.value || selected.value !== null)

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

/** Начать новый шаблон: конструктор открывается без `templateId`. */
function createTemplate(): void {
  router.push({ query: { ...route.query, template: 'new' } })
}

function onSaved(saved: unknown): void {
  ui.notify('mint', 'Шаблон сохранён', 'Новый макет применён к билетам этого шаблона.')
  // После создания уходим с `template=new` на реальный id, иначе F5 вернул бы
  // на пустой холст вместо только что созданного макета, а повторное «Сохранить»
  // создало бы второй шаблон с тем же именем. Blueprint ответа приходит и как
  // `{ data: { id } }`, и как `{ id }` — вытаскиваем id из обеих форм.
  const node = (saved as { data?: { id?: unknown } } | null)?.data ?? saved
  const id = Number((node as { id?: unknown } | null)?.id)
  if (Number.isFinite(id) && id > 0) {
    openTemplate(id)
  }
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

/**
 * Миниатюра макета в карточке списка.
 *
 * Раньше карточка рисовала КАЖДЫЙ прямоугольник как 40%×30% контейнера и не
 * смотрела на координаты — то есть показывала не макет, а цветные пятна.
 * С вертикальным билетом это особенно заметно: шапка на всю ширину выглядела
 * маленьким квадратиком в углу, и выбрать макет глазами было нельзя.
 *
 * Считаем в пикселях от высоты превью: контейнер держит пропорции макета
 * (`aspect-ratio`), поэтому масштаб по высоте верен и по ширине.
 */
const MINI_HEIGHT = 144

/** Фон миниатюры: сплошной цвет либо градиент из `template_json`. */
function templateBackground(template: TicketTemplate): string {
  const gradient = template.template_json?.backgroundGradient

  if (gradient?.from) {
    return `linear-gradient(${gradient.direction || 'to bottom'}, ${gradient.from}, ${gradient.to || '#1e293b'})`
  }

  return template.template_json?.backgroundColor || '#ffffff'
}

function miniStyle(el: Record<string, unknown>, template: TicketTemplate): Record<string, string> {
  const scale = MINI_HEIGHT / Math.max(1, template.height)
  const num = (value: unknown, fallback = 0): number =>
    typeof value === 'number' && Number.isFinite(value) ? value : fallback

  const style: Record<string, string> = {
    left: `${num(el.x) * scale}px`,
    top: `${num(el.y) * scale}px`,
  }

  switch (el.type) {
    case 'text':
      return {
        ...style,
        width: el.width ? `${num(el.width) * scale}px` : 'auto',
        color: typeof el.color === 'string' ? el.color : '#334155',
        fontSize: `${Math.max(4, num(el.fontSize, 12) * scale)}px`,
        fontWeight: el.fontWeight === 'bold' ? 'bold' : 'normal',
        lineHeight: '1.2',
        whiteSpace: el.width ? 'pre-wrap' : 'nowrap',
        overflow: 'hidden',
      }
    case 'rectangle':
    case 'image':
    case 'barcode':
      return {
        ...style,
        width: `${num(el.width, 100) * scale}px`,
        height: `${num(el.height, 100) * scale}px`,
        background: typeof el.fill === 'string' ? el.fill : 'transparent',
        border: typeof el.stroke === 'string' && el.stroke !== 'transparent'
          ? `${Math.max(1, num(el.strokeWidth, 1) * scale)}px solid ${el.stroke}`
          : 'none',
        borderRadius: `${num(el.borderRadius) * scale}px`,
      }
    case 'qr': {
      const size = num(el.size, 100) * scale
      return {
        ...style,
        width: `${size}px`,
        height: `${size}px`,
        // Шахматка вместо картинки: узнаваемый «QR» без генерации кода на
        // каждую карточку списка.
        background: 'repeating-conic-gradient(#0f172a 0% 25%, #ffffff 0% 50%) 0 0 / 25% 25%',
      }
    }
    case 'circle': {
      const d = num(el.r, 50) * 2 * scale
      return {
        ...style,
        width: `${d}px`,
        height: `${d}px`,
        borderRadius: '50%',
        background: typeof el.fill === 'string' ? el.fill : 'transparent',
      }
    }
    case 'line': {
      const x2 = num(el.x2, num(el.x) + num(el.width, 200))
      const y2 = num(el.y2, num(el.y))
      const length = Math.max(1, Math.hypot(x2 - num(el.x), y2 - num(el.y)) * scale)
      return {
        ...style,
        width: `${length}px`,
        height: `${Math.max(1, num(el.strokeWidth, 1) * scale)}px`,
        background: typeof el.stroke === 'string' ? el.stroke : '#000000',
        transformOrigin: '0 0',
        transform: `rotate(${Math.atan2(y2 - num(el.y), x2 - num(el.x))}rad)`,
      }
    }
    default:
      return style
  }
}

/**
 * Дублировать макет — «свой билет» с другой типографикой/фоном, не трогая
 * исходник. Сразу открываем копию в конструкторе, чтобы администратор правил
 * именно её.
 */
const duplicatingId = ref<number | null>(null)

async function duplicateFromList(template: TicketTemplate): Promise<void> {
  if (duplicatingId.value !== null) return
  duplicatingId.value = template.id
  try {
    const res = await send<{ id: number }>(`/ticket-templates/${template.id}/duplicate`, 'POST', {})
    const saved = (res as unknown as { data?: { id: number } }).data ?? res
    const newId = (saved as { id: number }).id
    ui.notify('mint', 'Копия создана', `${template.name} → макет №${newId}`)
    await load()
    openTemplate(newId)
  } catch (e) {
    ui.notify('rose', 'Не удалось дублировать', e instanceof ApiError ? e.message : String(e))
  } finally {
    duplicatingId.value = null
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
      <div class="flex flex-wrap gap-2">
        <NButton v-if="builderOpen" variant="secondary" @click="closeTemplate">← К списку</NButton>
        <NButton v-else variant="accent" @click="createTemplate">+ Создать шаблон</NButton>
      </div>
    </div>

    <div v-if="error" class="surface-card mt-5 border-rose-500/30 px-4 py-3 text-sm text-rose-400">
      Не удалось загрузить шаблоны: {{ error }}
      <NButton variant="secondary" class="mt-3" @click="load">Повторить</NButton>
    </div>

    <!-- Конструктор: новый шаблон (`template-id=null`) или открытый существующий -->
    <div v-else-if="builderOpen" class="mt-5">
      <TicketBuilder
        :key="isCreating ? 'new' : (selected?.id ?? 'new')"
        :template-id="isCreating ? null : (selected?.id ?? null)"
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
        description="Создайте первый шаблон: соберите макет билета на холсте и назначьте его мероприятию. Без шаблона билеты уходят в стандартном виде."
        action-label="+ Создать шаблон"
        @action="createTemplate"
      />

      <div v-else class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <article
          v-for="template in templates"
          :key="template.id"
          class="surface-card flex flex-col overflow-hidden p-0"
        >
          <!-- Миниатюра: реальная раскладка макета в масштабе карточки.
               Контейнер держит пропорции билета, поэтому элементы встают на
               свои места, а не превращаются в пятна 40%×30%. -->
          <button
            type="button"
            class="relative block h-36 w-full overflow-hidden border-b border-line text-left"
            :style="{ background: templateBackground(template) }"
            @click="openTemplate(template.id)"
          >
            <span class="absolute inset-0 grid place-items-center">
              <span
                class="relative block h-full overflow-hidden"
                :style="{ aspectRatio: `${template.width} / ${template.height}` }"
              >
                <span
                  v-for="(el, i) in (template.template_json?.elements ?? [])"
                  :key="i"
                  class="absolute"
                  :style="miniStyle(el as Record<string, unknown>, template)"
                >
                  <template v-if="(el as any).type === 'text'">{{ String((el as any).content || '') }}</template>
                </span>
              </span>
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
            <!-- Привязки: чьи билеты оформляет макет. Пусто — макет ни за кем
              не закреплён (может быть макетом по умолчанию для новых мероприятий). -->
            <p class="mt-1 truncate text-xs text-subtle" :title="(template.events ?? []).map((e) => e.title).join(', ')">
              <template v-if="(template.events ?? []).length">
                🎟 {{ (template.events ?? []).map((e) => e.title).join(' · ') }}
              </template>
              <template v-else>Не назначен ни одному мероприятию</template>
            </p>

            <div class="mt-4 flex flex-wrap items-center gap-2">
              <NButton @click="openTemplate(template.id)">Открыть</NButton>
              <NButton
                variant="secondary"
                :loading="duplicatingId === template.id"
                @click="duplicateFromList(template)"
              >Дублировать</NButton>
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
