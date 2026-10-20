<script setup lang="ts">
/**
 * Карточка события.
 *
 * Постер организован так, чтобы карточка читалась даже без изображения:
 * градиент, крупный заголовок, цена и статус. Когда организатор загрузит
 * постер, он ложится под тот же текст — вёрстка от этого не ломается.
 */
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NBadge from '@/components/ui/NBadge.vue'
import NStatusBadge from '@/components/ui/NStatusBadge.vue'
import { money, dateFull, time } from '@/lib/format'
import { isCustomerSession } from '@/lib/customer'
import { useLoyaltyStore } from '@/stores/loyalty'
import type { EventCard as EventCardType } from '@/lib/types'

const props = defineProps<{ event: EventCardType }>()
const router = useRouter()
const route = useRoute()
const loyalty = useLoyaltyStore()

const nextSession = computed(() => props.event.sessions?.[0])

/**
 * Минимальная цена билета в копейках или `null`, если цена неизвестна.
 *
 * Ровно `null`, а не 0: нуль на витрине означает «бесплатно», и покупатель
 * имеет право прийти на бесплатный концерт. Неизвестная цена — другой случай,
 * и путать их нельзя.
 */
const priceFromMinor = computed<number | null>(() => {
  const raw = props.event.price_from_minor ?? props.event.priceFromMinor
  return typeof raw === 'number' && raw >= 0 ? raw : null
})

/**
 * «Нет мест» — по реальному признаку, а не по `status`.
 *
 * Здесь стояло `props.event.status === 'sold_out'`, и сравнение было МЁРТВЫМ:
 * `events.status` такого значения не знает — `sold_out` это статус сеанса и
 * состояние позиции склада. Пока фронтенд-тип `EventStatus` ошибочно содержал
 * `sold_out`, TypeScript молчал; когда словарь привели к бэкенду, `vue-tsc`
 * показал, что ветка недостижима (`TS2367`).
 *
 * Настоящий признак отдаёт `EventResource`: у ближайшего сеанса нет ни одной
 * позиции со `status = 'available'` и положительным остатком. Позиции, ушедшие
 * в корзины, имеют `sold_out`/`held` и в этот счёт не попадают — то есть
 * «Нет мест» показывается ровно тогда, когда купить действительно нечего.
 */
const soldOut = computed(() => {
  const session = nextSession.value
  if (!session) {
    return false
  }

  const seats = session.availableSeats ?? session.available_seats

  return seats === 0
})

/** Нормализация для шаблона: API отдаёт category/venue то строкой, то объектом. */
const categoryName = computed(() =>
  typeof props.event.category === 'string' ? props.event.category : (props.event.category?.name ?? '')
)
const venueName = computed(() =>
  typeof props.event.venue === 'string' ? props.event.venue : (props.event.venue?.name ?? props.event.city ?? '')
)
const venueCity = computed(() =>
  typeof props.event.venue === 'string' ? '' : (props.event.venue?.city ?? '')
)
/** Даты сеанса: API может прислать camelCase или snake_case — берём что есть. */
const sessionStartsAt = computed(
  () => nextSession.value?.startsAt ?? nextSession.value?.starts_at ?? ''
)

/* ── «♡ Избранное» ─────────────────────────────────────────────────────────
 * Адресуем мероприятие `public_id`: внутри `EventResource` есть и внутренний
 * `id`, но `/me/events/{event}/favorite` резолвит событие только по `public_id`
 * (`LoyaltyController::resolveEvent`). Если постер пришёл без него (старая
 * выдача API), сердце не показываем — лучше отсутствующая кнопка, чем 404.
 */
const favoriteOn = computed(() => {
  const eventId = props.event.public_id ?? ''
  return eventId !== '' && loyalty.isFavorite(eventId)
})

async function onFavorite(): Promise<void> {
  const eventId = props.event.public_id ?? ''
  if (eventId === '') return

  // Гостю нечего хранить: `/me/*` ответит 401. Отправляем войти и возвращаем
  // на то же место — иначе «♡» на афише вела бы в кабинет, теряя страницу.
  if (!isCustomerSession()) {
    router.push({ path: '/login', query: { redirect: route.fullPath } })
    return
  }

  try {
    await loyalty.toggleFavorite(eventId)
  } catch {
    /* вид сердца уже не изменился — состояние двигает только ответ сервера */
  }
}
</script>

<template>
  <article
    class="group relative flex flex-col overflow-hidden rounded-xl border border-line bg-surface transition-all duration-200 ease-out hover:-translate-y-0.5 hover:border-brand-500/40 hover:shadow-lg"
  >
    <!--
      Постер занимает всю ширину и большую часть высоты карточки.
      Афиша идёт вертикальной стопкой, текст — под ней: так карточка читается
      как фотография, а не как «описание события с фоном». Это ориентир
      дизайна kassa-ugra: витрина = афиша + короткая подпись, а не карточка
      с тремя строками наложенного текста.
    -->
    <div class="relative aspect-[2/3] w-full overflow-hidden bg-surface-2">
      <div
        v-if="!event.poster"
        class="absolute inset-0"
        :style="{ background: `linear-gradient(145deg, ${event.posterFrom ?? 'rgb(var(--brand-500))'} 0%, ${event.posterTo ?? 'rgb(var(--accent-500))'} 100%)` }"
        aria-hidden="true"
      />
      <img
        v-else
        :src="event.poster"
        :alt="event.title ? `${event.title} — афиша` : 'Афиша'"
        class="absolute inset-0 h-full w-full object-cover"
        loading="lazy"
        decoding="async"
      >

      <!--
        Лёгкая подложка снизу для контраста — нужна, чтобы на светлых
        постерах (Руки Вверх, Пельмени) читалась дата и «от X ₽», но без
        крупного заголовка поверх фотографии.
      -->
      <div class="pointer-events-none absolute inset-x-0 bottom-0 h-20 bg-gradient-to-t from-black/70 via-black/25 to-transparent" aria-hidden="true" />

      <div class="absolute inset-x-0 top-0 flex items-start justify-between gap-2 p-3">
        <NBadge tone="brand" class="backdrop-blur">{{ categoryName }}</NBadge>
        <div class="flex flex-none items-center gap-2">
          <button
            v-if="event.public_id"
            type="button"
            class="relative z-10 grid h-8 w-8 place-items-center rounded-lg bg-black/40 text-base text-white/90 backdrop-blur transition-colors hover:bg-black/60"
            :class="favoriteOn ? 'text-rose-400' : ''"
            :aria-label="favoriteOn ? 'Убрать из избранного' : 'Добавить в избранное'"
            :aria-pressed="favoriteOn"
            @click="onFavorite"
          >
            <span aria-hidden="true">{{ favoriteOn ? '♥' : '♡' }}</span>
          </button>
          <NStatusBadge kind="event" :status="event.status" />
        </div>
      </div>

      <div v-if="nextSession" class="absolute inset-x-0 bottom-0 p-3 text-white">
        <p class="text-2xs font-medium uppercase tracking-wide text-white/80">
          {{ dateFull(sessionStartsAt) }} · {{ time(sessionStartsAt) }}
        </p>
        <p v-if="priceFromMinor !== null" class="mt-0.5 text-base font-semibold tabular-nums">
          от {{ money(priceFromMinor) }}
        </p>
      </div>
    </div>

    <!-- Подпись: название + площадка. Не дублируем дату и цену — они уже на постере. -->
    <div class="flex flex-col gap-1 p-3.5">
      <h3 class="text-balance text-sm font-semibold leading-snug text-content line-clamp-2">
        {{ event.title }}
      </h3>
      <p class="truncate text-xs text-muted">
        {{ venueName }}{{ venueCity ? `, ${venueCity}` : '' }}
      </p>

      <div class="mt-2 flex items-center justify-between gap-2">
        <span
          v-if="soldOut"
          class="rounded-md bg-surface-3 px-2.5 py-1 text-2xs font-medium uppercase tracking-wide text-subtle"
        >
          Нет мест
        </span>
        <span v-else class="text-2xs uppercase tracking-wide text-mint-400">
          В продаже
        </span>
        <span
          class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
          :class="soldOut ? 'bg-surface-3 text-subtle' : 'bg-brand-500 text-white group-hover:bg-brand-400'"
        >
          {{ soldOut ? 'Подробнее' : 'Выбрать' }}
        </span>
      </div>
    </div>

    <!-- Вся карточка — ссылка: кликабельная область не должна быть только текстом -->
    <RouterLink
      :to="`/event/${event.slug || event.id}`"
      class="absolute inset-0 rounded-xl"
      :aria-label="`Открыть событие ${event.title}`"
    />
  </article>
</template>
