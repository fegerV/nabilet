<script setup lang="ts">
/**
 * Таймер удержания мест.
 *
 * Это самый тревожный элемент во всём интерфейсе, поэтому он ведёт себя
 * предсказуемо: показывает остаток, за минуту до конца становится заметным,
 * но не кричит, и всегда предлагает продление. Пользователь никогда не узнаёт
 * о просроченном hold по исчезнувшим местам — только по явному сообщению.
 */
import { computed } from 'vue'
import { countdown } from '@/lib/format'
import { cn } from '@/lib/cn'

const props = withDefaults(
  defineProps<{
    secondsLeft: number
    total?: number
    warning?: boolean
    extendable?: boolean
  }>(),
  { total: 600, extendable: true },
)

const emit = defineEmits<{ extend: [] }>()

const ratio = computed(() => Math.max(0, Math.min(1, props.secondsLeft / props.total)))
const circumference = 2 * Math.PI * 14
const dash = computed(() => `${(circumference * ratio.value).toFixed(2)} ${circumference.toFixed(2)}`)

const critical = computed(() => props.warning || props.secondsLeft <= 60)
</script>

<template>
  <div
    :class="
      cn(
        'flex items-center gap-3 rounded-lg border px-3 py-2.5 transition-colors duration-200',
        critical ? 'border-sun-500/40 bg-sun-500/10' : 'border-line bg-surface-2',
      )
    "
    role="timer"
    aria-live="off"
  >
    <div class="relative h-9 w-9 flex-none">
      <svg class="h-9 w-9 -rotate-90" viewBox="0 0 32 32" aria-hidden="true">
        <circle cx="16" cy="16" r="14" fill="none" stroke="currentColor" stroke-width="3" class="text-line" />
        <circle
          cx="16"
          cy="16"
          r="14"
          fill="none"
          :stroke-dasharray="dash"
          stroke-width="3"
          stroke-linecap="round"
          :class="critical ? 'stroke-sun-500' : 'stroke-brand-500'"
          class="transition-all duration-1000 ease-linear"
        />
      </svg>
      <span
        aria-hidden="true"
        :class="cn('absolute inset-0 grid place-items-center text-2xs font-semibold', critical ? 'text-sun-400' : 'text-brand-300')"
      >{{ Math.ceil(secondsLeft / 60) }}м</span>
    </div>

    <div class="min-w-0 flex-1">
      <p class="text-sm font-medium leading-tight text-content">
        Места забронированы на {{ countdown(secondsLeft) }}
      </p>
      <p class="mt-0.5 text-xs leading-tight text-subtle">
        {{ critical ? 'Почти всё — продлите, чтобы не потерять выбор' : 'Пока держим, никто другой их не купит' }}
      </p>
    </div>

    <button
      v-if="extendable && critical"
      type="button"
      class="flex-none rounded-md border border-sun-500/50 px-2.5 py-1.5 text-xs font-medium text-sun-400 transition-colors hover:bg-sun-500/15"
      @click="emit('extend')"
    >
      Продлить
    </button>
  </div>
</template>
