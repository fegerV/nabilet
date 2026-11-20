<script setup lang="ts">
/**
 * Fallback-окно встраиваемого виджета схемы зала.
 *
 * Показывается при ЛЮБОЙ ошибке загрузки: нет сети, сервер продаж вернул 5xx,
 * не найден сеанс или схема. Виджет живёт на чужом сайте, поэтому пустой экран
 * здесь хуже честного сообщения: у покупателя должен остаться рабочий способ
 * купить билет — телефон, мессенджер или e-mail организации.
 */
import { computed } from 'vue'
import NButton from '@/components/ui/NButton.vue'
import { useStorefrontStore } from '@/stores/storefront'
import { FALLBACK_MESSAGE, supportActions, type SupportAction } from '@/lib/support'

const props = withDefaults(defineProps<{
  error?: string
  title?: string
  retryLabel?: string
  showRetry?: boolean
}>(), {
  title: 'Не удалось открыть кассу',
  retryLabel: 'Повторить',
  showRetry: true,
})

const emit = defineEmits<{ retry: [] }>()

const storefront = useStorefrontStore()
const actions = computed<SupportAction[]>(() => supportActions(storefront.config.support))
const message = computed(() => storefront.config.support?.message || FALLBACK_MESSAGE)

// Серверный текст ошибки может быть длинным (стек/URL) — в витрине нужен
// короткий диагностический штрих, а не простыня.
const detail = computed(() => (props.error ?? '').trim().slice(0, 160))
</script>

<template>
  <div class="surface-card flex flex-col items-center gap-3 px-4 py-10 text-center" role="alert">
    <span class="grid h-11 w-11 place-items-center rounded-full bg-sun-500/15 text-xl text-sun-500" aria-hidden="true">!</span>
    <p class="text-base font-semibold text-content">{{ title }}</p>
    <p class="max-w-md text-sm text-muted">{{ message }}</p>
    <p v-if="detail" class="max-w-md text-2xs text-subtle">{{ detail }}</p>

    <div class="mt-2 flex flex-wrap items-center justify-center gap-2">
      <NButton v-if="showRetry" variant="accent" size="sm" @click="emit('retry')">{{ retryLabel }}</NButton>
      <a
        v-for="action in actions"
        :key="action.kind"
        :href="action.href"
        :target="action.href.startsWith('http') ? '_blank' : undefined"
        :rel="action.href.startsWith('http') ? 'noopener noreferrer' : undefined"
        class="inline-flex h-9 items-center justify-center rounded-md border border-line-strong px-3 text-sm font-medium text-content hover:bg-surface-3 active:bg-line"
      >
        {{ action.label }}
      </a>
      <a
        href="/page/contacts"
        class="inline-flex h-9 items-center justify-center rounded-md px-3 text-sm text-muted hover:text-content"
      >
        Все контакты
      </a>
    </div>
  </div>
</template>
