<script setup lang="ts">
/**
 * Карточка события.
 *
 * Ключевой экран пути: здесь пользователь выбирает не «билет», а конкретный
 * сеанс. Поэтому сеансы — не список текста, а крупные кнопки с датой, временем
 * и остатком мест: «куда я иду и во сколько» должно считываться за секунду.
 * Кнопка покупки прилипает к низу экрана — на мобильном её не нужно искать.
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NBadge from '@/components/ui/NBadge.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import { get } from '@/lib/api'
import { customerUser, isCustomerSession } from '@/lib/customer'
import { trackEvent } from '@/lib/metrika'
import { useSeo } from '@/lib/seo'
import { money, dateFull, time, seatsLabel } from '@/lib/format'
// Радиус точки в предпросмотре считаем той же формулой, что интерактивная
// схема зала: иначе кружки налезают друг на друга и зал читается как пятно.
import { nearestPointDistance } from '@/lib/seatViewport'
import { cn } from '@/lib/cn'
import { useUiStore } from '@/stores/ui'
import { useLoyaltyStore } from '@/stores/loyalty'
import type { EventStatus } from '@/lib/types'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const loyalty = useLoyaltyStore()

interface SessionItem {
  id: string
  starts_at?: string
  startsAt?: string
  hall?: string
  hall_name?: string
  available_seats?: number
  availableSeats?: number
  status?: string
}

interface EventDetail {
  id: string
  slug: string
  public_id: string
  title: string
  short_description?: string
  description?: string
  status: EventStatus
  age_limit?: string | number | null
  duration_minutes?: number
  poster?: string
  cover?: string
  seo_title?: string
  seo_description?: string
  category?: { name?: string } | string | null
  sessions?: SessionItem[]
  organization?: { name?: string } | null
  venue?: { name?: string; city?: string } | null
  price_from_minor?: number
  /** Дополнительные фото и видео: приходят в этом же ответе, см. showBySlug. */
  gallery?: GalleryItem[]
  /**
   * Исполнители события (стратегия SEO, B-1).
   *
   * Только имя и адрес: профиль артиста (биография, жанр, соцсети) живёт на
   * серверной странице `/artist/{slug}` и там же индексируется. Копия профиля
   * здесь разошлась бы с той страницей при первой правке.
   */
  artists?: ArtistRef[]
}

/** Ссылка на артиста: то, что нужно для `<a href>` и подписи. */
interface ArtistRef {
  name: string
  slug: string
}

/**
 * Элемент галереи.
 *
 * `mime_type` приходит с сервера, а не угадывается по расширению: подписывать
 * `<img>` на видео значит показать битую картинку вместо плеера.
 */
interface GalleryItem {
  id: number
  url: string
  filename: string
  mime_type: string
  width: number | null
  height: number | null
  alt_text: string | null
  position: number
}

const event = ref<EventDetail | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)

async function loadEvent(): Promise<void> {
  const slug = String(route.params.slug ?? '')
  loading.value = true
  loadError.value = null
  try {
    const res = await get<EventDetail | { data: EventDetail }>(`/events/by-slug/${encodeURIComponent(slug)}`)
    // Контроллеры отдают конверт `{ data: {...} }`; разворот «голого» объекта
    // оставлен страховкой. Двойной каст, стоявший здесь, давал union-тип, который
    // не присваивался `EventDetail | null`, — `npm run typecheck` падал навсегда.
    const payload = res.data
    event.value = 'data' in payload ? payload.data : payload
    // E2: цель `event_view` была сконфигурирована в config/metrika.php, но не
    // имела call-site — воронка в Директе рвалась на первом же шаге.
    void trackEvent('event_view', {
      params: {
        event_id: event.value?.public_id ?? slug,
        slug: event.value?.slug ?? slug,
      },
    }).catch(() => {})
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e)
    event.value = null
  } finally {
    loading.value = false
  }
}

watch(() => route.params.slug, loadEvent, { immediate: true })

const activeSessionId = ref<string>('')
const activeSession = computed<SessionItem | undefined>(() => {
  const sessions = event.value?.sessions ?? []
  return sessions.find((s) => s.id === activeSessionId.value) ?? sessions[0]
})

/* Первый сеанс — активный по умолчанию */
watch(activeSession, (s) => {
  if (s && !activeSessionId.value) activeSessionId.value = s.id
})

const catName = computed(() => {
  const c = event.value?.category
  return typeof c === 'string' ? c : (c?.name ?? '')
})

/**
 * Исполнители события (стратегия SEO, B-1).
 *
 * Элемент без адреса отбрасывается: ссылка без `slug` ведёт в никуда, а битая
 * ссылка на странице хуже её отсутствия — и для человека, и для краулера,
 * который помечает такой адрес как ошибку. Отсев делается здесь, а не на
 * сервере, потому что это защита ОТОБРАЖЕНИЯ: сервер уже фильтрует по
 * `status = 'active'` (`Event::artists()`).
 */
const artists = computed<ArtistRef[]>(() =>
  (event.value?.artists ?? []).filter((a) => a && typeof a.slug === 'string' && a.slug !== ''),
)

/**
 * Alt афиши (SEO-аудит P-19).
 *
 * Формулировка совпадает с `og:image:alt` серверной обёртки
 * (`EventPageController`): превью в мессенджере и картинка на странице должны
 * описываться одинаково, иначе расхождение читается как два разных
 * изображения. Название мероприятия в alt обязательно — именно оно и есть
 * смысл картинки; «афиша» без названия не отвечает ни на один запрос.
 */
const posterAlt = computed(() =>
  event.value ? `Афиша мероприятия «${event.value.title}»` : '',
)

/**
 * E3: SEO-поля события приходили из API и не применялись — заголовок вкладки
 * и превью ссылки у ВСЕХ событий оставались корневыми из `index.html`.
 *
 * Приоритет — серверные `seo_*`: их заполняет организатор, и именно они
 * совпадают с og-блоком серверной обёртки (`EventPageController`), которую
 * видят краулеры без JS. Фолбэк — название события и короткое описание.
 */
useSeo(() => {
  const current = event.value
  if (!current) return null
  return {
    title: current.seo_title?.trim() || `${current.title} — билеты`,
    description:
      current.seo_description?.trim() ||
      current.short_description?.trim() ||
      null,
    image: current.poster ?? current.cover ?? null,
    url: window.location.href,
  }
})

const startsAtOf = (s: SessionItem): string => s.starts_at ?? s.startsAt ?? ''
const availableOf = (s: SessionItem): number => s.available_seats ?? s.availableSeats ?? 0
const hallOf = (s: SessionItem): string => s.hall_name ?? s.hall ?? ''

/* ── Дополнительные фото и видео ─────────────────────────────────────────────
 * Приходят в том же ответе, что и само событие (`gallery`), поэтому отдельного
 * запроса и состояния загрузки здесь нет: пока грузится страница, грузится и
 * галерея. Порядок задаёт сервер (`position`) — на витрине он тот же, что
 * администратор выставил в карточке мероприятия.
 */
const gallery = computed<GalleryItem[]>(() => event.value?.gallery ?? [])

const isGalleryImage = (item: GalleryItem): boolean => item.mime_type.startsWith('image/')

/** Просмотр во весь экран. `null` — закрыто. */
const lightbox = ref<GalleryItem | null>(null)

function openLightbox(item: GalleryItem): void {
  lightbox.value = item
}

function closeLightbox(): void {
  lightbox.value = null
}

// Escape закрывает просмотр. Слушатель висит только пока окно открыто: иначе
// страница перехватывала бы Escape всегда, включая момент, когда закрывать
// нечего.
watch(lightbox, (open) => {
  if (typeof window === 'undefined') return

  if (open) {
    window.addEventListener('keydown', onLightboxKey)
  } else {
    window.removeEventListener('keydown', onLightboxKey)
  }
})

function onLightboxKey(e: KeyboardEvent): void {
  if (e.key === 'Escape') closeLightbox()
}

const soldOut = computed(() => !activeSession.value || availableOf(activeSession.value) === 0)
const fewLeft = computed(() => {
  const left = activeSession.value ? availableOf(activeSession.value) : 0
  return left > 0 && left <= 50
})

/* Предпросмотр зала: реальные сектора и зоны из инвентаря выбранного сеанса.
 * Раньше на этом месте стояла СИНТЕТИЧЕСКАЯ сетка 7×20 — «места», которых
 * на нашей схеме никогда не существовало. Жалоба пользователя «не
 * использовал реальные схемы залов» относилась сюда: витрина показывала
 * фейк вместо настоящего зала. Теперь грузим `/inventory?session_id=…` и
 * рисуем то, что на самом деле продаётся: по сектору/зоне — название,
 * тип, вместимость, минимальная цена и сколько мест свободно.
 */
type InvItem = {
  id: number
  type: 'seat' | 'standing'
  sector_name?: string | null
  row_label?: string | null
  seat_number?: string | number | null
  price_amount: number
  price_minor?: number
  currency?: string
  capacity?: number
  available_quantity?: number
  status: string
  /* Геометрия места из схемы зала. Именно она делает предпросмотр
   * настоящей картой: x/y/width/height — координаты, в которых
   * нарисован зал (те же, что использует интерактивная схема). */
  seat?: {
    x?: number | string | null
    y?: number | string | null
    width?: number | string | null
    height?: number | string | null
    rotation?: number | string | null
    row_number?: number | string | null
    number?: number | string | null
    sector_name?: string | null
  } | null
  metadata_json?: { sector_name?: string; row_label?: string; seat_number?: string | number | null } | null
}

type SectorSummary = {
  name: string
  kind: 'seat' | 'standing'
  total: number
  available: number
  prices: number[]
}

const inventory = ref<{ items: InvItem[]; loading: boolean }>({ items: [], loading: false })

async function loadInventory(): Promise<void> {
  const sid = activeSession.value?.id
  if (!sid) {
    inventory.value = { items: [], loading: false }
    return
  }
  inventory.value.loading = true
  try {
    // /api/v1/inventory отдаёт всю развёрнутую позицию: цены,
    // остатки, ссылку на зал и сектор. Стоячие зоны приходят как
    // type='standing' с ёмкостью; места — type='seat' с координатами.
    const res = await get<{ data?: { data?: InvItem[] } | InvItem[] }>(
      `/inventory?session_id=${encodeURIComponent(sid)}&per_page=500`,
    )
    const payload = res.data
    const items: InvItem[] = Array.isArray(payload?.data)
      ? (payload!.data as InvItem[])
      : Array.isArray(payload)
        ? (payload as InvItem[])
        : []
    inventory.value = { items, loading: false }
  } catch {
    inventory.value = { items: [], loading: false }
  }
}

watch(activeSession, loadInventory, { immediate: true })
watch(() => activeSession.value?.id, loadInventory)

function nameOf(item: InvItem): string {
  const fromMeta = item.metadata_json?.sector_name ?? item.sector_name
  if (fromMeta && fromMeta !== '') return fromMeta
  return item.type === 'standing' ? 'Танцпол' : 'Сектор'
}

/**
 * Группируем инвентарь по секторам/зонам. Для каждой зоны считаем
 * ёмкость, сколько реально свободно и какой диапазон цен.
 */
const sectors = computed<SectorSummary[]>(() => {
  const groups = new Map<string, SectorSummary>()
  for (const item of inventory.value.items) {
    const name = nameOf(item) || 'Без названия'
    const price = item.price_amount ?? item.price_minor ?? 0
    if (!groups.has(name)) {
      groups.set(name, {
        name,
        kind: item.type,
        total: 0,
        available: 0,
        prices: [],
      })
    }
    const g = groups.get(name)!
    if (item.type === 'standing') {
      g.total += item.capacity ?? 0
      g.available += item.available_quantity ?? 0
    } else {
      g.total += 1
      // «Свободно» = позиция существует, не в холде и не продана.
      if (item.status === 'available') g.available += 1
    }
    if (price > 0) g.prices.push(price)
  }
  return [...groups.values()].sort((a, b) => a.name.localeCompare(b.name, 'ru'))
})

const totalSeats = computed(() => sectors.value.reduce((s, x) => s + x.total, 0))
const totalAvailable = computed(() => sectors.value.reduce((s, x) => s + x.available, 0))
const priceRange = computed(() => {
  const all = sectors.value.flatMap((s) => s.prices)
  if (!all.length) return null
  const min = Math.min(...all)
  const max = Math.max(...all)
  return { min, max }
})

/**
 * Настоящая карта зала для предпросмотра.
 *
 * Координаты берём из `inventory_items.seat` — это ровно та геометрия, по
 * которой нарисована интерактивная схема (`CoordSeatMap`), а не отдельная
 * «похожая» сетка. Поэтому предпросмотр совпадает с тем, что человек увидит
 * на странице выбора мест: те же ряды, те же проходы, те же секторы.
 *
 * `null`, когда мест нет вовсе (стоячие зоны вроде «Танцпола»): у зоны
 * координат нет, рисовать нечего — там показываем список секторов.
 */
const seatMap = computed(() => {
  const items = inventory.value.items.filter((i) => i.type === 'seat' && i.seat)
  if (items.length === 0) return null

  const num = (v: unknown, fallback = 0): number => {
    const n = typeof v === 'number' ? v : Number(v)
    return Number.isFinite(n) ? n : fallback
  }

  type Raw = { key: number; cx: number; cy: number; free: boolean; label: string }
  const raw: Raw[] = []
  let minX = Number.POSITIVE_INFINITY
  let minY = Number.POSITIVE_INFINITY
  let maxX = Number.NEGATIVE_INFINITY
  let maxY = Number.NEGATIVE_INFINITY

  for (const item of items) {
    const s = item.seat!
    const w = num(s.width, 0) || 2
    const h = num(s.height, 0) || 2
    const x = num(s.x)
    const y = num(s.y)
    const sector = s.sector_name ?? ''
    const row = s.row_number ?? '?'
    const n = s.number ?? 0
    raw.push({
      key: item.id,
      cx: x + w / 2,
      cy: y + h / 2,
      free: item.status === 'available',
      label: [sector, `ряд ${row}`, `место ${n}`].filter((p) => p !== '').join(' · '),
    })
    if (x < minX) minX = x
    if (y < minY) minY = y
    if (x + w > maxX) maxX = x + w
    if (y + h > maxY) maxY = y + h
  }

  /*
   * Радиус ОДИН на весь зал и считается от фактического шага между местами —
   * той же формулой, что интерактивная схема (`fitSeatRadius`): 34% от
   * ближайшего расстояния. Раньше радиус брался от размера самого места, и
   * кружки налезали друг на друга — зал читался как «какие-то рандомные
   * кружки», а не как ряды кресел.
   */
  const nearest = nearestPointDistance(
    raw.map((d) => ({ x: d.cx, y: d.cy })),
    1,
  )
  const radius = Number.isFinite(nearest) ? nearest * 0.34 : 2
  const dots = raw.map((d) => ({ key: d.key, cx: d.cx, cy: d.cy, r: radius, free: d.free, label: d.label }))

  const spanX = Math.max(maxX - minX, 1)
  const spanY = Math.max(maxY - minY, 1)
  const pad = Math.max(spanX * 0.03, 1)

  return {
    dots,
    viewBox: `${minX - pad} ${minY - pad} ${spanX + pad * 2} ${spanY + pad * 2}`,
    /* Соотношение сторон зала для CSS `aspect-ratio`. Зажимаем: вытянутый
     * в нитку зал иначе превратил бы предпросмотр в полоску или в башню. */
    ratio: Math.min(Math.max(spanY / spanX, 0.2), 1.05),
  }
})

/* Подсказка над предпросмотром: сектор, ряд, место. */
const mapBox = ref<HTMLElement | null>(null)
const mapTip = ref<{ label: string; x: number; y: number } | null>(null)

function showMapTip(e: MouseEvent, label: string): void {
  const box = mapBox.value
  if (box === null) return
  const r = box.getBoundingClientRect()
  mapTip.value = { label, x: e.clientX - r.left, y: e.clientY - r.top }
}

function moveMapTip(e: MouseEvent): void {
  const box = mapBox.value
  if (box === null || mapTip.value === null) return
  const r = box.getBoundingClientRect()
  mapTip.value = { ...mapTip.value, x: e.clientX - r.left, y: e.clientY - r.top }
}

function hideMapTip(): void {
  mapTip.value = null
}

function openSeats(): void {
  if (!event.value || !activeSession.value) return
  router.push({
    path: `/event/${event.value.slug}/seats`,
    query: { session: activeSession.value.id },
  })
}

function buy(): void {
  if (soldOut.value || !activeSession.value || !event.value) return
  router.push({ path: `/event/${event.value.slug}/seats`, query: { session: activeSession.value.id } })
}

/**
 * Достаточно ли крупная афиша, чтобы растянуть её на весь hero.
 *
 * Часть афиш Касса-Югры отдаёт в 216×306 — в полосе 1280×448 такая картинка
 * растягивается в шесть раз и превращается в мыло. Для мелких исходников
 * hero собирается иначе: размытая копия на фоне + резкая афиша по центру
 * (`object-contain`). Порог 600px по ширине: 710×1000 и 904×1280
 * отрисовываются обычным `object-cover` и выглядят хорошо, а 216×306 — нет.
 * Флаг ставится по `naturalWidth` после загрузки, поэтому решение
 * принимается по реальному файлу, а не по имени события.
 */
const posterIsLarge = ref(false)

function onPosterLoad(e: Event): void {
  const img = e.target as HTMLImageElement | null
  posterIsLarge.value = (img?.naturalWidth ?? 0) >= 600
}

/* ── «Клуб зрителей»: ♡ избранное, 🔔 подписка ─────────────────────────────
 *
 * Обе кнопки адресуют мероприятие по `public_id` (см. `EventDetail`), потому
 * что `/me/events/{event}/…` резолвит событие только по нему. Гостя обе
 * уводят на вход с возвратом на эту же страницу: `/me/*` требует аккаунта, и
 * «молча ничего не произошло» было бы худшим ответом на нажатие.
 */
const favoriteOn = computed(() => {
  const eventId = event.value?.public_id ?? ''
  return eventId !== '' && loyalty.isFavorite(eventId)
})

const followingOn = computed(() => {
  const eventId = event.value?.public_id ?? ''
  return eventId !== '' && loyalty.isFollowing(eventId)
})

async function onFavorite(): Promise<void> {
  const eventId = event.value?.public_id ?? ''
  if (eventId === '') return

  if (!isCustomerSession()) {
    router.push({ path: '/login', query: { redirect: route.fullPath } })
    return
  }

  try {
    await loyalty.toggleFavorite(eventId)
  } catch {
    ui.notify('rose', 'Не удалось обновить избранное', 'Попробуйте ещё раз')
  }
}

async function onFollow(): Promise<void> {
  const eventId = event.value?.public_id ?? ''
  if (eventId === '') return

  if (!isCustomerSession()) {
    router.push({ path: '/login', query: { redirect: route.fullPath } })
    return
  }

  try {
    const nowOn = await loyalty.toggleFollow(eventId)
    ui.notify(
      'mint',
      nowOn ? 'Вы подписаны' : 'Подписка отменена',
      nowOn ? `Письмо о новых сеансах придёт на ${customerUser()?.email ?? 'ваш адрес'}.` : undefined,
    )
  } catch {
    ui.notify('rose', 'Не удалось изменить подписку', 'Попробуйте ещё раз')
  }
}
</script>

<template>
  <div class="pb-28 md:pb-12">
      <!-- Постер -->
      <!--
        АФИША — ТЕГОМ <img>, А НЕ CSS background-image (SEO-аудит P-19).

        Было `:style="{ background: url(...) }"`. Для поиска изображений это
        значит, что афиши мероприятия не существует: Google Images индексирует
        теги <img> с атрибутом alt, а фоновое изображение для него не картинка
        страницы, а её оформление. У мероприятия с афишей не было ни одного
        изображения в разметке — при том что афиша это ровно тот контент, по
        которому такие страницы и находят.

        Alt берётся тот же, что и в `og:image:alt` на сервере: одна формулировка
        в превью соцсети и на странице, а не две разные.

        Градиент остался ПОД картинкой и работает как заглушка, когда афиши нет:
        `v-if` на <img> не оставляет пустого тега без src (это ошибка вёрстки,
        браузер покажет рамку сломанного изображения).
      -->
      <div class="relative h-72 overflow-hidden sm:h-96 lg:h-[28rem]">
        <div
          class="absolute inset-0"
          :style="{ background: 'linear-gradient(145deg, #7C3AED 0%, #FF5C22 100%)' }"
          aria-hidden="true"
        />
        <!--
          Мелкая афиша не растягивается на всю полосу: под ней лежит её же
          размытая копия, а сама она показывается целиком по центру. Иначе
          картинка 216×306 в блоке 1280×448 — это шестикратное увеличение.
        -->
        <img
          v-if="event?.poster && !posterIsLarge"
          :src="event.poster"
          class="absolute inset-0 h-full w-full scale-125 object-cover blur-2xl"
          alt=""
          aria-hidden="true"
          decoding="async"
        >
        <img
          v-if="event?.poster"
          :src="event.poster"
          :alt="posterAlt"
          class="absolute inset-0 h-full w-full"
          :class="posterIsLarge ? 'object-cover object-top' : 'object-contain'"
          decoding="async"
          @load="onPosterLoad"
        >
        <div class="absolute inset-0 bg-gradient-to-t from-canvas via-canvas/30 to-transparent" />
      </div>

      <div v-if="loading" class="mx-auto max-w-content px-4 py-8 text-sm text-subtle">
        Загрузка события…
      </div>

      <div v-else-if="loadError" class="mx-auto max-w-content px-4 py-8 text-sm text-danger-500">
        Не удалось загрузить событие: {{ loadError }}
      </div>

      <!--
        `relative z-10` обязателен: hero выше — позиционированный блок, а
        этот контейнер подтянут к нему отрицательным отступом и сам по себе
        статический. Позиционированные элементы рисуются ПОВЕРХ статических,
        поэтому без z-index hero перекрывал верхнюю часть заголовка.

        Отступ ровно `-mt-10` (40px = высота строки бейджей): бейджи «В
        продаже / В избранное / Следить» ложатся на нижнюю кромку афиши, а
        сам заголовок начинается уже на фоне страницы. При большем нахлёсте
        тёмный текст попадал на тёмную афишу и первая строка названия
        становилась нечитаемой.
      -->
      <div v-else-if="event" class="relative z-10 mx-auto -mt-10 max-w-content px-4 sm:px-6">
        <div class="flex flex-wrap items-center gap-2">
          <NBadge tone="brand">{{ catName }}</NBadge>
          <NStatusBadge kind="event" :status="event.status" size="md" />

          <!--
            «Клуб зрителей»: ♡ и 🔔. Обе кнопки работают только с аккаунтом —
            гостя они уводят на вход с возвратом (`onFavorite`/`onFollow`).
            «🔔 Следить» — это просьба прислать письмо, поэтому она подписана
            словами, а не значком: «колокольчик» без текста не объясняет, что
            произойдёт после нажатия.
          -->
          <div class="ml-auto flex items-center gap-2">
            <button
              type="button"
              class="flex items-center gap-1.5 rounded-lg border border-line bg-surface-2 px-3 py-2 text-sm text-muted transition-colors hover:text-content"
              :class="favoriteOn ? '!text-rose-400' : ''"
              :aria-label="favoriteOn ? 'Убрать из избранного' : 'Добавить в избранное'"
              :aria-pressed="favoriteOn"
              @click="onFavorite"
            >
              <span aria-hidden="true">{{ favoriteOn ? '♥' : '♡' }}</span>
              <span class="hidden sm:inline">{{ favoriteOn ? 'В избранном' : 'В избранное' }}</span>
            </button>

            <button
              type="button"
              class="flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm transition-colors"
              :class="
                followingOn
                  ? 'border-brand-500/60 bg-brand-500/10 text-brand-400'
                  : 'border-line bg-surface-2 text-muted hover:text-content'
              "
              :aria-pressed="followingOn"
              @click="onFollow"
            >
              <span aria-hidden="true">🔔</span>
              {{ followingOn ? 'Вы подписаны' : 'Следить' }}
            </button>
          </div>
        </div>

        <p v-if="followingOn" class="mt-2 text-xs text-subtle">
          Письмо о новых сеансах и открытии продажи придёт на {{ customerUser()?.email ?? 'ваш адрес' }}.
        </p>

        <h1 class="mt-3 max-w-3xl text-balance text-3xl font-bold leading-tight tracking-tight text-content sm:text-4xl">
          {{ event.title }}
        </h1>
        <p class="mt-2 text-base text-muted">{{ event.short_description ?? event.description ?? '' }}</p>

        <dl class="mt-5 grid gap-4 sm:grid-cols-3">
          <div class="flex items-start gap-2.5">
            <span aria-hidden="true" class="mt-0.5 text-base text-brand-400">◷</span>
            <div>
              <dt class="text-2xs uppercase tracking-wide text-subtle">Начало</dt>
              <dd v-if="activeSession" class="text-sm text-content">
                {{ dateFull(startsAtOf(activeSession)) }}, {{ time(startsAtOf(activeSession)) }}
              </dd>
            </div>
          </div>
          <div class="flex items-start gap-2.5">
            <span aria-hidden="true" class="mt-0.5 text-base text-brand-400">⌖</span>
            <div>
              <dt class="text-2xs uppercase tracking-wide text-subtle">Площадка</dt>
              <dd class="text-sm text-content">{{ event.venue?.name ?? '' }}{{ event.venue?.city ? `, ${event.venue.city}` : '' }}</dd>
            </div>
          </div>
          <div class="flex items-start gap-2.5">
            <span aria-hidden="true" class="mt-0.5 text-base text-brand-400">◈</span>
            <div>
              <dt class="text-2xs uppercase tracking-wide text-subtle">Билеты от</dt>
              <!-- Прочерк вместо «0 ₽»: 0 ₽ означает «бесплатно», а неизвестная
                   цена — «продаж сейчас нет» (цена считается по предстоящим
                   сеансам, см. EventRepository::minPriceFor). -->
              <dd class="text-sm font-semibold tabular-nums text-content">
                {{ event.price_from_minor ? money(event.price_from_minor) : '—' }}
              </dd>
            </div>
          </div>
        </dl>

        <!--
          Исполнители (стратегия SEO, B-1).

          ОБЫЧНЫЙ `<a>`, А НЕ `<RouterLink>`. Витрина — hash-SPA
          (`createWebHashHistory`), и маршрута `/artist/*` в ней нет: страница
          артиста отдаётся сервером отдельным документом
          (`LandingPageRenderer`). `<RouterLink>` попытался бы разрешить адрес
          внутри SPA и привёл бы на «не найдено»; обычная ссылка делает ровно
          то, что нужно, — полный переход на серверную страницу.

          ЗАЧЕМ ССЫЛКА ЗДЕСЬ, А НЕ ТОЛЬКО В СЕРВЕРНОМ БЛОКЕ. Google и Яндекс
          исполняют JS, поэтому на отрендеренной странице серверного блока уже
          нет — его заменяет это приложение. Без ссылки тут страница артиста
          осталась бы известна только карте сайта, то есть без перетекания веса
          со страницы события.
        -->
        <div v-if="artists.length" class="mt-5 flex flex-wrap items-center gap-2">
          <span class="text-2xs uppercase tracking-wide text-subtle">Исполнители</span>
          <a
            v-for="artist in artists"
            :key="artist.slug"
            :href="`/artist/${encodeURIComponent(artist.slug)}`"
            class="rounded-full border border-line bg-surface-2 px-3 py-1 text-sm text-content transition hover:border-brand-500 hover:text-brand-400"
          >
            {{ artist.name }}
          </a>
        </div>

      <div class="mt-8 grid gap-6 lg:grid-cols-[1fr_360px]">
        <!-- Описание -->
        <div class="min-w-0">
          <h2 class="text-lg font-semibold text-content">О событии</h2>
          <p class="mt-2 max-w-prose text-pretty text-base leading-relaxed text-muted">
                      {{ event.title }} — {{ event.short_description ?? event.description ?? '' }}. Продолжительность сеанса зависит от программы;
                      вход на площадку открывается за час до начала. Билеты с местами на схеме зала:
                      ряд и место вы выбираете сами, а не получаете «лучшее из свободных».
                    </p>

          <!--
            ── Дополнительные фото и видео ──
            Блока не существовало вообще: раздел «Дополнительные фото и видео»
            в карточке мероприятия отсутствовал, а таблица `media_links` стояла
            пустой, потому что модуль Media не отдавал ни одного маршрута.
          -->
          <section v-if="gallery.length" class="mt-8">
            <h2 class="text-lg font-semibold text-content">Фотографии и видео</h2>
            <ul class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
              <li v-for="item in gallery" :key="item.id">
                <button
                  type="button"
                  class="block w-full overflow-hidden rounded-xl border border-line bg-surface-2 transition hover:border-brand-500"
                  @click="openLightbox(item)"
                >
                  <img
                    v-if="isGalleryImage(item)"
                    :src="item.url"
                    :alt="item.alt_text ?? item.filename"
                    class="aspect-[4/3] w-full object-cover"
                    loading="lazy"
                  />
                  <span v-else class="flex aspect-[4/3] w-full items-center justify-center text-3xl" aria-hidden="true">🎬</span>
                </button>
              </li>
            </ul>
          </section>

                    <h2 class="mt-8 text-lg font-semibold text-content">Схема зала</h2>
                    <p class="mt-1 text-sm text-muted">
                      {{ activeSession ? hallOf(activeSession) : '' }}
                      <span v-if="!inventory.loading && sectors.length" class="text-subtle"> · {{ totalSeats }} мест, свободно {{ totalAvailable }}</span>
                    </p>

          <div class="surface-card mt-3 overflow-hidden">
            <div class="border-b border-line px-4 py-3">
              <p class="text-2xs uppercase tracking-wide text-subtle">Зал сеанса</p>
              <p class="mt-1 text-base font-semibold text-content">
                {{ activeSession ? hallOf(activeSession) : '—' }}
              </p>
              <p v-if="priceRange" class="mt-0.5 text-xs text-muted">
                от {{ money(priceRange.min) }}<template v-if="priceRange.max !== priceRange.min"> до {{ money(priceRange.max) }}</template>
              </p>
            </div>

            <!--
              Настоящая схема зала: каждая точка — реальное место из
              `inventory_items`, поставленное по своим координатам из схемы.
              Это не «декоративная сетка»: расположение рядов, проходов и
              секторов совпадает с интерактивной схемой выбора мест.
            -->
            <div v-if="seatMap" ref="mapBox" class="relative border-b border-line bg-surface-2/40 px-4 py-4">
              <svg
                :viewBox="seatMap.viewBox"
                :style="{ aspectRatio: String(1 / seatMap.ratio) }"
                class="mx-auto block h-auto max-h-[460px] w-full"
                preserveAspectRatio="xMidYMid meet"
                role="img"
                :aria-label="`Схема зала: всего ${totalSeats} мест, свободно ${totalAvailable}`"
              >
                <circle
                  v-for="dot in seatMap.dots"
                  :key="dot.key"
                  :cx="dot.cx"
                  :cy="dot.cy"
                  :r="dot.r"
                  :fill="dot.free ? 'rgb(var(--brand-500))' : 'rgb(var(--surface-3))'"
                  @mouseenter="showMapTip($event, dot.label)"
                  @mousemove="moveMapTip($event)"
                  @mouseleave="hideMapTip"
                />
              </svg>

              <div
                v-if="mapTip"
                class="pointer-events-none absolute z-20 -translate-x-1/2 -translate-y-[calc(100%+8px)] whitespace-nowrap rounded-lg border border-line bg-surface px-2.5 py-1.5 text-2xs font-semibold text-content shadow-lg"
                :style="{ left: `${mapTip.x}px`, top: `${mapTip.y}px` }"
                role="status"
                aria-live="polite"
              >
                {{ mapTip.label }}
              </div>

              <div class="mt-3 flex items-center justify-center gap-4 text-2xs text-subtle">
                <span class="flex items-center gap-1.5">
                  <span class="h-2 w-2 rounded-full bg-brand-500" aria-hidden="true" />
                  свободно
                </span>
                <span class="flex items-center gap-1.5">
                  <span class="h-2 w-2 rounded-full bg-surface-3" aria-hidden="true" />
                  продано
                </span>
              </div>
            </div>

            <div v-if="inventory.loading" class="px-4 py-6 text-sm text-subtle">
              Загружаем схему…
            </div>

            <div v-else-if="!sectors.length" class="px-4 py-6 text-sm text-subtle">
              Для этого сеанса пока нет опубликованной схемы зала.
            </div>

            <ul v-else class="divide-y divide-line">
              <li v-for="sector in sectors" :key="sector.name" class="flex items-center gap-3 px-4 py-3">
                <span
                  class="grid h-9 w-9 flex-none place-items-center rounded-md"
                  :class="sector.kind === 'standing' ? 'bg-accent-500/15 text-accent-400' : 'bg-brand-500/15 text-brand-400'"
                  aria-hidden="true"
                >
                  <span class="text-base font-semibold">{{ sector.kind === 'standing' ? '◳' : '⌗' }}</span>
                </span>
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium text-content">{{ sector.name }}</p>
                  <p class="text-2xs text-subtle">
                    {{ sector.kind === 'standing' ? 'Стоячая зона' : 'Места' }}
                    · всего {{ sector.total }}
                  </p>
                </div>
                <div class="flex-none text-right">
                  <p class="text-sm font-semibold tabular-nums text-content">
                    {{ sector.available > 0 ? sector.available : 0 }}
                  </p>
                  <p class="text-2xs text-subtle">свободно</p>
                </div>
                <div v-if="sector.prices.length" class="flex-none text-right">
                  <p class="text-sm tabular-nums text-muted">
                    {{ money(Math.min(...sector.prices)) }}
                  </p>
                  <p class="text-2xs text-subtle">от</p>
                </div>
              </li>
            </ul>

            <div class="border-t border-line px-4 py-3">
              <button
                type="button"
                class="w-full rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-400 disabled:cursor-not-allowed disabled:bg-surface-3 disabled:text-subtle"
                :disabled="soldOut || !activeSession"
                @click="openSeats"
              >
                {{ soldOut ? 'Билетов нет' : 'Открыть схему зала' }}
              </button>
            </div>
          </div>
        </div>

        <!-- Выбор сеанса -->
        <aside class="lg:sticky lg:top-24 lg:self-start">
          <div class="surface-card overflow-hidden">
            <div class="border-b border-line px-4 py-3">
              <h2 class="text-sm font-semibold text-content">Сеансы</h2>
              <p class="mt-0.5 text-xs text-subtle">Выберите дату и время</p>
            </div>

            <div class="space-y-2 p-3">
              <button
                              v-for="session in (event.sessions ?? [])"
                              :key="session.id"
                              type="button"
                              :disabled="availableOf(session) === 0"
                              :class="
                                cn(
                                  'w-full rounded-lg border p-3 text-left transition-all duration-120',
                                  session.id === activeSessionId
                                    ? 'border-brand-500 bg-brand-500/10'
                                    : 'border-line hover:border-brand-500/40 hover:bg-surface-2',
                                  availableOf(session) === 0 && 'cursor-not-allowed opacity-45',
                                )
                              "
                              @click="activeSessionId = session.id"
                            >
                              <div class="flex items-baseline justify-between gap-2">
                                <p class="text-sm font-medium text-content">
                                  {{ dateFull(startsAtOf(session)) }}, {{ time(startsAtOf(session)) }}
                                </p>
                                <span
                                  :class="
                                    cn(
                                      'flex-none text-xs',
                                      availableOf(session) === 0
                                        ? 'text-subtle'
                                        : availableOf(session) <= 50
                                          ? 'text-accent-400'
                                          : 'text-mint-400',
                                    )
                                  "
                                >
                                  {{ availableOf(session) === 0 ? 'нет мест' : seatsLabel(availableOf(session)) }}
                                </span>
                              </div>
                              <p class="mt-0.5 text-xs text-subtle">{{ hallOf(session) }}</p>
                            </button>
            </div>
          </div>

        </aside>
      </div>
    </div>

    <!-- Прилипающая кнопка покупки -->
    <div class="glass fixed inset-x-0 bottom-0 z-30 border-t border-line px-4 py-3 md:hidden safe-bottom">
      <button
        type="button"
        :disabled="soldOut"
        :class="
          cn(
            'w-full rounded-lg py-3.5 text-base font-semibold transition-colors',
            soldOut ? 'cursor-not-allowed bg-surface-3 text-subtle' : 'bg-accent-gradient text-white shadow-accent',
          )
        "
        @click="buy"
      >
        {{ soldOut ? 'Билетов нет' : 'Выбрать места' }}
      </button>
    </div>

    <div class="mx-auto mt-8 hidden max-w-content px-6 md:block">
      <button
        type="button"
        :disabled="soldOut"
        :class="
          cn(
            'rounded-lg px-8 py-3.5 text-base font-semibold transition-colors',
            soldOut ? 'cursor-not-allowed bg-surface-3 text-subtle' : 'bg-accent-gradient text-white shadow-accent',
          )
        "
        @click="buy"
      >
        {{ soldOut ? 'Билетов нет' : 'Выбрать места' }}
      </button>
      <p v-if="fewLeft" class="mt-2 text-xs text-accent-400">
        Осталось мало мест — лучше не откладывать
      </p>
    </div>

    <!--
      Просмотр файла во весь экран. `role="dialog"` и `aria-modal` — чтобы
      скринридер не читал страницу под окном; закрытие по клику на фон и по
      Escape обязательно: окно без выхода — ловушка.
    -->
    <div
      v-if="lightbox"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
      role="dialog"
      aria-modal="true"
      :aria-label="lightbox.alt_text ?? lightbox.filename"
      @click="closeLightbox"
    >
      <button
        type="button"
        class="absolute right-4 top-4 rounded-lg bg-white/10 px-3 py-1.5 text-sm text-white transition hover:bg-white/20"
        @click.stop="closeLightbox"
      >
        Закрыть
      </button>

      <img
        v-if="isGalleryImage(lightbox)"
        :src="lightbox.url"
        :alt="lightbox.alt_text ?? lightbox.filename"
        class="max-h-full max-w-full rounded-lg object-contain"
        @click.stop
      />
      <video
        v-else
        :src="lightbox.url"
        class="max-h-full max-w-full rounded-lg"
        controls
        @click.stop
      />
    </div>
  </div>
</template>
