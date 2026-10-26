<script setup lang="ts">
/**
 * Приём результата соц-входа: `#/auth/callback?token=…` или `?error=…`.
 *
 * ЗАЧЕМ ОТДЕЛЬНАЯ СТРАНИЦА. Callback провайдера обрабатывает сервер, но токен
 * он отдаёт браузеру — и положить его в `localStorage` может только клиент.
 * Без этого экрана покупатель после «Войти через Яндекс» попадал бы на витрину
 * с токеном в адресе и БЕЗ сессии: шапка показывала бы «войти», кабинет — пустые
 * поля, а токен оставался бы в истории браузера.
 *
 * ПОЧЕМУ `router.replace`, А НЕ `push`
 *
 *   `push` оставил бы запись с токеном в истории: кнопка «назад» вернула бы
 *   человека на `?token=…`, а токен к тому моменту уже принят — то есть
 *   повторный заход показывал бы ошибку вместо кабинета. `replace` затирает
 *   запись.
 *
 * ПОЧЕМУ ОШИБКА ПОКАЗЫВАЕТСЯ ЗДЕСЬ, А НЕ РЕДИРЕКТОМ НА `/login`
 *
 *   Молчаливый редирект на форму входа выглядит как «ничего не произошло»:
 *   человек нажимал «Войти через VK» и вернулся к той же форме без объяснения.
 *   Экран с причиной и ссылкой на парольный вход говорит, что случилось и что
 *   делать дальше.
 */
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import { completeSocialLogin, socialErrorText, socialReturnPath } from '@/lib/social'

const router = useRouter()
const route = useRoute()

const failed = ref<string | null>(null)

onMounted(async () => {
  const raw = route.query.token
  const token = typeof raw === 'string' ? raw.trim() : ''

  if (token === '') {
    const rawError = route.query.error
    failed.value = socialErrorText(typeof rawError === 'string' ? rawError : null)
    return
  }

  try {
    await completeSocialLogin(token)
    await router.replace(socialReturnPath())
  } catch {
    // Токен мог не дойти (истёк, отозван) или профиль не прочитался —
    // покупателю важен результат, а не стадия отказа.
    failed.value = 'Не удалось завершить вход. Попробуйте ещё раз.'
  }
})
</script>

<template>
  <div class="mx-auto max-w-md px-4 py-16 sm:px-6">
    <div v-if="failed === null" class="surface-card p-8 text-center" role="status" aria-live="polite">
      <div class="mx-auto h-8 w-8 animate-spin rounded-full border-2 border-brand-500/30 border-t-brand-500" />
      <p class="mt-4 text-sm text-muted">Завершаем вход…</p>
    </div>

    <div v-else class="surface-card p-8 text-center">
      <h1 class="font-display text-xl font-bold tracking-tight text-content">Вход не завершён</h1>
      <p class="mt-2 text-sm text-muted">{{ failed }}</p>

      <div class="mt-6 flex flex-col gap-2">
        <NButton variant="primary" block size="lg" @click="router.replace('/login')">
          Войти по e-mail
        </NButton>
        <RouterLink
          to="/"
          class="text-sm text-brand-400 transition-colors hover:text-brand-300"
        >Вернуться в афишу</RouterLink>
      </div>
    </div>
  </div>
</template>
