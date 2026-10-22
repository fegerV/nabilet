<script setup lang="ts">
/**
 * Карточка события — афиша и покупка на одном экране.
 *
 * МАКЕТ. Раньше страница была широкой полосой: hero, описание, галерея,
 * «схема зала» во всю ширину и кнопка «Открыть схему зала», которая уводила на
 * ОТДЕЛЬНЫЙ экран выбора мест. Покупатель грузил пять страниц, чтобы купить
 * билет. Теперь:
 *
 *   1) шапка в две колонки: слева афиша (как на касса-югре: вертикальная, не
 *      растянутая на экран), справа название, одна строка фактов, исполнители;
 *   2) ниже, ВО ВСЮ ШИРИНУ контейнера — виджет покупки (`PurchaseWidget`):
 *      схема зала, выбор мест, «Далее» → контакты и оплата БЕЗ смены страницы;
 *   3) ниже — описание и галерея.
 *
 * ПОЧЕМУ ВИДЖЕТ ВНЕ КОЛОНОК. Он стоял в правой колонке, а внутри у него была
 * ещё одна колонка со сводкой — схема зала получала треть трети ширины, точки
 * мест были мельче ногтя. Схема — главный инструмент выбора, поэтому она
 * вынесена из обеих колонок: ширина 1190 px вместо 320 на десктопе.
 *
 * Дублей информации здесь нет намеренно. Было два блока про один и тот же зал
 * («Схема зала: Театр СурГУ · Основной зал · 500 мест» и «Зал сеанса: Основной
 * зал, Театр СурГУ, от 1 900 ₽ до 5 700 ₽»). Теперь дата и площадка названы
 * один раз — в строке фактов; зал и число мест — в шапке самой схемы.
 */
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NBadge from '@/components/ui/NBadge.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import PurchaseWidget, { type WidgetSession } from '@/components/storefront/PurchaseWidget.vue'
import { get } from '@/lib/api'
import { customerUser, isCustomerSession } from '@/lib/customer'
import { trackEvent } from '@/lib/metrika'
import { useSeo } from '@/lib/seo'
import { money, dateFull, time } from '@/lib/format'
import { useLoyaltyStore } from '@/stores/loyalty'
import { useUiStore } from '@/stores/ui'
import type { EventStatus } from '@/lib/types'

const route = useRoute()
const router = useRouter()
const loyalty = useLoyaltyStore()
const ui = useUiStore()

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

/* Первый сеанс — активный по умолчанию, а если в адресе есть `?session=`
 * (старые ссылки с отдельного экрана выбора мест), то именно он. */
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

/**
 * Сеанс из ссылки побеждает первый по списку.
 *
 * Маршрут `/event/{slug}/seats?session=23` перебрасывает на эту страницу, и
 * сеанс из адреса должен открыться сразу: покупатель уже выбрал дату, и
 * заставлять его выбирать её заново — это ровно тот «квест», за который ему
 * стыдно. Значение принимается только если такой сеанс у события есть:
 * подставить чужой id значило бы показать схему другого концерта.
 */
const requestedSessionId = computed(() => {
  const fromQuery = String(route.query.session ?? '')
  const sessions = event.value?.sessions ?? []
  return sessions.some((s) => String(s.id) === fromQuery) ? fromQuery : ''
})

watch([event, requestedSessionId], ([, requested]) => {
  if (requested !== '' && activeSessionId.value !== requested) activeSessionId.value = requested
})

/**
 * Достаточно ли крупная афиша, чтобы кадрировать её по вертикали.
 *
 * Часть афиш Касса-Югры отдаёт в 216×306 — в вертикальной колонке 320×480
 * такая картинка растягивается и превращается в мыло. Для мелких исходников
 * афиша показывается целиком (`object-contain`) на градиенте, для крупных —
 * кадрируется сверху (`object-cover object-top`), чтобы не обрезать название.
 * Порог 600px по ширине: 710×1000 и 904×1280 выглядят хорошо, 216×306 — нет.
 * Флаг ставится по `naturalWidth` после загрузки, поэтому решение принимается
 * по реальному файлу, а не по имени события.
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

/**
 * Сеансы в форме, которую принимает виджет покупки.
 *
 * Копия, а не ссылка: виджет сортирует и хранит свой активный сеанс, и общий
 * массив означал бы, что правка в одном месте меняет состояние в другом.
 */
const widgetSessions = computed<WidgetSession[]>(() =>
  (event.value?.sessions ?? []).map((s) => ({ ...s })),
)

</script>
<template>
  <div class="pb-16 md:pb-10">
    <div v-if="loading" class="mx-auto max-w-content px-4 py-8 text-sm text-subtle">
      Загрузка события…
    </div>

    <div v-else-if="loadError" class="mx-auto max-w-content px-4 py-8 text-sm text-danger-500">
      Не удалось загрузить событие: {{ loadError }}
    </div>

    <!--
      ── Шапка в две колонки: афиша слева, название и факты справа ──

      Макет взят с касса-Югры (`surgut.kassa-ugra.ru/events/…`): слева
      вертикальная афиша, справа название. Раньше страница была широкой полосой
      с hero во весь экран, и покупатель после неё уходил на отдельный экран
      выбора мест — пять загрузок на один билет.

      Покупки здесь больше нет: она вынесена во всю ширину ниже. Пока виджет
      стоял в правой колонке, схема зала получала треть её ширины.

      `items-start` на гриде, а не `stretch`: левая колонка на десктопе
      прилипает (`lg:sticky lg:top-24`) и не растягивается под правую колонку.
    -->
    <div v-else-if="event" class="mx-auto max-w-content px-4 py-6 sm:px-6">
      <div class="grid items-start gap-6 lg:grid-cols-[minmax(220px,300px)_1fr] lg:gap-8">
        <!-- ── Афиша ────────────────────────────────────────────────────────
             АФИША — ТЕГОМ <img>, А НЕ CSS background-image (SEO-аудит P-19):
             Google Images индексирует <img> с атрибутом alt, а фоновое
             изображение для него — оформление страницы. Alt берётся тот же,
             что и в `og:image:alt` серверной обёртки: одна формулировка в
             превью соцсети и на странице, а не две разные.

             Колонка фиксированной ширины с соотношением 2:3 (шаблон билета
             400×600 — тот же): афиша 216×306 в блоке 300×450 растягивается в
             полтора раза и остаётся читаемой, а не в шесть, как в hero.
        -->
        <div class="lg:sticky lg:top-24">
          <div class="relative aspect-[2/3] overflow-hidden rounded-2xl border border-line shadow-card">
            <div
              class="absolute inset-0"
              :style="{ background: 'linear-gradient(145deg, #7C3AED 0%, #FF5C22 100%)' }"
              aria-hidden="true"
            />
            <img
              v-if="event.poster"
              :src="event.poster"
              :alt="posterAlt"
              class="absolute inset-0 h-full w-full"
              :class="posterIsLarge ? 'object-cover object-top' : 'object-contain'"
              decoding="async"
              @load="onPosterLoad"
            >
          </div>
        </div>

        <!-- ── Правая колонка: название, одна строка фактов, покупка ──────── -->
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <NBadge tone="brand">{{ catName }}</NBadge>
            <NStatusBadge kind="event" :status="event.status" size="md" />

            <!--
              «Клуб зрителей»: ♡ и 🔔. Обе кнопки работают только с аккаунтом —
              гостя они уводят на вход с возвратом (`onFavorite`/`onFollow`).
              «🔔 Следить» подписана словами, а не значком: «колокольчик» без
              текста не объясняет, что произойдёт после нажатия.
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

          <h1 class="mt-3 text-balance text-3xl font-bold leading-tight tracking-tight text-content sm:text-4xl">
            {{ event.title }}
          </h1>
          <p v-if="event.short_description ?? event.description" class="mt-2 max-w-prose text-base text-muted">
            {{ event.short_description ?? event.description }}
          </p>

          <!--
            ОДНА строка фактов вместо трёх блоков.

            Было: «Начало / Площадка / Билеты от» отдельным гридом, ПЛЮС блок
            «Схема зала: Театр СурГУ · Основной зал · 500 мест, свободно 495»,
            ПЛЮС блок «Зал сеанса: Основной зал, Театр СурГУ, Сургут, от 1 900 ₽
            до 5 700 ₽» — три места про один и тот же зал. Площадка и зал
            называются один раз: в подписи над схемой внутри виджета. Здесь
            остаётся то, что человек ищет глазами до схемы: когда, где и
            сколько стоит.
          -->
          <p class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted">
            <span v-if="activeSession" class="flex items-center gap-1.5">
              <span class="text-brand-400" aria-hidden="true">◷</span>
              {{ dateFull(startsAtOf(activeSession)) }}, {{ time(startsAtOf(activeSession)) }}
            </span>
            <span v-if="event.venue?.name" class="flex items-center gap-1.5">
              <span class="text-brand-400" aria-hidden="true">⌖</span>
              {{ event.venue.name }}<template v-if="event.venue.city">, {{ event.venue.city }}</template>
            </span>
            <span v-if="event.price_from_minor" class="flex items-center gap-1.5">
              <span class="text-brand-400" aria-hidden="true">◈</span>
              <span class="font-semibold tabular-nums text-content">{{ money(event.price_from_minor) }}</span>
            </span>
            <span v-if="event.age_limit" class="flex items-center gap-1.5">
              <span class="text-brand-400" aria-hidden="true">◍</span>
              {{ event.age_limit }}
            </span>
          </p>

          <!--
            Исполнители (стратегия SEO, B-1).

            ОБЫЧНЫЙ `<a>`, А НЕ `<RouterLink>`: маршрута `/artist/*` в
            hash-SPA нет, страница артиста отдаётся сервером отдельным
            документом (`LandingPageRenderer`). Ссылка на неё с отрендеренной
            JS страницы — единственный путь передачи веса со страницы события.
          -->
          <div v-if="artists.length" class="mt-3 flex flex-wrap items-center gap-2">
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
        </div>
      </div>

      <!--
        ── Покупка во всю ширину страницы ──

        Виджет стоял в правой колонке карточки, а внутри у него была ещё одна
        колонка со сводкой — и схема зала получала треть трети: точки мест
        мельче ногтя. Схема — главный инструмент выбора, поэтому она выведена
        из обеих колонок и занимает всю ширину контейнера; название, дата и
        площадка остаются в шапке события выше.

        Блок идёт ПОСЛЕ грида, а не внутри правой колонки: иначе ширина схемы
        снова упиралась бы в ширину колонки.
      -->
      <div class="mt-8">
        <PurchaseWidget v-model:session-id="activeSessionId" :sessions="widgetSessions" />
      </div>

      <!--
        Описание — ПОСЛЕ покупки, хотя в шапке рядом с афишей остаётся пустая
        полоса. Это осознанный выбор: текст события занимает ~380 px, и в
        правой колонке он опускал схему зала с 620 до 830 px — на экране
        1000 px покупатель вообще не видел мест, ради которых пришёл. Пустая
        полоса рядом с афишей дешевле, чем схема за первым экраном.
      -->
      <section class="mt-10 max-w-prose">
        <h2 class="text-lg font-semibold text-content">О событии</h2>
        <p class="mt-2 text-pretty text-base leading-relaxed text-muted">
          {{ event.description ?? event.short_description ?? '' }}
        </p>
        <p v-if="event.duration_minutes" class="mt-2 text-sm text-subtle">
          Продолжительность — {{ event.duration_minutes }} мин. Вход на площадку открывается за час до начала.
        </p>
      </section>

      <!--
        Дополнительные фото и видео. Блока не существовало вообще: раздел
        «Дополнительные фото и видео» в карточке мероприятия отсутствовал,
        а таблица `media_links` стояла пустой, потому что модуль Media не
        отдавал ни одного маршрута.
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
