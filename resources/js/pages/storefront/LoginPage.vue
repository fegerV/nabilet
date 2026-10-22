<script setup lang="ts">
/**
 * Вход покупателя.
 *
 * ОТДЕЛЬНАЯ СТРАНИЦА ОТ АДМИНСКОГО ВХОДА. Это не дублирование: аудитории разные
 * и токены у них разные (`nabilet_customer_token` против `nabilet_admin_token`,
 * см. `api.ts`), а после входа ведут они в разные места — сюда за билетами, в
 * админку за работой. Общий экран пришлось бы разветвлять по ролям уже ПОСЛЕ
 * запроса, то есть показывать форму не тому, кто её открыл.
 *
 * ПОЧЕМУ ЗДЕСЬ НЕТ ПОЛЯ «ПОДТВЕРДИТЕ ПАРОЛЬ» И ПОЧЕМУ ВООБЩЕ МОЖНО ВОЙТИ БЕЗ
 * РЕГИСТРАЦИИ: аккаунт покупателю заводится сам при оплате заказа
 * (`CustomerAccountService::resolveForCheckout()`), но с `password = NULL` —
 * входить по нему нечем. Такому покупателю нужна не регистрация (аккаунт уже
 * есть), а «Забыли пароль?» → письмо → задать пароль впервые. Ссылка стоит
 * рядом с кнопкой именно поэтому.
 */
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import { customerAuthMessage, loginCustomer } from '@/lib/customer'

const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const loading = ref(false)
const error = ref<string | null>(null)
const showPassword = ref(false)

/**
 * Куда вернуться после входа. Значение приходит от guard'а кабинета
 * (`?redirect=/account`), но принимается только внутренний путь: подставленный
 * снаружи `//evil.example` увёл бы покупателя на чужой сайт прямо после ввода
 * пароля.
 */
const redirect = computed(() => {
  const raw = route.query.redirect
  return typeof raw === 'string' && raw.startsWith('/') && !raw.startsWith('//') ? raw : '/account'
})

/**
 * Guard присылает `?reason=expired`, когда токен в браузере был, но сервер его
 * не признал (истёк, отозван выходом на другом устройстве или сменой пароля).
 * Без объяснения форма входа появлялась «из ниоткуда»: покупатель шёл в клуб
 * или кабинет и оказывался на входе без причины.
 */
const expired = computed(() => route.query.reason === 'expired')

async function submit(): Promise<void> {
  if (loading.value) return
  error.value = null
  loading.value = true
  try {
    await loginCustomer(email.value, password.value)
    router.push(redirect.value)
  } catch (e) {
    error.value = customerAuthMessage(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-md px-4 py-10 sm:px-6">
    <h1 class="font-display text-2xl font-bold tracking-tight text-content">Вход</h1>
    <p class="mt-1.5 text-sm text-muted">
      Билеты, заказы и данные профиля — в личном кабинете.
    </p>

    <p
      v-if="expired"
      class="mt-4 rounded-lg border border-sun-500/30 bg-sun-500/10 px-3 py-2.5 text-sm text-sun-400"
      role="status"
    >
      Сессия истекла — войдите снова, чтобы вернуться на прежнюю страницу.
    </p>

    <form class="surface-card mt-6 p-6" aria-label="Вход в личный кабинет" @submit.prevent="submit">
      <div v-if="error" class="mb-4 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-400">
        {{ error }}
      </div>

      <div class="space-y-4">
        <NInput
          v-model="email"
          type="email"
          label="E-mail"
          placeholder="ivan@example.com"
          autocomplete="email"
          inputmode="email"
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

        <NButton type="submit" variant="primary" block size="lg" :loading="loading">Войти</NButton>
      </div>
    </form>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm">
      <RouterLink to="/forgot-password" class="text-brand-400 transition-colors hover:text-brand-300">
        Забыли пароль?
      </RouterLink>
      <span class="text-muted">
        Нет аккаунта?
        <RouterLink to="/register" class="text-brand-400 transition-colors hover:text-brand-300">Зарегистрироваться</RouterLink>
      </span>
    </div>

    <p class="mt-6 text-xs text-subtle">
      Билеты приходят на e-mail сразу после оплаты — аккаунт не обязателен для покупки,
      но с ним билеты не теряются при смене браузера.
    </p>
  </div>
</template>
