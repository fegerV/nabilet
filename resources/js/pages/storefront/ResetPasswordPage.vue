<script setup lang="ts">
/**
 * Установка нового пароля по ссылке из письма.
 *
 * ПУТЬ ЗДЕСЬ — `/#/reset-password?token=…&email=…`, И ЭТО НЕ УКРАШЕНИЕ
 *   Витрина — SPA с hash-историей, серверного маршрута `/reset-password` не
 *   существует: `routes/web.php` отдаёт оболочку только на `/`, остальное
 *   перехватывает `Route::fallback()`. Пока `AccountMailService::actionUrl()`
 *   собирал ссылку без `#`, письмо приходило, а переход по нему давал 404 — то
 *   есть задать пароль было нельзя ни одним способом. Форма ссылки и этот
 *   маршрут обязаны меняться вместе.
 *
 * ТОКЕН ОДНОРАЗОВЫЙ И ПРИВЯЗАН К АДРЕСУ. `AccountRecoveryService::resetPassword()`
 * сверяет подписанный адрес с присланным в теле, поэтому `email` из query
 * уходит обратно как есть — подменять его на введённый вручную нечего и незачем.
 */
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import { resetPassword } from '@/lib/customer'
import { ApiError } from '@/lib/api'

const route = useRoute()
const router = useRouter()

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''))

const password = ref('')
const confirmation = ref('')
const loading = ref(false)
const error = ref<string | null>(null)
const done = ref(false)

const MIN_PASSWORD = 12

/** Ссылка без токена или адреса — открывать форму бессмысленно. */
const usable = computed(() => token.value !== '' && email.value !== '')

async function submit(): Promise<void> {
  if (loading.value || !usable.value) return
  error.value = null

  if (password.value !== confirmation.value) {
    error.value = 'Пароли не совпадают.'
    return
  }

  loading.value = true
  try {
    await resetPassword(token.value, email.value, password.value)
    done.value = true
  } catch (e) {
    // Сервер отвечает одной формулировкой и на просроченный токен, и на
    // подделанный, и на уже использованный (`PASSWORD_RESET_TOKEN_INVALID`) —
    // различать их клиенту нечем и не нужно.
    if (e instanceof ApiError && e.details?.token) {
      error.value = String(e.details.token[0])
    } else if (e instanceof ApiError && e.status === 429) {
      error.value = 'Слишком много попыток. Подождите минуту и повторите.'
    } else if (e instanceof ApiError && e.details?.password) {
      error.value = String(e.details.password[0])
    } else {
      error.value = 'Не удалось изменить пароль. Запросите новую ссылку.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-md px-4 py-10 sm:px-6">
    <h1 class="font-display text-2xl font-bold tracking-tight text-content">Новый пароль</h1>

    <template v-if="done">
      <div class="surface-card mt-6 p-6">
        <p class="text-sm text-content">Пароль изменён.</p>
        <p class="mt-3 text-xs text-subtle">
          Все активные сессии завершены — это часть смены пароля. Войдите заново с новым паролем.
        </p>
      </div>
      <NButton variant="primary" class="mt-4" block @click="router.push('/login')">Войти</NButton>
    </template>

    <template v-else-if="!usable">
      <div class="surface-card mt-6 p-6">
        <p class="text-sm text-rose-400">Ссылка неполная: в ней нет токена или адреса.</p>
        <p class="mt-3 text-xs text-subtle">
          Откройте письмо и перейдите по ссылке целиком — или запросите новую.
        </p>
      </div>
      <NButton variant="secondary" class="mt-4" block @click="router.push('/forgot-password')">
        Запросить новую ссылку
      </NButton>
    </template>

    <template v-else>
      <p class="mt-1.5 text-sm text-muted">
        Новый пароль для <span class="font-medium text-content">{{ email }}</span>
      </p>

      <form class="surface-card mt-6 p-6" aria-label="Установка нового пароля" @submit.prevent="submit">
        <div v-if="error" class="mb-4 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-400">
          {{ error }}
        </div>

        <div class="space-y-4">
          <NInput
            v-model="password"
            type="password"
            label="Новый пароль"
            placeholder="••••••••••••"
            autocomplete="new-password"
            required
            :hint="`Минимум ${MIN_PASSWORD} символов: заглавные и строчные буквы, цифра и знак`"
          />

          <NInput
            v-model="confirmation"
            type="password"
            label="Новый пароль ещё раз"
            placeholder="••••••••••••"
            autocomplete="new-password"
            required
          />

          <NButton type="submit" variant="primary" block size="lg" :loading="loading">Сохранить пароль</NButton>
        </div>
      </form>
    </template>
  </div>
</template>
