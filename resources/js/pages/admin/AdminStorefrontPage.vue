<script setup lang="ts">
/**
 * Конструктор витрины.
 *
 * Принцип: организация правит черновик и видит результат до сохранения.
 * Витрина — публичное лицо площадки, поэтому «сохранил — пошёл смотреть —
 * вернулся — поправил» слишком дорогой цикл: предпросмотр пересобирается
 * на каждое изменение, а на витрину уходит только то, что нажато «Сохранить».
 *
 * Порядок секций — drag & drop плюс кнопки ↑/↓: перетаскивание нельзя делать
 * единственным способом, иначе раздел недоступен с клавиатуры.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import NSelect from '@/components/ui/NSelect.vue'
import NSegmented from '@/components/ui/NSegmented.vue'
import NCheckbox from '@/components/ui/NCheckbox.vue'
import MediaPickerField from '@/components/admin/MediaPickerField.vue'
import SectionInspector from '@/components/admin/SectionInspector.vue'
import SectionShell from '@/components/storefront/widgets/SectionShell.vue'
import { isFlushWidget, widgetComponent } from '@/components/storefront/widgets/index'
import { useStorefrontStore } from '@/stores/storefront'
import { useUiStore } from '@/stores/ui'
import { useCatalogStore } from '@/stores/catalog'
import { themeVars } from '@/lib/theme'
import {
  PALETTES,
  WIDGETS,
  WIDGET_LABELS,
  cloneConfig,
  createSection,
  type SectionType,
  type StorefrontConfig,
  type StorefrontSection,
} from '@/lib/storefront'

const storefront = useStorefrontStore()
const ui = useUiStore()
const catalog = useCatalogStore()

const TABS = [
  { value: 'sections', label: 'Секции' },
  { value: 'branding', label: 'Брендинг' },
  { value: 'theme', label: 'Цвета' },
  { value: 'chrome', label: 'Меню и подвал' },
  { value: 'support', label: 'Контакты' },
  { value: 'preview', label: 'Предпросмотр' },
]

const tab = ref('sections')
const draft = ref<StorefrontConfig>(cloneConfig(storefront.config))
const selectedId = ref<string | null>(null)
const saving = ref(false)
const resetting = ref(false)
const savedSnapshot = ref('')
/** Что сервер не сохранил при последней записи (лимиты, приведённые значения). */
const saveWarnings = ref<string[]>([])
const ready = ref(false)
const systemTheme = window.matchMedia('(prefers-color-scheme: dark)')
const systemDark = ref(systemTheme.matches)
function updateSystemTheme(event: MediaQueryListEvent): void { systemDark.value = event.matches }
systemTheme.addEventListener('change', updateSystemTheme)
onBeforeUnmount(() => systemTheme.removeEventListener('change', updateSystemTheme))
const previewMode = computed(() => draft.value.theme.mode === 'auto'
  ? (systemDark.value ? 'dark' : 'light') : draft.value.theme.mode)

async function loadEditor(): Promise<void> {
  ready.value = false
  await storefront.loadAdmin()
  if (storefront.error) return
  draft.value = cloneConfig(storefront.config)
  savedSnapshot.value = JSON.stringify(draft.value)
  selectedId.value = draft.value.sections[0]?.id ?? null
  ready.value = true
}

onMounted(() => {
  void storefront.loadPublicMeta()
  void loadEditor()
  void catalog.load()
  window.addEventListener('beforeunload', warnBeforeUnload)
})
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeUnload))

const dirty = computed(() => ready.value && JSON.stringify(draft.value) !== savedSnapshot.value)
function warnBeforeUnload(event: BeforeUnloadEvent): void {
  if (!dirty.value) return
  event.preventDefault()
  event.returnValue = ''
}
onBeforeRouteLeave(() => {
  if (saving.value || resetting.value) return false
  return !dirty.value || window.confirm('Есть несохранённые изменения витрины. Уйти без сохранения?')
})

/**
 * При нескольких организациях гость видит витрину первой из них, а админ
 * правит свою. Без явной подсказки «Открыть витрину ↗» выглядит как отсутствие
 * сохранения — поэтому показываем предупреждение над формой.
 */
const editingAnotherOrganization = computed(() =>
  storefront.publicOrganizationId !== null
  && storefront.editingOrganizationId !== null
  && storefront.publicOrganizationId !== storefront.editingOrganizationId,
)

const selected = computed(() => draft.value.sections.find((s) => s.id === selectedId.value) ?? null)

/** Кисти предпросмотра: применяются к контейнеру, а не ко всему документу. */
const previewVars = computed(() => themeVars(draft.value.theme))

// ВАЖНО: режим темы черновика НЕ применяется к глобальной теме интерфейса.
// Преждездесь стоял `ui.setTheme(theme.mode)` — и вход в конструктор «переклю-
// чал» всю админку на светлую, если в черновике витрины была записана светлая
// (или наоборот). Предпросмотр самодостаточен: `previewVars` красит контейнер
// предпросмотра палитрой черновика, а тема админки — личная настройка.

/* ── Секции ─────────────────────────────────────────────────────────────── */

const dragIndex = ref<number | null>(null)

function select(section: StorefrontSection): void {
  selectedId.value = section.id
}

function addWidget(type: SectionType): void {
  if (draft.value.sections.length >= 40) return
  // `once` в реестре виджетов перестал быть мёртвым флагом: hero — первый экран,
  // второй такой же баннер ломает структуру страницы.
  const meta = WIDGETS.find((w) => w.type === type)
  if (meta?.once && draft.value.sections.some((s) => s.type === type)) {
    ui.notify('sun', 'Виджет уже есть', `«${meta.label}» можно добавить только один раз`)
    return
  }
  const section = createSection(type, WIDGET_LABELS[type] ?? '')
  draft.value.sections.push(section)
  selectedId.value = section.id
  ui.notify('brand', 'Виджет добавлен', WIDGET_LABELS[type] ?? type)
}

function removeSection(id: string): void {
  if (!window.confirm('Удалить секцию с витрины?')) return
  draft.value.sections = draft.value.sections.filter((s) => s.id !== id)
  if (selectedId.value === id) selectedId.value = draft.value.sections[0]?.id ?? null
}

function duplicate(index: number): void {
  const source = draft.value.sections[index]
  if (!source || draft.value.sections.length >= 40 || WIDGETS.find((w) => w.type === source.type)?.once) return
  const copy: StorefrontSection = JSON.parse(JSON.stringify(draft.value.sections[index]))
  copy.id = createSection(copy.type).id
  draft.value.sections.splice(index + 1, 0, copy)
  selectedId.value = copy.id
}

function move(index: number, delta: number): void {
  const target = index + delta
  if (target < 0 || target >= draft.value.sections.length) return
  const list = [...draft.value.sections]
  const moved = list.splice(index, 1)[0]
  if (!moved) return
  list.splice(target, 0, moved)
  draft.value.sections = list
}

function dropAt(target: number, event?: DragEvent): void {
  const from = dragIndex.value
  dragIndex.value = null
  if (from === null || from === target) return
  // «Перед целевой секцией» независимо от направления: без поправки при
  // перетаскивании сверху вниз элемент оказывался ПОСЛЕ цели, а снизу вверх —
  // перед ней. Позиция теперь предсказуема.
  const insertAt = from < target ? target - 1 : target
  const list = [...draft.value.sections]
  const moved = list.splice(from, 1)[0]
  if (!moved) return
  list.splice(insertAt, 0, moved)
  draft.value.sections = list
  event?.dataTransfer?.clearData()
}

/** Firefox не инициирует drag без dataTransfer.setData(). */
function onSectionDragStart(index: number, event: DragEvent): void {
  dragIndex.value = index
  event.dataTransfer?.setData('text/plain', String(index))
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

/* ── Тема ───────────────────────────────────────────────────────────────── */

function applyPreset(name: string): void {
  const palette = PALETTES[name]
  if (!palette) return
  draft.value.theme = { ...draft.value.theme, preset: name, ...palette.colors }
}

const presetList = computed(() =>
  Object.entries(PALETTES).map(([key, value]) => ({
    key,
    label: value.label,
    brand: value.colors.brand,
    accent: value.colors.accent,
  })),
)

/** Пресет подсвечивается, только если его кисти реально не переопределены. */
function presetActive(key: string): boolean {
  if (draft.value.theme.preset !== key) return false
  const palette = PALETTES[key]
  if (!palette) return false
  const c = palette.colors
  const t = draft.value.theme
  return c.brand === t.brand && c.brandStrong === t.brandStrong
    && c.accent === t.accent && c.accentStrong === t.accentStrong
}

/* ── Меню и подвал ──────────────────────────────────────────────────────── */

function addNav(): void {
  if (draft.value.header.nav.length >= 6) return
  draft.value.header.nav.push({ label: 'Раздел', to: '/' })
}

function removeNav(index: number): void {
  draft.value.header.nav.splice(index, 1)
}

function addFooterLink(): void {
  if (draft.value.footer.links.length >= 8) return
  draft.value.footer.links.push({ label: 'Ссылка', url: '/' })
}

function removeFooterLink(index: number): void {
  draft.value.footer.links.splice(index, 1)
}

function addSocial(): void {
  if (draft.value.footer.social.length >= 6) return
  draft.value.footer.social.push({ label: 'Соцсеть', url: 'https://' })
}

function removeSocial(index: number): void {
  draft.value.footer.social.splice(index, 1)
}

/* ── Сохранение ─────────────────────────────────────────────────────────── */

async function save(): Promise<void> {
  if (!ready.value || saving.value || resetting.value) return
  saving.value = true
  saveWarnings.value = []
  const submitted = cloneConfig(draft.value)
  const result = await storefront.save(submitted)
  saving.value = false
  if (!result.ok) {
    ui.notify('rose', 'Не удалось сохранить', 'Проверьте подключение и попробуйте ещё раз.')
    return
  }
  // Черновик обязан стать ТЕМ, что реально легло в БД: сервер нормализует
  // конфиг, и иначе «Сохранено» скрывало бы брошенные поля до перезагрузки.
  savedSnapshot.value = JSON.stringify(storefront.config)
  if (JSON.stringify(draft.value) === JSON.stringify(submitted)) {
    draft.value = cloneConfig(storefront.config)
  }
  selectedId.value = draft.value.sections.find((s) => s.id === selectedId.value)?.id ?? draft.value.sections[0]?.id ?? null
  saveWarnings.value = result.warnings
  ui.notify(
    result.warnings.length ? 'sun' : 'mint',
    'Витрина сохранена',
    result.warnings.length
      ? 'Часть значений сервер привёл к допустимым — подробности ниже.'
      : 'Изменения уже видны покупателям.',
  )
}

async function resetToDefault(): Promise<void> {
  if (!ready.value || saving.value || resetting.value) return
  if (!window.confirm('Вернуть витрину к настройкам по умолчанию? Текущая верстка будет потеряна.')) return
  resetting.value = true
  const ok = await storefront.reset()
  resetting.value = false
  if (!ok) {
    ui.notify('rose', 'Не удалось сбросить настройки')
    return
  }
  draft.value = cloneConfig(storefront.config)
  savedSnapshot.value = JSON.stringify(draft.value)
  saveWarnings.value = []
  selectedId.value = draft.value.sections[0]?.id ?? null
  ui.notify('sun', 'Витрина сброшена', 'Вернулись к конфигурации по умолчанию.')
}

function discard(): void {
  if (saving.value || resetting.value) return
  draft.value = cloneConfig(storefront.config)
  savedSnapshot.value = JSON.stringify(draft.value)
  saveWarnings.value = []
  selectedId.value = draft.value.sections[0]?.id ?? null
  ui.notify('neutral', 'Изменения отменены')
}

/** Режим темы для предпросмотра: не трогаем глобальную тему админки. */
const previewModeClass = computed(() => previewMode.value === 'dark' ? 'dark' : '')
</script>

<template>
  <div class="mx-auto max-w-[1500px]">
    <!-- Заголовок -->
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold tracking-tight text-content">Витрина</h1>
        <p class="mt-1 text-sm text-muted">
          Секции, цвета и брендинг публичной афиши.
          <span v-if="dirty" class="text-sun-400">Есть несохранённые изменения.</span>
          <span v-else-if="ready && storefront.isDefault" class="text-sun-400">Сейчас — конфигурация по умолчанию.</span>
          <span v-else-if="ready" class="text-mint-400">Сохранено.</span>
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <NButton variant="ghost" :disabled="!dirty || saving || resetting" @click="discard">Отменить</NButton>
        <NButton variant="outline" :loading="resetting" :disabled="!ready || saving || resetting" @click="resetToDefault">Сбросить</NButton>
        <a
          href="/"
          target="_blank"
          rel="noopener"
          class="inline-flex h-11 items-center gap-2 rounded-lg border border-line px-4 text-sm text-muted transition-colors hover:text-content"
        >
          Открыть витрину ↗
        </a>
        <NButton variant="primary" :loading="saving" :disabled="!dirty || saving || resetting" @click="save">Сохранить</NButton>
      </div>
    </div>

    <div v-if="editingAnotherOrganization" class="surface-card mt-4 border-sun-500/40 px-4 py-3 text-sm text-sun-400">
      Вы редактируете витрину своей организации, а публичная витрина на этом домене показывает другую.
      Сохранение подействует на вашу витрину; «Открыть витрину ↗» может показать не её.
    </div>

    <div v-if="saveWarnings.length" class="surface-card mt-4 border-sun-500/40 px-4 py-3 text-sm text-sun-400">
      <p class="font-medium">Сохранено не полностью — сервер привёл значения к допустимым:</p>
      <ul class="mt-1.5 list-inside list-disc space-y-0.5 text-xs">
        <li v-for="(warning, index) in saveWarnings" :key="index">{{ warning }}</li>
      </ul>
    </div>

    <p v-if="storefront.loading" class="mt-5 text-sm text-muted" role="status">Загружаем настройки витрины…</p>
    <div v-else-if="!ready" class="surface-card mt-5 p-4" role="alert">
      <p class="mb-3 text-sm text-rose-400">Не удалось загрузить настройки: {{ storefront.error }}</p>
      <NButton @click="loadEditor">Повторить загрузку</NButton>
    </div>
    <fieldset v-if="ready" :disabled="saving || resetting" class="min-w-0 border-0 p-0">
    <div class="mt-5">
      <NSegmented v-model="tab" :segments="TABS" aria-label="Разделы конструктора" />
    </div>

    <!-- ── Секции ─────────────────────────────────────────────────────── -->
    <div v-if="tab === 'sections'" class="mt-5 grid gap-5 lg:grid-cols-[minmax(280px,340px)_1fr]">
      <!-- Список секций -->
      <div class="surface-card order-1 p-3">
        <p class="px-1 pb-2 text-xs font-semibold uppercase tracking-wide text-subtle">Порядок на странице</p>

        <ul class="space-y-1">
          <li
            v-for="(section, index) in draft.sections"
            :key="section.id"
            draggable="true"
            :class="[
              'group flex items-center gap-2 rounded-lg border px-2.5 py-2 transition-colors',
              selectedId === section.id ? 'border-brand-500/50 bg-brand-500/8' : 'border-transparent hover:bg-surface-2',
            ]"
            @dragstart="onSectionDragStart(index, $event)"
            @dragend="dragIndex = null"
            @dragover.prevent
            @drop="dropAt(index, $event)"
            @click="select(section)"
          >
            <span aria-hidden="true" class="cursor-grab text-sm text-subtle">⋮⋮</span>
            <span class="min-w-0 flex-1">
              <span class="block truncate text-sm text-content">{{ section.title || WIDGET_LABELS[section.type] }}</span>
              <span class="block truncate text-2xs text-subtle">{{ WIDGET_LABELS[section.type] }}</span>
            </span>

            <!-- Управление секцией видно на тач-устройствах всегда: hover там
                 не существует, а скрытые за ним кнопки — недоступны. -->
            <span class="flex flex-none items-center gap-0.5 transition-opacity lg:opacity-0 lg:group-hover:opacity-100 lg:focus-within:opacity-100">
              <button
                type="button"
                class="grid h-7 w-7 place-items-center rounded text-xs text-subtle hover:bg-surface-3 hover:text-content"
                :aria-label="section.visible ? 'Скрыть секцию' : 'Показать секцию'"
                @click.stop="section.visible = !section.visible"
              >
                {{ section.visible ? '◉' : '○' }}
              </button>
              <button
                type="button"
                class="grid h-7 w-7 place-items-center rounded text-xs text-subtle hover:bg-surface-3 hover:text-content"
                aria-label="Выше"
                @click.stop="move(index, -1)"
              >↑</button>
              <button
                type="button"
                class="grid h-7 w-7 place-items-center rounded text-xs text-subtle hover:bg-surface-3 hover:text-content"
                aria-label="Ниже"
                @click.stop="move(index, 1)"
              >↓</button>
              <button
                type="button"
                class="grid h-7 w-7 place-items-center rounded text-xs text-subtle hover:bg-surface-3 hover:text-content"
                aria-label="Дублировать"
                :disabled="draft.sections.length >= 40 || WIDGETS.some((w) => w.type === section.type && w.once)"
                @click.stop="duplicate(index)"
              >⧉</button>
              <button
                type="button"
                class="grid h-7 w-7 place-items-center rounded text-xs text-subtle hover:bg-rose-500/10 hover:text-rose-400"
                aria-label="Удалить"
                @click.stop="removeSection(section.id)"
              >✕</button>
            </span>
          </li>
        </ul>

        <div v-if="!draft.sections.length" class="px-2 py-6 text-center text-sm text-subtle">
          Секций нет — добавьте виджет ниже.
        </div>

        <!-- Добавление виджетов -->
        <p class="px-1 pb-2 pt-4 text-xs font-semibold uppercase tracking-wide text-subtle">Добавить виджет · {{ draft.sections.length }}/40</p>
        <div class="grid grid-cols-2 gap-1.5">
          <button
            v-for="widget in WIDGETS"
            :key="widget.type"
            type="button"
            class="flex items-center gap-2 rounded-lg border border-line px-2.5 py-2 text-left text-xs text-muted transition-colors hover:border-brand-500/40 hover:bg-surface-2 hover:text-content"
            :title="widget.hint"
            :disabled="draft.sections.length >= 40 || (widget.once && draft.sections.some((s) => s.type === widget.type))"
            @click="addWidget(widget.type)"
          >
            <span aria-hidden="true" class="text-sm">{{ widget.icon }}</span>
            <span class="truncate">{{ widget.label }}</span>
          </button>
        </div>
      </div>

      <!-- Инспектор -->
      <div class="surface-card order-2 p-4 sm:p-5">
        <div v-if="selected">
          <div class="mb-4 flex items-center justify-between gap-3">
            <p class="text-sm font-semibold text-content">{{ WIDGET_LABELS[selected.type] ?? selected.type }}</p>
            <span
              class="rounded-full px-2 py-0.5 text-2xs"
              :class="selected.visible ? 'bg-mint-500/15 text-mint-400' : 'bg-surface-3 text-subtle'"
            >
              {{ selected.visible ? 'Показывается' : 'Скрыта' }}
            </span>
          </div>
          <SectionInspector :key="selected.id" :section="selected" />
        </div>

        <p v-else class="py-10 text-center text-sm text-subtle">Выберите секцию слева, чтобы настроить её.</p>
      </div>
    </div>

    <!-- ── Брендинг ───────────────────────────────────────────────────── -->
    <div v-else-if="tab === 'branding'" class="mt-5 grid gap-5 lg:grid-cols-[1fr_360px]">
      <div class="surface-card space-y-4 p-4 sm:p-5">
        <div class="grid gap-4 sm:grid-cols-2">
          <NInput v-model="draft.branding.name" label="Название площадки" placeholder="NABILET" />
          <NInput v-model="draft.branding.tagline" label="Подпись" placeholder="Билеты на события" />
        </div>
        <MediaPickerField
          :model-value="draft.branding.logoUrl"
          label="Логотип"
          hint="PNG/SVG с прозрачным фоном, до 200 px по высоте — выберите из медиатеки или вставьте URL"
          placeholder="https://…"
          @update:model-value="draft.branding.logoUrl = $event"
        />
        <div class="grid gap-4 sm:grid-cols-2">
          <NInput v-model="draft.branding.logoMark" label="Литера в шапке" hint="Если логотипа нет — показываем первую букву" maxlength="4" />
          <MediaPickerField
            :model-value="draft.branding.faviconUrl"
            label="Иконка сайта"
            hint="PNG 32×32 или 64×64, квадрат"
            placeholder="https://…"
            @update:model-value="draft.branding.faviconUrl = $event"
          />
        </div>
      </div>

      <!-- Живой пример шапки -->
      <div class="surface-card p-4">
        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-subtle">Как это выглядит</p>
        <div class="rounded-lg border border-line bg-surface-2 p-3">
          <div class="flex items-center gap-2.5">
            <img
              v-if="draft.branding.logoUrl"
              :src="draft.branding.logoUrl"
              :alt="draft.branding.name"
              class="h-9 w-auto max-w-[8rem] object-contain"
            />
            <span
              v-else
              class="grid h-9 w-9 place-items-center rounded-lg bg-brand-gradient text-base font-bold text-brand-on shadow-brand"
              aria-hidden="true"
            >{{ draft.branding.logoMark || draft.branding.name.slice(0, 1) }}</span>
            <span class="min-w-0">
              <span class="block truncate text-base font-bold text-content">{{ draft.branding.name }}</span>
              <span class="block truncate text-2xs text-subtle">{{ draft.branding.tagline }}</span>
            </span>
          </div>
        </div>
        <p class="mt-3 text-xs text-subtle">
          Логотип и название попадают в шапку витрины и в заголовок вкладки браузера.
        </p>
      </div>
    </div>

    <!-- ── Тема ───────────────────────────────────────────────────────── -->
    <div v-else-if="tab === 'theme'" class="mt-5 grid gap-5 lg:grid-cols-[1fr_360px]">
      <div class="surface-card space-y-5 p-4 sm:p-5">
        <div>
          <p class="mb-2.5 text-sm font-medium text-content">Цветовая схема</p>
          <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <button
              v-for="preset in presetList"
              :key="preset.key"
              type="button"
              :class="[
                'flex items-center gap-2.5 rounded-lg border p-2.5 text-left transition-colors',
                presetActive(preset.key) ? 'border-brand-500 bg-brand-500/8' : 'border-line hover:border-brand-500/40',
              ]"
              @click="applyPreset(preset.key)"
            >
              <span class="flex flex-none gap-1" aria-hidden="true">
                <span class="h-6 w-6 rounded-md" :style="{ background: preset.brand }" />
                <span class="h-6 w-6 rounded-md" :style="{ background: preset.accent }" />
              </span>
              <span class="truncate text-sm text-content">{{ preset.label }}</span>
            </button>
          </div>
        </div>

        <div class="border-t border-line pt-5">
          <p class="mb-2.5 text-sm font-medium text-content">Свои цвета</p>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1.5 block text-sm text-content">Основной</span>
              <span class="flex items-center gap-2">
                <input v-model="draft.theme.brand" type="color" class="h-11 w-14 cursor-pointer rounded-lg border border-line bg-surface" />
                <input
                  v-model="draft.theme.brand"
                  class="h-11 w-full rounded-lg border border-line bg-surface px-3 font-mono text-sm text-content focus:border-brand-400 focus:outline-none"
                />
              </span>
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm text-content">Основной (насыщеннее)</span>
              <span class="flex items-center gap-2">
                <input v-model="draft.theme.brandStrong" type="color" class="h-11 w-14 cursor-pointer rounded-lg border border-line bg-surface" />
                <input
                  v-model="draft.theme.brandStrong"
                  class="h-11 w-full rounded-lg border border-line bg-surface px-3 font-mono text-sm text-content focus:border-brand-400 focus:outline-none"
                />
              </span>
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm text-content">Акцент</span>
              <span class="flex items-center gap-2">
                <input v-model="draft.theme.accent" type="color" class="h-11 w-14 cursor-pointer rounded-lg border border-line bg-surface" />
                <input
                  v-model="draft.theme.accent"
                  class="h-11 w-full rounded-lg border border-line bg-surface px-3 font-mono text-sm text-content focus:border-brand-400 focus:outline-none"
                />
              </span>
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm text-content">Акцент (насыщеннее)</span>
              <span class="flex items-center gap-2">
                <input v-model="draft.theme.accentStrong" type="color" class="h-11 w-14 cursor-pointer rounded-lg border border-line bg-surface" />
                <input
                  v-model="draft.theme.accentStrong"
                  class="h-11 w-full rounded-lg border border-line bg-surface px-3 font-mono text-sm text-content focus:border-brand-400 focus:outline-none"
                />
              </span>
            </label>
          </div>
          <p class="mt-2 text-xs text-subtle">
            Остальные оттенки шкалы строятся из этих двух кистей — вводить одиннадцать
            цветов не нужно.
          </p>
        </div>

        <div class="grid gap-4 border-t border-line pt-5 sm:grid-cols-2">
          <label class="block">
            <span class="mb-1.5 block text-sm text-content">Скругление: {{ draft.theme.radius }} px</span>
            <input v-model.number="draft.theme.radius" type="range" min="0" max="28" class="w-full accent-brand-500" />
          </label>
          <NSelect
            v-model="draft.theme.mode"
            label="Тема по умолчанию"
            :options="[
              { value: 'auto', label: 'Как в системе' },
              { value: 'light', label: 'Светлая' },
              { value: 'dark', label: 'Тёмная' },
            ]"
            hint="Покупатель может переключить тему сам"
          />
        </div>

        <div class="border-t border-line pt-5">
          <p class="mb-2.5 text-sm font-medium text-content">Фон витрины</p>
          <div class="grid gap-2 sm:grid-cols-3">
            <button
              v-for="surface in [
                { value: 'tinted', label: 'Тонированный' },
                { value: 'clean', label: 'Чистый белый' },
                { value: 'dark', label: 'Тёмный' },
              ]"
              :key="surface.value"
              type="button"
              :class="[
                'rounded-lg border px-3 py-2.5 text-sm transition-colors',
                draft.theme.surface === surface.value ? 'border-brand-500 bg-brand-500/8 text-content' : 'border-line text-muted hover:border-brand-500/40',
              ]"
              @click="draft.theme.surface = surface.value as StorefrontConfig['theme']['surface']"
            >
              {{ surface.label }}
            </button>
          </div>
        </div>
      </div>

      <!-- Превью темы -->
      <div class="surface-card p-4" :style="previewVars" :data-preview-mode="previewMode" :data-preview-surface="draft.theme.surface">
        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-subtle">Пробник</p>
        <div class="space-y-3 rounded-lg border border-line bg-surface p-4">
          <div class="rounded-lg bg-brand-gradient px-4 py-5 text-center text-sm font-semibold text-brand-on">
            Градиент бренда
          </div>
          <div class="flex gap-2">
            <span class="flex-1 rounded-lg bg-brand-500 px-3 py-2.5 text-center text-sm font-medium text-brand-on shadow-brand">Купить</span>
            <span class="flex-1 rounded-lg bg-accent-500 px-3 py-2.5 text-center text-sm font-medium text-accent-on">Бронь</span>
          </div>
          <div class="rounded-lg border border-line bg-surface-2 p-3">
            <p class="text-sm text-content">Карточка мероприятия</p>
            <p class="mt-0.5 text-xs text-muted">Обычный текст и подпись</p>
            <p class="mt-2 text-sm font-semibold text-brand-500">от 1 200 ₽</p>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Меню и подвал ──────────────────────────────────────────────── -->
    <div v-else-if="tab === 'chrome'" class="mt-5 grid gap-5 lg:grid-cols-2">
      <div class="surface-card space-y-4 p-4 sm:p-5">
        <p class="text-sm font-semibold text-content">Шапка</p>
        <NCheckbox v-model="draft.header.showSearch" label="Поиск по событиям" />
        <NCheckbox v-model="draft.header.showCart" label="Корзина" />
        <NCheckbox v-model="draft.header.showThemeToggle" label="Переключатель темы" />

        <div class="border-t border-line pt-4">
          <p class="mb-2 text-sm text-content">Пункты меню</p>
          <div v-for="(item, index) in draft.header.nav" :key="index" class="mb-3 grid items-end gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
            <NInput v-model="item.label" label="Название пункта" placeholder="Афиша" class="min-w-0" />
            <NInput v-model="item.to" label="Адрес" placeholder="/" class="min-w-0" />
            <NButton variant="ghost" size="sm" aria-label="Удалить пункт" @click="removeNav(index)">✕</NButton>
          </div>
          <NButton variant="secondary" size="sm" icon="+" :disabled="draft.header.nav.length >= 6" @click="addNav">Добавить пункт ({{ draft.header.nav.length }}/6)</NButton>
        </div>
      </div>

      <div class="surface-card space-y-4 p-4 sm:p-5">
        <p class="text-sm font-semibold text-content">Подвал</p>
        <NInput v-model="draft.footer.text" label="Строка копирайта" />

        <div class="space-y-3 border-t border-line pt-4">
          <p class="text-sm font-medium text-content">Колонки подвала</p>
          <p class="text-xs text-muted">До четырёх колонок, до восьми ссылок в каждой. Заполните заголовок и хотя бы одну ссылку.</p>
          <div v-for="(column, index) in draft.footer.columns" :key="index" class="space-y-3 rounded-lg border border-line p-3">
            <NInput v-model="column.title" label="Заголовок колонки" maxlength="40" />
            <div v-for="(link, linkIndex) in column.links" :key="linkIndex" class="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
              <NInput v-model="link.label" label="Название ссылки" />
              <NInput v-model="link.url" label="Адрес ссылки" placeholder="/contacts" />
              <NButton variant="ghost" aria-label="Удалить ссылку из колонки" @click="column.links.splice(linkIndex, 1)">✕</NButton>
            </div>
            <div class="flex flex-wrap gap-2">
              <NButton variant="secondary" size="sm" :disabled="column.links.length >= 8" @click="column.links.push({ label: 'Ссылка', url: '/' })">Добавить ссылку в колонку</NButton>
              <NButton variant="ghost" size="sm" @click="draft.footer.columns.splice(index, 1)">Удалить колонку</NButton>
            </div>
          </div>
          <NButton variant="secondary" size="sm" :disabled="draft.footer.columns.length >= 4" @click="draft.footer.columns.push({ title: 'Раздел', links: [{ label: 'Ссылка', url: '/' }] })">Добавить колонку</NButton>
        </div>

        <div class="border-t border-line pt-4">
          <p class="mb-2 text-sm text-content">Ссылки в подвале</p>
          <div v-for="(link, index) in draft.footer.links" :key="index" class="mb-3 grid items-end gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
            <NInput v-model="link.label" label="Название ссылки" placeholder="Помощь" class="min-w-0" />
            <NInput v-model="link.url" label="Адрес" placeholder="/" class="min-w-0" />
            <NButton variant="ghost" size="sm" aria-label="Удалить ссылку" @click="removeFooterLink(index)">✕</NButton>
          </div>
          <NButton variant="secondary" size="sm" icon="+" :disabled="draft.footer.links.length >= 8" @click="addFooterLink">Добавить ссылку ({{ draft.footer.links.length }}/8)</NButton>
        </div>

        <div class="border-t border-line pt-4">
          <p class="mb-2 text-sm text-content">Соцсети</p>
          <div v-for="(link, index) in draft.footer.social" :key="index" class="mb-3 grid items-end gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
            <NInput v-model="link.label" label="Название соцсети" placeholder="Телеграм" class="min-w-0" />
            <NInput v-model="link.url" label="Адрес" placeholder="https://" class="min-w-0" />
            <NButton variant="ghost" size="sm" aria-label="Удалить соцсеть" @click="removeSocial(index)">✕</NButton>
          </div>
          <NButton variant="secondary" size="sm" icon="+" :disabled="draft.footer.social.length >= 6" @click="addSocial">Добавить соцсеть ({{ draft.footer.social.length }}/6)</NButton>
        </div>
      </div>
    </div>

    <!-- ── Запасные контакты для виджета на чужом сайте ────────────── -->
    <div v-else-if="tab === 'support'" class="mt-5 grid gap-5 lg:grid-cols-2">
      <div class="surface-card space-y-4 p-4 sm:p-5">
        <p class="text-sm font-semibold text-content">Если касса недоступна</p>
        <p class="text-xs text-muted">
          Эти контакты увидит покупатель в окне виджета схемы зала, когда сервер продаж
          не отвечает или схема не загрузилась. Телефон и e-mail подставлены из карточки
          организации — можно заменить на дежурные.
        </p>
        <NInput v-model="draft.support.phone" label="Телефон" placeholder="+7 999 123-45-67" />
        <NInput v-model="draft.support.email" label="E-mail" placeholder="cash@example.ru" />
        <NInput v-model="draft.support.telegram" label="Telegram (ссылка)" placeholder="https://t.me/..." />
        <NInput v-model="draft.support.whatsapp" label="WhatsApp (ссылка)" placeholder="https://wa.me/..." />
      </div>
      <div class="surface-card space-y-4 p-4 sm:p-5">
        <p class="text-sm font-semibold text-content">Сообщение</p>
        <NInput v-model="draft.support.message" label="Что написать покупателю" />
        <p class="text-xs text-muted">
          Кнопки «Позвонить», мессенджеры и e-mail появятся автоматически для заполненных полей.
          Всегда есть кнопка повтора и ссылка на страницу «Контакты».
        </p>
      </div>
    </div>

    <!-- ── Предпросмотр ───────────────────────────────────────────────── -->
    <div v-else class="mt-5">
      <div class="surface-card overflow-hidden">
        <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
          <p class="text-sm text-muted">Так витрину увидит покупатель</p>
          <div class="flex items-center gap-2">
            <NButton variant="ghost" size="sm" @click="tab = 'sections'">К секциям</NButton>
            <NButton variant="primary" size="sm" :loading="saving" :disabled="!dirty" @click="save">Сохранить</NButton>
          </div>
        </div>

        <!-- Внутри — настоящие виджеты витрины с кистями черновика, плюс
             шапка/подвал: иначе вкладка «Предпросмотр» показывала только
             секции и обещала «как увидит покупатель», хотя покупатель ещё
             видит брендинг, меню и копирайт. -->
        <div
          :style="previewVars"
          :data-preview-surface="draft.theme.surface"
          :data-preview-mode="previewMode"
          :class="[previewModeClass, 'max-h-[75vh] overflow-y-auto bg-canvas']"
        >
          <header class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
            <span class="flex min-w-0 items-center gap-2.5">
              <img
                v-if="draft.branding.logoUrl"
                :src="draft.branding.logoUrl"
                :alt="draft.branding.name"
                class="h-8 w-auto max-w-24 object-contain"
              />
              <span v-else class="grid h-8 w-8 place-items-center rounded-lg bg-brand-gradient text-sm font-bold text-brand-on">
                {{ draft.branding.logoMark || draft.branding.name.slice(0, 1) }}
              </span>
              <span class="min-w-0">
                <span class="block truncate text-sm font-bold text-content">{{ draft.branding.name }}</span>
                <span class="block truncate text-2xs text-subtle">{{ draft.branding.tagline }}</span>
              </span>
            </span>
            <span class="hidden flex-wrap gap-3 text-xs text-muted sm:flex">
              <span v-for="(item, index) in draft.header.nav" :key="index">{{ item.label }}</span>
            </span>
            <span class="flex gap-2 text-xs text-subtle">
              <span v-if="draft.header.showSearch">⌕</span>
              <span v-if="draft.header.showCart">◯ Корзина</span>
              <span v-if="draft.header.showThemeToggle">☾</span>
            </span>
          </header>

          <template v-for="section in draft.sections.filter((s) => s.visible)" :key="section.id">
            <SectionShell
              v-if="widgetComponent(section.type)"
              :title="section.title"
              :subtitle="section.subtitle"
              :flush="isFlushWidget(section.type)"
            >
              <component :is="widgetComponent(section.type)" :section="section" />
            </SectionShell>
          </template>
          <p v-if="!draft.sections.filter((s) => s.visible).length" class="py-16 text-center text-sm text-subtle">
            Все секции скрыты — витрина пустая.
          </p>

          <footer class="border-t border-line px-4 py-5 text-xs text-subtle">
            <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <div v-for="(column, index) in draft.footer.columns" :key="index">
                <p class="mb-2 text-sm font-semibold text-content">{{ column.title }}</p>
                <p v-for="(link, linkIndex) in column.links" :key="linkIndex" class="mt-1">{{ link.label }}</p>
              </div>
            </div>
            <p>{{ draft.footer.text }}</p>
            <p class="mt-2 flex flex-wrap gap-3">
              <span v-for="(link, index) in draft.footer.links" :key="index">{{ link.label }}</span>
              <span v-for="(link, index) in draft.footer.social" :key="`s${index}`" class="text-brand-500">{{ link.label }}</span>
            </p>
          </footer>
        </div>
      </div>
    </div>
    </fieldset>
  </div>
</template>
