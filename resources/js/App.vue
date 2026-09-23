<script setup lang="ts">
/**
 * Корень приложения: маршруты, уведомления и командная палитра.
 *
 * Палитра на Ctrl+K — не украшение. В админке организатора разделов много, и
 * поиск по ним привычнее, чем обход меню: администратор помнит, куда хочет
 * попасть, но не помнит, в какой группе это лежит.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import NToastHost from '@/components/ui/NToastHost.vue'
import { useUiStore } from '@/stores/ui'
import { cn } from '@/lib/cn'

const ui = useUiStore()
const router = useRouter()

const query = ref('')
const activeIndex = ref(0)

const COMMANDS = [
  { label: 'Обзор', hint: 'Продажи и задачи', to: '/admin', icon: '◧' },
  { label: 'Заказы', hint: 'Все заказы и возвраты', to: '/admin/orders', icon: '◫' },
  { label: 'Мероприятия', hint: 'Афиша и статусы', to: '/admin/events', icon: '▤' },
  { label: 'Схемы залов', hint: 'Редактор рассадки', to: '/admin/halls', icon: '▦' },
  { label: 'Афиша', hint: 'Публичная витрина', to: '/', icon: '▧' },
  { label: 'Мои билеты', hint: 'QR и архив', to: '/tickets', icon: '◨' },
]

const results = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return COMMANDS
  return COMMANDS.filter((c) => c.label.toLowerCase().includes(q) || c.hint.toLowerCase().includes(q))
})

function go(to: string): void {
  ui.commandOpen = false
  query.value = ''
  router.push(to)
}

function onKeydown(event: KeyboardEvent): void {
  const isCmdK = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k'
  if (isCmdK) {
    event.preventDefault()
    ui.commandOpen = !ui.commandOpen
    return
  }
  if (!ui.commandOpen) return
  if (event.key === 'Escape') {
    ui.commandOpen = false
  } else if (event.key === 'ArrowDown') {
    event.preventDefault()
    activeIndex.value = Math.min(results.value.length - 1, activeIndex.value + 1)
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    activeIndex.value = Math.max(0, activeIndex.value - 1)
  } else if (event.key === 'Enter') {
    event.preventDefault()
    const target = results.value[activeIndex.value]
    if (target) go(target.to)
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <RouterView v-slot="{ Component }">
    <Transition
      mode="out-in"
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0"
      leave-active-class="transition duration-120 ease-in"
      leave-to-class="opacity-0"
    >
      <component :is="Component" />
    </Transition>
  </RouterView>

  <NToastHost />

  <!-- Командная палитра -->
  <Teleport to="body">
    <div v-if="ui.commandOpen" class="fixed inset-0 z-palette flex items-start justify-center p-4 pt-[12vh]">
      <div class="absolute inset-0 bg-overlay/70 animate-fade-in" @click="ui.commandOpen = false" />
      <div
        class="relative w-full max-w-lg animate-scale-in overflow-hidden rounded-xl border border-line bg-surface shadow-xl"
        role="dialog"
        aria-modal="true"
        aria-label="Командная палитра"
      >
        <div class="flex items-center gap-2.5 border-b border-line px-4 py-3">
          <span aria-hidden="true" class="text-sm text-subtle">⌕</span>
          <input
            ref="input"
            v-model="query"
            placeholder="Куда перейти?"
            aria-label="Поиск раздела"
            class="flex-1 bg-transparent text-base text-content placeholder:text-subtle focus:outline-none"
          />
          <kbd class="rounded border border-line px-1.5 py-0.5 font-mono text-2xs text-subtle">Esc</kbd>
        </div>

        <ul class="max-h-72 overflow-y-auto p-1.5">
          <li v-if="results.length === 0" class="px-3 py-6 text-center text-sm text-subtle">Ничего не найдено</li>
          <li v-for="(command, index) in results" :key="command.to">
            <button
              type="button"
              :class="
                cn(
                  'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors',
                  index === activeIndex ? 'bg-brand-500/12' : 'hover:bg-surface-2',
                )
              "
              @click="go(command.to)"
              @mouseenter="activeIndex = index"
            >
              <span aria-hidden="true" class="grid h-7 w-7 flex-none place-items-center rounded-md bg-surface-3 text-sm">
                {{ command.icon }}
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm text-content">{{ command.label }}</span>
                <span class="block truncate text-xs text-subtle">{{ command.hint }}</span>
              </span>
              <span aria-hidden="true" class="flex-none text-xs text-subtle">↵</span>
            </button>
          </li>
        </ul>
      </div>
    </div>
  </Teleport>
</template>
