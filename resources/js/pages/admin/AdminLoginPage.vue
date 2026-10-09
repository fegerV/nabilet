<script setup lang="ts">
/**
 * Вход в админку NABILET.
 *
 * Отдельная страница вне AdminShell: логиниться можно, ещё не имея роли.
 * После успешного входа с ролью admin/manager — редирект в админку.
 * Без прав (support/клиент) — показываем отказ.
 *
 * Экран — первая встреча с продуктом, поэтому слева бренд-панель с ценностью,
 * а не пустой фон. На узких экранах панель уходит, остаётся компактная форма.
 */
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import { login, saveCurrentUser, canAdmin, loginErrorMessage } from '@/lib/auth'

const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const loading = ref(false)
const error = ref<string | null>(null)
const showPassword = ref(false)

const PERKS = [
  'Конструктор схем залов — рассадка без кода',
  'Онлайн-оплата и вход по QR за минуты',
  'Брендированная витрина для каждого события',
]

async function submit(): Promise<void> {
  if (loading.value) return
  error.value = null
  loading.value = true
  try {
    const data = await login(email.value.trim(), password.value)
    saveCurrentUser(data.user)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/admin'
    if (canAdmin(data.user.roles)) {
      router.push(redirect)
    } else {
      error.value = 'У вашей учётной записи нет прав администратора'
    }
  } catch (e) {
    error.value = loginErrorMessage(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-dvh bg-canvas">
    <!-- Бренд-панель: ценность продукта, а не декоративный фон. -->
    <aside
      aria-hidden="true"
      class="relative hidden w-[44%] max-w-xl flex-none overflow-hidden bg-brand-gradient text-white md:flex lg:w-[46%]"
    >
      <!-- Мягкая подсветка сверху + крупная полупрозрачная «Н» как визитная метка. -->
      <div
        class="pointer-events-none absolute inset-0"
        style="background: radial-gradient(120% 80% at 20% 0%, rgb(255 255 255 / 0.28) 0%, transparent 55%)"
      />
      <span
        class="pointer-events-none absolute -bottom-16 -right-10 select-none font-display text-[16rem] font-extrabold leading-none text-white/10"
      >Н</span>

      <div class="relative flex h-full w-full flex-col justify-between gap-10 p-10 xl:p-12">
        <div class="flex items-center gap-3">
          <span
            class="grid h-11 w-11 place-items-center rounded-xl bg-white/15 text-xl font-bold backdrop-blur"
            style="box-shadow: inset 0 0 0 1px rgb(255 255 255 / 0.25)"
          >Н</span>
          <span class="font-display text-xl font-bold tracking-tight">NABILET</span>
        </div>

        <div>
          <h2 class="font-display text-4xl font-bold leading-tight tracking-tight xl:text-5xl">
            Продажи билетов<br />из одной панели
          </h2>
          <p class="mt-4 max-w-md text-base text-white/80">
            События, схемы залов, касса и витрина — всё, что нужно организатору, собрано в одном месте.
          </p>

          <ul class="mt-8 space-y-3">
            <li v-for="perk in PERKS" :key="perk" class="flex items-start gap-3 text-sm text-white/90">
              <span
                class="mt-0.5 grid h-5 w-5 flex-none place-items-center rounded-full bg-mint-400 text-[0.7rem] font-bold text-ink-900"
              >✓</span>
              <span>{{ perk }}</span>
            </li>
          </ul>
        </div>

        <p class="text-xs text-white/60">© 2026 NABILET · Панель организатора</p>
      </div>
    </aside>

    <!-- Форма -->
    <div class="flex min-w-0 flex-1 items-center justify-center bg-canvas px-4 py-10">
      <div class="w-full max-w-sm animate-fade-up">
        <!-- Компактный бренд для мобильных: панели слева здесь нет. -->
        <div class="mb-8 flex flex-col items-center gap-3 md:hidden">
          <span
            class="grid h-14 w-14 place-items-center rounded-2xl bg-brand-gradient text-2xl font-bold text-white shadow-brand"
            aria-hidden="true"
          >Н</span>
          <div class="text-center">
            <h1 class="font-display text-xl font-bold tracking-tight text-content">NABILET</h1>
            <p class="mt-0.5 text-sm text-muted">Панель управления билетами</p>
          </div>
        </div>

        <form
          class="surface-card p-6 shadow-md sm:p-7"
          aria-label="Вход в админку"
          @submit.prevent="submit"
        >
          <h1 class="sr-only">Вход в панель организатора</h1>

          <div v-if="error" class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-400">
            {{ error }}
          </div>

          <div class="space-y-4" :class="error ? 'mt-4' : ''">
            <NInput
              v-model="email"
              type="email"
              label="Email"
              placeholder="admin@nabilet.local"
              autocomplete="email"
              required
              icon="✉"
            />

            <div class="relative">
              <NInput
                v-model="password"
                :type="showPassword ? 'text' : 'password'"
                label="Пароль"
                placeholder="••••••••"
                autocomplete="current-password"
                required
                icon="⚿"
              />
              <button
                type="button"
                class="absolute right-3 top-8 text-xs text-subtle transition-colors hover:text-content"
                :aria-label="showPassword ? 'Скрыть пароль' : 'Показать пароль'"
                @click="showPassword = !showPassword"
              >{{ showPassword ? 'Скрыть' : 'Показать' }}</button>
            </div>

            <NButton
              type="submit"
              variant="primary"
              block
              size="lg"
              :loading="loading"
            >
              Войти
            </NButton>
          </div>
        </form>

        <p class="mt-6 text-center text-xs text-subtle">
          Демо: <span class="font-mono text-muted">admin@nabilet.local</span> / <span class="font-mono text-muted">admin123</span>
        </p>
      </div>
    </div>
  </div>
</template>
