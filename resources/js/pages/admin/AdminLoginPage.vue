<script setup lang="ts">
/**
 * Вход в админку NABILET.
 *
 * Отдельная страница вне AdminShell: логиниться можно, ещё не имея роли.
 * После успешного входа с ролью admin/manager — редирект в админку.
 * Без прав (support/клиент) — показываем отказ.
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
  <div class="flex min-h-dvh items-center justify-center bg-canvas px-4 py-10">
    <div class="w-full max-w-sm">
      <!-- Бренд -->
      <div class="mb-8 flex flex-col items-center gap-3">
        <span
          class="grid h-14 w-14 place-items-center rounded-2xl bg-brand-gradient text-2xl font-bold text-white shadow-brand"
          aria-hidden="true"
        >Н</span>
        <div class="text-center">
          <h1 class="text-xl font-bold tracking-tight text-content">NABILET</h1>
          <p class="mt-0.5 text-sm text-muted">Панель управления билетами</p>
        </div>
      </div>

      <form
        class="surface-card space-y-4 p-6"
        @submit.prevent="submit"
      >
        <div v-if="error" class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2.5 text-sm text-rose-400">
          {{ error }}
        </div>

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
          variant="accent"
          block
          size="lg"
          :loading="loading"
        >
          Войти
        </NButton>
      </form>

      <p class="mt-6 text-center text-xs text-subtle">
        Демо: admin@nabilet.local / admin123
      </p>
    </div>
  </div>
</template>
