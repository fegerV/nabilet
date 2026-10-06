<script setup lang="ts">
/**
 * Промо-полоса: акция, промокод, призыв к действию.
 *
 * Тон задаёт кисть (бренд / акцент / тёмный), промокод показывается
 * отдельной плашкой — его копируют, поэтому это отдельный элемент
 * с моноширинным шрифтом, а не часть текста.
 */
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { setting } from '@/lib/storefront'
import type { StorefrontSection } from '@/lib/storefront'

const props = defineProps<{ section: StorefrontSection }>()

const router = useRouter()
const copied = ref(false)

const tone = computed(() => setting<'brand' | 'accent' | 'dark'>(props.section.settings, 'tone', 'brand'))
const title = computed(() => setting<string>(props.section.settings, 'title', ''))
const text = computed(() => setting<string>(props.section.settings, 'text', ''))
const buttonLabel = computed(() => setting<string>(props.section.settings, 'buttonLabel', ''))
const buttonLink = computed(() => setting<string>(props.section.settings, 'buttonLink', '/'))
const code = computed(() => setting<string>(props.section.settings, 'code', ''))

const TONES: Record<string, string> = {
  brand: 'bg-brand-gradient text-brand-on',
  accent: 'bg-accent-gradient text-accent-on',
  dark: 'bg-ink-850 text-white',
}

async function copyCode(): Promise<void> {
  if (!code.value) return
  try {
    await navigator.clipboard.writeText(code.value)
    copied.value = true
    window.setTimeout(() => (copied.value = false), 1800)
  } catch {
    /* буфер недоступен (http, Safari без жеста) — промокод просто видно */
  }
}

function go(): void {
  if (!buttonLink.value) return
  if (buttonLink.value.startsWith('/')) router.push(buttonLink.value)
  else window.open(buttonLink.value, '_blank', 'noopener')
}
</script>

<template>
  <div :class="['relative overflow-hidden rounded-2xl px-5 py-7 sm:px-8 sm:py-9', TONES[tone] ?? TONES.brand]">
    <div class="flex flex-wrap items-center justify-between gap-6">
      <div class="min-w-0 max-w-2xl">
        <h3 v-if="title" class="text-balance text-xl font-bold tracking-tight sm:text-2xl">{{ title }}</h3>
        <p v-if="text" class="mt-2 text-pretty text-sm opacity-90 sm:text-base">{{ text }}</p>

        <button
          v-if="code"
          type="button"
          class="mt-4 inline-flex items-center gap-2 rounded-lg border border-white/30 bg-white/10 px-3 py-1.5 font-mono text-sm backdrop-blur transition-colors hover:bg-white/20"
          :aria-label="`Скопировать промокод ${code}`"
          @click="copyCode"
        >
          {{ code }}
          <span aria-hidden="true">{{ copied ? '✓' : '⧉' }}</span>
        </button>
        <span v-if="copied" class="ml-2 text-xs opacity-90">Скопировано</span>
      </div>

      <button
        v-if="buttonLabel"
        type="button"
        class="inline-flex h-12 flex-none items-center rounded-lg bg-white/95 px-6 text-sm font-semibold text-ink-900 transition-transform duration-120 hover:scale-[1.02] active:scale-100"
        @click="go"
      >
        {{ buttonLabel }}
      </button>
    </div>
  </div>
</template>
