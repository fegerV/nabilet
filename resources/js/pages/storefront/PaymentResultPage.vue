<script setup lang="ts">
/**
 * Результат оплаты.
 *
 * Формулировка состояния важнее картинки. Успех — «билеты готовы». Ошибка —
 * «деньги не списаны, попробуйте снова», потому что первая мысль пользователя
 * именно про деньги, а не про статус. В ожидании говорим, что подтверждение
 * придёт от сервера: это снимает вопрос «а точно ли прошло?».
 */
import { computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NButton from '@/components/ui/NButton.vue'
import { useCartStore } from '@/stores/cart'
import { money } from '@/lib/format'

const route = useRoute()
const router = useRouter()
const cart = useCartStore()

const result = computed(() => String(route.params.result ?? 'success'))

const VIEW = {
  success: {
    icon: '✓',
    tone: 'bg-mint-500/15 text-mint-400 ring-mint-500/30',
    title: 'Билеты готовы',
    text: 'Оплата подтверждена. Билеты уже в разделе «Мои билеты» и летят на почту — QR работает и без интернета.',
    primary: 'Показать билеты',
    to: '/tickets',
  },
  pending: {
    icon: '⏱',
    tone: 'bg-sun-500/15 text-sun-400 ring-sun-500/30',
    title: 'Ждём подтверждения',
    text: 'Платёж обрабатывается. Статус подтвердит сервер — обычно это занимает меньше минуты, страницу можно закрыть.',
    primary: 'Обновить статус',
    to: '/tickets',
  },
  fail: {
    icon: '!',
    tone: 'bg-rose-500/15 text-rose-400 ring-rose-500/30',
    title: 'Оплата не прошла',
    text: 'Деньги не списаны. Места пока за вами — можно повторить оплату или выбрать другой способ.',
    primary: 'Попробовать снова',
    to: '/checkout',
  },
} as const

const view = computed(() => VIEW[result.value as keyof typeof VIEW] ?? VIEW.success)
const paidMinor = computed(() => cart.subtotalMinor + 9900 - cart.discountMinor)

onMounted(() => {
  // Успешная оплата закрывает сценарий: удержание больше не нужно.
  if (result.value === 'success') cart.clear()
})
</script>

<template>
  <div class="mx-auto max-w-content px-4 py-10 sm:px-6">
    <div class="mx-auto max-w-md text-center">
      <div
        :class="['mx-auto grid h-16 w-16 place-items-center rounded-2xl text-2xl ring-1', view.tone]"
        aria-hidden="true"
      >{{ view.icon }}</div>

      <h1 class="mt-5 text-2xl font-bold tracking-tight text-content">{{ view.title }}</h1>
      <p class="mt-2 text-pretty text-base text-muted">{{ view.text }}</p>

      <div v-if="result === 'success'" class="surface-card mt-6 p-4 text-left">
        <p class="text-xs uppercase tracking-wide text-subtle">Сумма к оплате</p>
        <p class="mt-0.5 text-2xl font-bold tabular-nums text-content">{{ money(paidMinor) }}</p>
      </div>

      <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
        <NButton variant="accent" size="lg" @click="router.push(view.to)">{{ view.primary }}</NButton>
        <NButton variant="secondary" size="lg" @click="router.push('/')">На афишу</NButton>
      </div>

      <p class="mt-6 text-xs text-subtle">
        Возник вопрос? Напишите в поддержку — номер заказа в письме.
      </p>
    </div>
  </div>
</template>
