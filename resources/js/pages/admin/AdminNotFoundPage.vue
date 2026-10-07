<script setup lang="ts">
/**
 * Неизвестный раздел админки — честный 404 внутри SPA.
 *
 * Раньше здесь была заглушка «раздел ещё не реализован». Она стала вредной:
 * когда появились реальные экраны для всех пунктов меню, единственный способ
 * попасть на этот компонент — опечатка в адресе. Тогда сообщение «спроектирован,
 * но не реализован» прямо врало: раздела с таким именем не существует вовсе, и
 * администратор шёл искать его в дорожной карте.
 *
 * Поэтому экран говорит то, что есть: адреса нет. Подсказки ведут в реальные
 * разделы, а не в «следующий срез разработки».
 *
 * Маршрут по-прежнему `:section` (catch-all), а не отдельный 404: SPA на
 * hash-истории, и любой неизвестный путь в админке должен отрисовываться
 * оболочкой с меню, а не пустым экраном роутера.
 */
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'

const route = useRoute()
const router = useRouter()

/** Что именно человек ввёл — показываем как есть, чтобы опечатка была видна. */
const requested = computed(() => String(route.params.section ?? ''))

/** Разделы админки для подсказки. Список совпадает с `AdminShell` GROUPS. */
const SECTIONS: ReadonlyArray<{ path: string; label: string }> = [
  { path: '/admin/orders', label: 'Заказы' },
  { path: '/admin/tickets', label: 'Билеты' },
  { path: '/admin/payments', label: 'Платежи' },
  { path: '/admin/events', label: 'Мероприятия' },
  { path: '/admin/sessions', label: 'Сеансы' },
  { path: '/admin/venues', label: 'Площадки' },
  { path: '/admin/halls', label: 'Схемы залов' },
  { path: '/admin/storefront', label: 'Конструктор витрины' },
  { path: '/admin/users', label: 'Пользователи' },
  { path: '/admin/integrations', label: 'Интеграции' },
  { path: '/admin/webhooks', label: 'Вебхуки' },
  { path: '/admin/notification-templates', label: 'Шаблоны писем' },
  { path: '/admin/mail', label: 'Почта (SMTP)' },
  { path: '/admin/analytics', label: 'Аналитика' },
]

/**
 * Ближайший существующий раздел по опечатке.
 *
 * Цель — одна полезная догадка для почти правильно набранного адреса
 * (`/admin/oders` → «Заказы»). Сравнение по подстроке здесь НЕ работает:
 * `oders` и `orders` не содержат друг друга — буквы переставлены, а это самая
 * частая опечатка при быстром наборе. Поэтому считается редакционное расстояние
 * (Левенштейн), и догадка принимается только при дистанции ≤ 2 и близкой длине.
 *
 * Порог намеренно строгий: выдуманная ссылка хуже её отсутствия — она уводит в
 * раздел, который человек не искал, и он решает, что нажал не туда.
 */
const MAX_DISTANCE = 2
const MIN_SIMILARITY = 0.6

function levenshtein(a: string, b: string): number {
  // Классическая динамика по строкам; длины тут ≤ 24, оптимизация не нужна,
  // а читаемость важнее — алгоритм не должен выглядеть как трюк.
  const rows = b.length + 1
  const cols = a.length + 1
  let prev = Array.from({ length: cols }, (_, i) => i)

  for (let r = 1; r < rows; r += 1) {
    const curr = [r]
    for (let c = 1; c < cols; c += 1) {
      const cost = b[r - 1] === a[c - 1] ? 0 : 1
      curr[c] = Math.min(prev[c] + 1, curr[c - 1] + 1, prev[c - 1] + cost)
    }
    prev = curr
  }

  return prev[cols - 1]
}

const suggestion = computed(() => {
  const needle = requested.value.toLowerCase()
  if (needle === '') return null

  let best: { path: string; label: string } | null = null
  let bestDistance = Number.POSITIVE_INFINITY

  for (const section of SECTIONS) {
    const slug = section.path.slice('/admin/'.length).toLowerCase()
    const distance = levenshtein(needle, slug)
    const similarity = 1 - distance / Math.max(needle.length, slug.length)

    if (distance <= MAX_DISTANCE && similarity >= MIN_SIMILARITY && distance < bestDistance) {
      best = section
      bestDistance = distance
    }
  }

  return best
})
</script>

<template>
  <div class="mx-auto max-w-2xl">
    <h1 class="text-2xl font-bold tracking-tight text-content">Раздел не найден</h1>
    <div class="surface-card mt-5 p-6">
      <p class="text-sm text-muted">
        Адрес <code class="rounded bg-surface-3 px-1.5 py-0.5 font-mono text-xs text-content">/admin/{{ requested }}</code>
        не соответствует ни одному разделу админки. Возможно, в ссылке опечатка или она устарела.
      </p>

      <template v-if="suggestion">
        <p class="mt-4 text-sm text-muted">Возможно, вы имели в виду:</p>
        <div class="mt-2">
          <NButton variant="primary" @click="router.push(suggestion.path)">
            {{ suggestion.label }}
          </NButton>
        </div>
      </template>

      <p v-else class="mt-4 text-sm text-muted">
        Выберите раздел в меню слева или вернитесь на обзор.
      </p>

      <div class="mt-5 flex gap-2">
        <NButton variant="secondary" @click="router.push('/admin')">На обзор</NButton>
        <NButton variant="secondary" @click="router.push('/admin/orders')">К заказам</NButton>
      </div>
    </div>
  </div>
</template>
