<script setup lang="ts">
/**
 * Запрос ссылки для восстановления пароля.
 *
 * ЭТОТ ЭКРАН — ЕДИНСТВЕННЫЙ СПОСОБ ЗАДАТЬ ПАРОЛЬ ПОКУПАТЕЛЮ, КУПИВШЕМУ БИЛЕТ
 *   Аккаунт, заведённый при оплате, не имеет пароля вовсе
 *   (`CustomerAccountService::resolveForCheckout()` пишет `password = NULL`), а
 *   вместе с ним не имеет и старого пароля, который можно «забыть». Путь
 *   «forgot → письмо → reset» — не восстановление, а первичная установка, и
 *   другого входа в кабинет у такого покупателя нет.
 *
 * ЭКРАН НЕ РАЗЛИЧАЕТ «АДРЕС ЕСТЬ» И «АДРЕСА НЕТ» — и это требование сервера, а
 * не упрощение. `POST /auth/password/forgot` отвечает 204 всегда, иначе он
 * становится оракулом «зарегистрирован ли этот человек у нас». Значит, и текст
 * здесь один: «если адрес у нас есть, письмо ушло». Показать «такого адреса
 * нет» было бы не заботой о пользователе, а утечкой базы адресов.
 */
import { ref } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import NInput from '@/components/ui/NInput.vue'
import { forgotPassword } from '@/lib/customer'
import { ApiError } from '@/lib/api'

const email = ref('')
const loading = ref(false)
const error = ref<string | null>(null)
const sent = ref(false)

async function submit(): Promise<void> {
  if (loading.value) return
  error.value = null
  loading.value = true
  try {
    await forgotPassword(email.value)
    sent.value = true
  } catch (e) {
    // 429 — единственная ошибка, которую имеет смысл показать: она про
    // поведение (слишком часто), а не про существование адреса.
    error.value =
      e instanceof ApiError && e.status === 429
        ? 'Слишком много запросов. Подождите минуту и попробуйте снова.'
        : 'Не удалось отправить письмо. Попробуйте ещё раз.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-md px-4 py-10 sm:px-6">
    <h1 class="font-display text-2xl font-bold tracking-tight text-content">Восстановление пароля</h1>

    <template v-if="sent">
      <div class="surface-card mt-6 p-6">
        <p class="text-sm text-content">
          Если адрес <span class="font-medium">{{ email }}</span> зарегистрирован, письмо
          со ссылкой для смены пароля уже отправлено.
        </p>
        <p class="mt-3 text-xs text-subtle">
          Письма нет? Проверьте «Спам» и правильность адреса. Ссылка действует ограниченное время —
          если не успели, запросите новую.
        </p>
      </div>

      <div class="mt-4 flex flex-wrap gap-2">
        <NButton variant="secondary" @click="sent = false">Отправить ещё раз</NButton>
        <NButton variant="ghost" @click="$router.push('/login')">Ко входу</NButton>
      </div>
    </template>

    <template v-else>
      <p class="mt-1.5 text-sm text-muted">
        Укажите e-mail, на который оформляли заказ — пришлём ссылку для установки нового пароля.
      </p>

      <form class="surface-card mt-6 p-6" aria-label="Восстановление пароля" @submit.prevent="submit">
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

          <NButton type="submit" variant="primary" block size="lg" :loading="loading">Отправить ссылку</NButton>
        </div>
      </form>

      <p class="mt-4 text-sm text-muted">
        Вспомнили пароль?
        <RouterLink to="/login" class="text-brand-400 transition-colors hover:text-brand-300">Войти</RouterLink>
      </p>
    </template>
  </div>
</template>
