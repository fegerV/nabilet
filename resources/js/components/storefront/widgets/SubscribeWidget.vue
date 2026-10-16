<script setup lang="ts">
/**
 * Подписка на афишу.
 *
 * Валидация — до отправки: «вы не ввели e-mail» после запроса выглядит как
 * издевательство. Адрес уходит в `POST /subscribe` (NewsletterController →
 * `newsletter_subscribers` + запись согласия в consents): ответ 201/200 —
 * «Готово», ошибка сети/сервера — честное предложение попробовать позже.
 * Повторная отправка того же адреса безопасна (сервер идемпотентен).
 *
 * ОШИБКА КОНКРЕТНОГО АДРЕСА НЕ РАЗЛИЧИМА ОТ СБОЯ СПЕЦИАЛЬНО: перебор чужих
 * адресов не должен получать подтверждение их существования.
 */
import { computed, ref } from 'vue'
import { send } from '@/lib/api'
import { setting } from '@/lib/storefront'
import type { StorefrontSection } from '@/lib/storefront'

const props = defineProps<{ section: StorefrontSection }>()

const title = computed(() => setting<string>(props.section.settings, 'title', ''))
const text = computed(() => setting<string>(props.section.settings, 'text', ''))
const placeholder = computed(() => setting<string>(props.section.settings, 'placeholder', 'E-mail'))
const buttonLabel = computed(() => setting<string>(props.section.settings, 'buttonLabel', 'Подписаться'))
const privacy = computed(() => setting<string>(props.section.settings, 'privacy', ''))

const email = ref('')
const state = ref<'idle' | 'error' | 'offline' | 'busy' | 'done'>('idle')

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/

async function submit(): Promise<void> {
  const value = email.value.trim()
  if (!EMAIL.test(value)) {
    state.value = 'error'
    return
  }

  state.value = 'busy'

  try {
    await send('/subscribe', 'POST', { email: value })

    state.value = 'done'
    email.value = ''
  } catch {
    // Любая ошибка (сеть, 4xx, 5xx) — честный оффлайн-статус без деталей:
    // различать «адрес занят» мы сознательно не умеем — перебор адресов не
    // должен получать подтверждения их существования.
    state.value = 'offline'
  }
}
</script>

<template>
  <div class="rounded-2xl border border-line bg-surface-2 p-5 sm:p-7">
    <div class="flex flex-wrap items-end justify-between gap-6">
      <div class="min-w-0 max-w-xl">
        <h3 v-if="title" class="text-balance text-xl font-bold tracking-tight text-content">{{ title }}</h3>
        <p v-if="text" class="mt-1.5 text-pretty text-sm text-muted">{{ text }}</p>
      </div>

      <form class="w-full min-w-0 sm:w-auto sm:min-w-[22rem]" @submit.prevent="submit">
        <div class="flex gap-2">
          <label class="sr-only" for="subscribe-email">{{ placeholder }}</label>
          <input
            id="subscribe-email"
            v-model="email"
            type="email"
            inputmode="email"
            autocomplete="email"
            :placeholder="placeholder"
            :disabled="state === 'busy'"
            :aria-invalid="state === 'error' || undefined"
            class="h-12 min-w-0 flex-1 rounded-lg border bg-surface px-3.5 text-sm text-content placeholder:text-subtle focus:outline-none focus:ring-2 focus:ring-brand-500/25 disabled:opacity-60"
            :class="state === 'error' ? 'border-rose-500' : 'border-line focus:border-brand-400'"
            @input="state = 'idle'"
          />
          <button
            type="submit"
            :disabled="state === 'busy'"
            class="h-12 flex-none rounded-lg bg-brand-500 px-5 text-sm font-medium text-brand-on transition-colors hover:bg-brand-400 disabled:opacity-60"
          >
            {{ state === 'busy' ? 'Отправляем…' : buttonLabel }}
          </button>
        </div>

        <p v-if="state === 'error'" class="mt-2 text-xs text-rose-400">Введите корректный e-mail.</p>
        <p v-else-if="state === 'offline'" class="mt-2 text-xs text-sun-400">
          Не получилось отправить — попробуйте ещё раз чуть позже.
        </p>
        <p v-else-if="state === 'done'" class="mt-2 text-xs text-mint-400">
          Готово — расскажем о новых событиях.
        </p>
        <p v-else-if="privacy" class="mt-2 text-xs text-subtle">{{ privacy }}</p>
      </form>
    </div>
  </div>
</template>
