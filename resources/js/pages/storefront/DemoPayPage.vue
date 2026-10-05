<script setup lang="ts">
/**
 * Симулятор подтверждения оплаты (demo-режим ЮKassa).
 *
 * Сюда покупателя приводит `confirmation_url` из `POST /payments`, когда
 * реальных ключей ЮKassa нет (`PAYMENT_DEMO_MODE=true`). Страница делает ровно
 * то, что в реальном режиме делает шлюз: подтверждает платёж и возвращает
 * покупателя на страницу результата.
 *
 * Живёт в SPA, потому что витрина — хеш-SPA и серверного маршрута у неё нет
 * (`routes/web.php` отдаёт только `GET /`). Раньше `confirmation_url` указывал
 * на `/checkout/demo-pay` без `#`, и покупатель после «оплаты» получал 404.
 *
 * Безопасность обеспечивает сервер: при `PAYMENT_DEMO_MODE=false` эндпоинт
 * отвечает 403 `DEMO_DISABLED` и ничего не подтверждает, поэтому открытая
 * страница сама по себе ничего не даёт.
 */
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError } from '@/lib/api'
import { confirmDemoPayment } from '@/lib/payments'

const route = useRoute()
const router = useRouter()

const message = ref('Подтверждаем оплату…')

onMounted(async () => {
  const paymentId = String(route.query.payment_id ?? '')

  if (!paymentId) {
    message.value = 'В ссылке нет идентификатора платежа.'
    await router.replace({ name: 'payment', params: { result: 'fail' } })
    return
  }

  try {
    await confirmDemoPayment(paymentId)
    // `replace`, а не `push`: возврат «назад» не должен повторять подтверждение.
    await router.replace({ name: 'payment', params: { result: 'success' } })
  } catch (error) {
    message.value = error instanceof ApiError ? error.message : 'Не удалось подтвердить платёж.'
    await router.replace({ name: 'payment', params: { result: 'fail' } })
  }
})
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-16 sm:px-6">
    <div class="mx-auto max-w-md text-center">
      <div class="mx-auto h-10 w-10 animate-spin rounded-full border-2 border-line border-t-brand-500" aria-hidden="true" />
      <p class="mt-4 text-sm text-muted" role="status">{{ message }}</p>
      <p class="mt-2 text-2xs text-subtle">Это демонстрационный режим оплаты — реальные деньги не списываются.</p>
    </div>
  </div>
</template>
