<script setup lang="ts">
/**
 * «Подписки» — мероприятия, о новых сеансах которых покупатель просил письмо.
 *
 * ЧЕМ ЭТО ОТЛИЧАЕТСЯ ОТ «ИЗБРАННОГО»
 *   Избранное — память («хочу посмотреть»), подписка — действие («напишите,
 *   когда появится»). Поэтому на странице подписок объясняется, КОГДА придёт
 *   письмо: при появлении сеанса и при открытии продаж
 *   (`SessionObserver` → `NotifyFollowersOfNewSessionJob`). Одно письмо на
 *   событие, без ежедневных рассылок — это обещание, и оно здесь сказано
 *   вслух, иначе «Следить» воспринимается как «будут присылать рекламу».
 *
 * СТРОКА БЕЗ МЕРОПРИЯТИЯ
 *   Как и в «Избранном», показывается, а не прячется: см. комментарий в
 *   `FavoritesPage.vue`.
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

const unsubscribing = ref<string | null>(null)

onMounted(() => {
  void loyalty.load()
})

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

async function unsubscribe(eventId: string): Promise<void> {
  unsubscribing.value = eventId
  try {
    await loyalty.toggleFollow(eventId)
    ui.notify('mint', 'Подписка отменена')
  } catch {
    ui.notify('rose', 'Не удалось отписаться', 'Попробуйте ещё раз')
  } finally {
    unsubscribing.value = null
  }
}

const hasEntries = computed(() => loyalty.follows.length > 0)
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-6 sm:px-6">
    <h1 class="font-display text-2xl font-bold tracking-tight text-content">Подписки</h1>
    <p class="mt-1.5 text-sm text-muted">
      Письмо придёт, когда у мероприятия появится новый сеанс или откроются продажи.
      Одно письмо на событие — без ежедневных рассылок.
    </p>

    <p v-if="loyalty.loading" class="mt-6 text-sm text-subtle">Загружаем подписки…</p>

    <NEmptyState
      v-else-if="!hasEntries"
      class="mt-6"
      icon="🔔"
      title="Подписок пока нет"
      description="Нажмите «Следить» на странице мероприятия — и мы напишем, когда появятся новые сеансы."
      action-label="К афише"
      @action="router.push('/')"
    />

    <ul v-else class="mt-6 grid gap-3 sm:grid-cols-2">
      <li
        v-for="entry in loyalty.follows"
        :key="entry.id"
        class="surface-card flex items-center gap-3 p-3"
      >
        <template v-if="entry.event">
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
          </div>

          <NButton
            variant="ghost"
            size="sm"
            :loading="unsubscribing === entry.event.id"
            @click="unsubscribe(entry.event.id)"
          >Отписаться</NButton>
        </template>

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
