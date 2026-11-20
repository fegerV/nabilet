<script setup lang="ts">
/**
 * Возврат из Яндекс OAuth (implicit flow).
 *
 * Яндекс отдаёт access_token в ФРАГМЕНТЕ URL — браузер не отправляет его
 * серверу, поэтому страница читает hash сама и сразу отправляет токен в
 * защищённый админ-API. Токен не пишется в localStorage и не логируется.
 *
 * state защищает от подмены ответа: он генерируется перед уходом на Яндекс
 * и хранится в sessionStorage; здесь мы сравниваем значения.
 */
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { send } from '@/lib/api'

const router = useRouter()
const status = ref<'working' | 'done' | 'error'>('working')
const message = ref('Подключаем Яндекс.Диск…')

onMounted(async () => {
  const hash = new URLSearchParams(window.location.hash.replace(/^#/, ''))
  const token = hash.get('access_token')
  const state = hash.get('state')
  const expected = sessionStorage.getItem('nabilet_yandex_oauth_state')
  sessionStorage.removeItem('nabilet_yandex_oauth_state')

  if (!token) {
    status.value = 'error'
    message.value = hash.get('error')
      ? `Яндекс вернул ошибку: ${hash.get('error')}`
      : 'Яндекс не вернул токен. Попробуйте подключить Диск ещё раз.'
    return
  }
  if (!expected || state !== expected) {
    status.value = 'error'
    message.value = 'Не совпал защитный параметр state. Попробуйте подключить Диск ещё раз.'
    return
  }

  try {
    await send('/admin/backups/yandex/token', 'POST', { access_token: token })
    status.value = 'done'
    message.value = 'Яндекс.Диск подключён.'
    // Фрагмент больше не нужен — убираем его из адресной строки и истории.
    window.history.replaceState({}, document.title, '/admin/backups')
    setTimeout(() => router.replace('/admin/backups'), 1200)
  } catch (e) {
    status.value = 'error'
    message.value = e instanceof Error ? e.message : 'Не удалось сохранить токен.'
  }
})
</script>

<template>
  <div class="mx-auto flex min-h-[60vh] max-w-lg items-center justify-center px-4">
    <div class="surface-card w-full p-6 text-center">
      <div class="mb-3 text-4xl" aria-hidden="true">{{ status === 'working' ? '⏳' : status === 'done' ? '✅' : '⚠' }}</div>
      <h1 class="text-lg font-semibold text-content">Подключение Яндекс.Диска</h1>
      <p class="mt-2 text-sm text-muted">{{ message }}</p>
      <button v-if="status !== 'working'" type="button"
              class="mt-5 text-sm text-brand-300 underline decoration-dotted"
              @click="router.replace('/admin/backups')">
        Вернуться к бэкапам
      </button>
    </div>
  </div>
</template>
