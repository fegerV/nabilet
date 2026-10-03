<script setup lang="ts">
/**
 * Оболочка витрины.
 *
 * Шапка прилипает, потому что корзина и счётчик выбранных мест должны быть
 * доступны в любой точке страницы — пользователь не должен скроллить наверх,
 * чтобы понять, сколько уже выбрал. На мобильном навигация сворачивается в
 * нижнюю панель: до низа экрана дотянуться легче, чем до верха.
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useUiStore } from '@/stores/ui'
import { useCartStore } from '@/stores/cart'

const ui = useUiStore()
const cart = useCartStore()
const router = useRouter()

const search = ref('')

const NAV = [
  { label: 'Афиша', to: '/', icon: '▦' },
  { label: 'Мои билеты', to: '/tickets', icon: '◫' },
  { label: 'Организаторам', to: '/admin', icon: '◈' },
]

const cartCount = computed(() => cart.count)

function submitSearch(): void {
  if (search.value.trim()) router.push({ path: '/', query: { q: search.value.trim() } })
}
</script>

<template>
  <div class="flex min-h-dvh flex-col bg-canvas">
    <header class="glass sticky top-0 z-40 border-b border-line">
      <div class="mx-auto flex h-16 max-w-content items-center gap-3 px-4 sm:gap-5 sm:px-6">
        <!-- Логотип -->
        <RouterLink to="/" class="flex flex-none items-center gap-2.5">
          <span
            class="grid h-9 w-9 place-items-center rounded-lg bg-brand-gradient text-base font-bold text-white shadow-brand"
            aria-hidden="true"
          >Н</span>
          <span class="hidden text-lg font-bold tracking-tight text-content sm:block">NABILET</span>
        </RouterLink>

        <!-- Поиск: на витрине ищут событие, а не раздел -->
        <form class="min-w-0 flex-1" role="search" @submit.prevent="submitSearch">
          <div class="relative">
            <span aria-hidden="true" class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-subtle">⌕</span>
            <input
              v-model="search"
              type="search"
              placeholder="Событие, площадка или город"
              aria-label="Поиск событий"
              class="h-11 w-full rounded-lg border border-line bg-surface-2 pl-9 pr-3 text-sm text-content placeholder:text-subtle focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
            />
          </div>
        </form>

        <nav class="hidden items-center gap-1 md:flex">
          <RouterLink
            v-for="item in NAV"
            :key="item.to"
            :to="item.to"
            class="rounded-md px-3 py-2 text-sm text-muted transition-colors hover:bg-surface-3 hover:text-content"
            active-class="text-content bg-surface-3"
          >
            {{ item.label }}
          </RouterLink>
        </nav>

        <!-- Тема -->
        <button
          type="button"
          class="grid h-9 w-9 flex-none place-items-center rounded-lg border border-line text-sm text-muted transition-colors hover:text-content"
          :aria-label="ui.isDark ? 'Включить светлую тему' : 'Включить тёмную тему'"
          @click="ui.toggleTheme()"
        >{{ ui.isDark ? '☾' : '☀' }}</button>

        <!-- Корзина -->
        <button
          type="button"
          class="relative grid h-9 w-9 flex-none place-items-center rounded-lg bg-brand-500 text-sm text-white transition-colors hover:bg-brand-400"
          aria-label="Корзина"
          @click="router.push('/checkout')"
        >
          <span aria-hidden="true">◷</span>
          <span
            v-if="cartCount > 0"
            class="absolute -right-1 -top-1 grid h-5 min-w-5 place-items-center rounded-full bg-accent-500 px-1 text-2xs font-bold text-white"
          >{{ cartCount }}</span>
        </button>

        <button
          type="button"
          class="grid h-9 w-9 flex-none place-items-center rounded-lg border border-line text-sm md:hidden"
          aria-label="Меню"
          aria-expanded="false"
          @click="router.push('/tickets')"
        >☰</button>
      </div>
    </header>

    <main class="flex-1 pb-24 md:pb-0">
      <router-view />
    </main>

    <!-- Нижняя навигация: мобильный стандарт, а не компромисс -->
    <nav
      class="glass fixed inset-x-0 bottom-0 z-40 flex border-t border-line md:hidden"
      aria-label="Основная навигация"
    >
      <RouterLink
        v-for="item in NAV"
        :key="item.to"
        :to="item.to"
        class="flex flex-1 flex-col items-center gap-0.5 py-2 text-2xs text-subtle transition-colors safe-bottom"
        active-class="!text-brand-400"
      >
        <span aria-hidden="true" class="text-lg leading-none">{{ item.icon }}</span>
        {{ item.label }}
      </RouterLink>
    </nav>

    <footer class="hidden border-t border-line md:block">
      <div class="mx-auto flex max-w-content items-center justify-between px-6 py-6 text-sm text-subtle">
        <p>NABILET — билеты на события</p>
        <p class="flex items-center gap-4">
          <a href="#" class="transition-colors hover:text-content">Помощь</a>
          <a href="#" class="transition-colors hover:text-content">Возврат</a>
          <a href="#" class="transition-colors hover:text-content">Организаторам</a>
        </p>
      </div>
    </footer>
  </div>
</template>
