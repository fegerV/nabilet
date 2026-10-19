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

/* Постер из API (если есть) или дефолтный градиент. */
const posterStyle = computed(() => {
  const poster = props.event.poster
  if (poster) {
    return { backgroundImage: `url(${poster})` }
  }
  // Запасной градиент — из бренд-кистей площадки, а не из зашитого фиолета:
  // иначе витрина с зелёным брендом показывает фиолетовые обложки.
  return {
    background: `linear-gradient(145deg, ${props.event.posterFrom ?? 'rgb(var(--brand-500))'} 0%, ${props.event.posterTo ?? 'rgb(var(--accent-500))'} 100%)`,
  }
})

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
    <!-- Постер -->
    <div class="relative aspect-[16/10] overflow-hidden" :style="posterStyle">
      <span
        class="pointer-events-none absolute -right-6 -top-8 h-32 w-32 rounded-full opacity-40 blur-2xl"
        :style="{ background: event.posterAccent }"
        aria-hidden="true"
      />

      <div class="absolute inset-x-0 top-0 flex items-start justify-between p-3">
              <NBadge tone="brand" class="backdrop-blur">{{ categoryName }}</NBadge>
              <div class="flex flex-none items-center gap-2">
                <!--
                  Сердце лежит ПОВЕРХ ссылки-оверлея карточки (`relative z-10`):
                  без z-index клик уходил бы в RouterLink и открывал страницу
                  события вместо того, чтобы ставить отметку.
                -->
                <button
                  v-if="event.public_id"
                  type="button"
                  class="relative z-10 grid h-8 w-8 place-items-center rounded-lg bg-overlay/60 text-base backdrop-blur transition-colors"
                  :class="favoriteOn ? 'text-rose-400' : 'text-white/80 hover:text-white'"
                  :aria-label="favoriteOn ? 'Убрать из избранного' : 'Добавить в избранное'"
                  :aria-pressed="favoriteOn"
                  @click="onFavorite"
                >
                  <span aria-hidden="true">{{ favoriteOn ? '♥' : '♡' }}</span>
                </button>
                <NStatusBadge kind="event" :status="event.status" />
              </div>
            </div>

      <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-overlay/85 to-transparent p-3 pt-10">
              <h3 class="text-balance text-base font-semibold leading-snug text-white">{{ event.title }}</h3>
              <p class="mt-0.5 truncate text-xs text-white/70">{{ event.subtitle ?? event.short_description ?? '' }}</p>
            </div>
    </div>

    <!-- Метаданные -->
    <div class="flex flex-1 flex-col gap-2.5 p-3.5">
      <div class="flex items-center gap-2 text-xs text-muted">
        <span aria-hidden="true">◷</span>
        <span v-if="nextSession">{{ dateFull(sessionStartsAt) }} · {{ time(sessionStartsAt) }}</span>
        <span v-else>Расписание уточняется</span>
      </div>

      <div class="flex items-center gap-2 text-xs text-muted">
              <span aria-hidden="true">⌖</span>
              <span class="truncate">{{ venueName }}{{ venueCity ? `, ${venueCity}` : '' }}</span>
            </div>

            <div class="mt-auto flex items-end justify-between gap-3 border-t border-line pt-3">
              <div>
                <p class="text-2xs uppercase tracking-wide text-subtle">от</p>
                <!--
                  «от 0 ₽» было нормой, пока публичный список событий не отдавал
                  цену: поле в EventResource отсутствовало, и `?? 0` молча
                  подставляло ноль на КАЖДУЮ карточку афиши (проверено на живом
                  стенде). Теперь цена приходит как `price_from_minor`, а её
                  отсутствие — это «продаж нет»: рисуем прочерк, а не нуль.
                -->
                <p
                  v-if="priceFromMinor !== null"
                  class="text-base font-semibold tabular-nums text-content"
                >
                  {{ money(priceFromMinor) }}
                </p>
                <p v-else class="text-base font-semibold text-subtle">—</p>
              </div>

        <span
          class="rounded-lg px-3 py-2 text-sm font-medium transition-colors"
          :class="
            soldOut
              ? 'cursor-not-allowed bg-surface-3 text-subtle'
              : 'bg-brand-500 text-white group-hover:bg-brand-400'
          "
        >
          {{ soldOut ? 'Нет мест' : 'Выбрать' }}
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
