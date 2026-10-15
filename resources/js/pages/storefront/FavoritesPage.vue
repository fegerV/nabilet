<script setup lang="ts">
/**
 * «Избранное» — личный список «хочу посмотреть».
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ ЭКРАН, А НЕ РАЗДЕЛ КАБИНЕТА
 *   Избранное — список для просмотра, а не для управления аккаунтом. В кабинете
 *   его место было бы пятым разделом под профилем и паролем, то есть ровно там,
 *   куда покупатель не заходит «просто посмотреть». Отдельный маршрут живёт в
 *   шапке витрины и открывается в один клик.
 *
 * ПОЧЕМУ ЗДЕСЬ НЕТ СВОЕЙ ЗАГРУЗКИ «НА ВСЯКИЙ СЛУЧАЙ»
 *   Список лежит в `stores/loyalty.ts` и грузится оболочкой витрины при любой
 *   навигации. Дубль здесь означал бы второй запрос к тем же `/me/*` — он
 *   идемпотентен, но мигает скелетоном.
 *
 * СТРОКА БЕЗ МЕРОПРИЯТИЯ ПОКАЗЫВАЕТСЯ, А НЕ ПРЯЧЕТСЯ
 *   Событие могли снять с публикации после того, как покупатель нажал «♡»:
 *   сервер тогда отдаёт строку с `event: null`. Спрятать её — значит молча
 *   уменьшить список, и покупатель решит, что «избранное сломалось». Поэтому
 *   такая строка честно подписана, и «Убрать» у неё есть.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NEmptyState from '@/components/ui/NEmptyState.vue'
import { dateFull, time } from '@/lib/format'
import { useUiStore } from '@/stores/ui'
import { useLoyaltyStore } from '@/stores/loyalty'
import type { LoyaltyEvent } from '@/lib/loyalty'

const router = useRouter()
const ui = useUiStore()
const loyalty = useLoyaltyStore()

const removing = ref<string | null>(null)

onMounted(() => {
  void loyalty.load()
})

/** Дата ближайшего сеанса или честное «уточняется» — битая дата не «Jan 1 1970». */
function formatStartsAt(iso: string | null): string {
  if (!iso) return 'Расписание уточняется'
  if (Number.isNaN(new Date(iso).getTime())) return 'Расписание уточняется'
  return `${dateFull(iso)}, ${time(iso)}`
}

/** Ссылка на витрину: без slug строить нечего (`/event/:slug`). */
function eventHref(event: LoyaltyEvent | null): string | null {
  if (!event?.slug) return null
  return `/event/${event.slug}`
}

async function remove(eventId: string): Promise<void> {
  removing.value = eventId
  try {
    await loyalty.toggleFavorite(eventId)
    ui.notify('mint', 'Убрано из избранного')
  } catch {
    ui.notify('rose', 'Не удалось убрать', 'Попробуйте ещё раз')
  } finally {
    removing.value = null
  }
}

const hasEntries = computed(() => loyalty.favorites.length > 0)
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-6 sm:px-6">
    <h1 class="font-display text-2xl font-bold tracking-tight text-content">Избранное</h1>
    <p class="mt-1.5 text-sm text-muted">
      Мероприятия, которые вы отложили «посмотреть потом». Нажмите «♡» на карточке в афише —
      и оно появится здесь.
    </p>

    <p v-if="loyalty.loading" class="mt-6 text-sm text-subtle">Загружаем избранное…</p>

    <NEmptyState
      v-else-if="!hasEntries"
      class="mt-6"
      icon="♡"
      title="В избранном пока пусто"
      description="Отмечайте «♡» мероприятия в афише — так проще выбрать, на что пойти."
      action-label="К афише"
      @action="router.push('/')"
    />

    <ul v-else class="mt-6 grid gap-3 sm:grid-cols-2">
      <li
        v-for="entry in loyalty.favorites"
        :key="entry.id"
        class="surface-card flex items-center gap-3 p-3"
      >
        <template v-if="entry.event">
          <!-- Постер и название — ссылка, когда slug есть. -->
          <RouterLink
            v-if="eventHref(entry.event)"
            :to="eventHref(entry.event)!"
            class="h-16 w-12 flex-none overflow-hidden rounded-lg"
            :aria-label="`Открыть ${entry.event.title}`"
          >
            <img
              v-if="entry.event.poster"
              :src="entry.event.poster"
              alt=""
              class="h-full w-full object-cover"
            />
            <span
              v-else
              class="grid h-full w-full place-items-center bg-brand-gradient text-lg text-brand-on"
              aria-hidden="true"
            >{{ entry.event.title.slice(0, 1) }}</span>
          </RouterLink>
          <span
            v-else
            class="grid h-16 w-12 flex-none place-items-center rounded-lg bg-surface-3 text-lg text-subtle"
            aria-hidden="true"
          >♪</span>

          <div class="min-w-0 flex-1">
            <RouterLink
              v-if="eventHref(entry.event)"
              :to="eventHref(entry.event)!"
              class="block truncate text-sm font-medium text-content transition-colors hover:text-brand-400"
            >{{ entry.event.title }}</RouterLink>
            <span v-else class="block truncate text-sm font-medium text-content">{{ entry.event.title }}</span>

            <p class="mt-0.5 text-xs text-subtle">{{ formatStartsAt(entry.event.starts_at) }}</p>
            <p v-if="entry.event.age_limit" class="mt-0.5 text-xs text-subtle">{{ entry.event.age_limit }}+</p>
          </div>

          <NButton
            variant="ghost"
            size="sm"
            :loading="removing === entry.event.id"
            @click="remove(entry.event.id)"
          >Убрать</NButton>
        </template>

        <!--
          Защитная ветка: сервер грузит `event` вместе со строкой
          (`FavoriteService::forUser`), а FK удаляет отметку вместе с
          мероприятием, так что встретить её значит увидеть расхождение
          контракта. Убрать такую строку нельзя — удаление идёт по
          `public_id` МЕРОПРИЯТИЯ, которого здесь нет; поэтому честная
          заглушка вместо молча исчезающей строки.
        -->
        <template v-else>
          <span
            class="grid h-16 w-12 flex-none place-items-center rounded-lg bg-surface-3 text-lg text-subtle"
            aria-hidden="true"
          >♪</span>
          <p class="min-w-0 flex-1 truncate text-sm text-subtle">Мероприятие снято с публикации</p>
        </template>
      </li>
    </ul>
  </div>
</template>
