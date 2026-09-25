<script setup lang="ts">
/** Планировка танцпола и столов в «Кассы Югры»: сцена снизу-сверху, танцпол-зона
 *  перед сценой (кликабельна, покупка N билетов), столы/места вокруг.
 *
 *  Новый координатный рендер продаж: SVG с настоящими координатами (x/y),
 *  используется для залов, где известна планировка (Вавилон).
 */
import { computed, ref } from 'vue'
import { money } from '@/lib/format'
import type { InventoryItem } from '@/lib/inventory'

const props = defineProps<{
  /** Инвентарь сессии (места + стоячие зоны). */
  inventory: InventoryItem[]
  /** Действие при клике на место. */
  onToggle?: (item: InventoryItem, qty: number) => Promise<void> | void
  /** Действие при выборе зоны танцпола (выбор N билетов). */
  onDanceToggle?: (item: InventoryItem, qty: number) => Promise<void> | void
  /** id выбранных мест. */
  selected?: string[]
  maxQuantity?: number
}>()

const emit = defineEmits<{ toggle: [item: InventoryItem, qty: number]; limit: [] }>()

const selectedIds = computed(() => new Set(props.selected ?? []))

/** Масштаб: привести данные (0..60) к пикселям SVG (600x400). */
const SCALE_X = 10
const SCALE_Y = 10
const PAD = 30
const SVG_W = 620
const SVG_H = 400

/** Сцена — сверху по центру. */
const stage = { x: (SVG_W - 200) / 2, y: 12, w: 200, h: 30 }

/** Места (seat) с координатами. */
const seats = computed(() =>
  props.inventory.filter((i) => i.type === 'seat' && i.seat),
)

/** Координаты места → пиксели SVG. y у Яндекса растёт вниз, но мы хотим
 *  «ближе к сцене = дороже» (сцена сверху) — потому переворачиваем Y. */
function px(item: InventoryItem): { x: number; y: number; n: number } {
  const sx = Number(item.seat?.x ?? 0)
  const sy = Number(item.seat?.y ?? 0)
  // инвертируем Y: данные 0..40, нижний край → верх (к сцене)
  const ny = 40 - sy
  return {
    x: PAD + sx * SCALE_X,
    y: PAD + ny * SCALE_Y,
    n: Number(item.seat?.number ?? 0),
  }
}

/** Стоячая зона (танцпол). */
const dance = computed(() => props.inventory.find((i) => i.type === 'standing'))

const danceRect = computed(() => {
  const d = dance.value
  if (!d) return null
  const w = 150
  const h = 90
  return { x: (SVG_W - w) / 2, y: 70, w, h }
})

const danceSelected = ref(false)
const danceQty = ref(2)
const dancePickerOpen = ref(false)

function toggleDance(): void {
  if (danceSelected.value) return
  dancePickerOpen.value = true
}

function pickDance(qty: number): void {
  const d = dance.value
  if (!d) return
  danceQty.value = qty
  dancePickerOpen.value = false
  emit('toggle', d, qty)
  danceSelected.value = true
}

function toggleSeat(item: InventoryItem): void {
  if (selectedIds.value.has(String(item.id))) return
  emit('toggle', item, 1)
}

function seatState(item: InventoryItem): 'free' | 'sold' | 'held' | 'unavailable' {
  if (item.status === 'sold') return 'sold'
  if (item.status === 'held') return 'held'
  if ((item.available_quantity ?? 1) < 1) return 'unavailable'
  return 'free'
}
</script>

<template>
  <div class="overflow-hidden rounded-xl border border-line bg-surface-2">
    <div class="flex items-center justify-between gap-3 px-3 py-2 text-2xs text-subtle">
      <span>{{ seats.length }} мест · танцпол {{ dance?.available_quantity ?? 0 }} билетов</span>
      <span class="text-layer flex gap-2">
        <span class="flex items-center gap-1"><i class="inline-block size-2.5 rounded-[2px] bg-brand-400" /> свободно</span>
        <span class="flex items-center gap-1"><i class="inline-block size-2.5 rounded-[2px] bg-amber-400" /> занято</span>
      </span>
    </div>

    <svg :viewBox="`0 0 ${SVG_W} ${SVG_H}`" class="block h-auto w-full" role="img" aria-label="Схема зала">
      <!-- Сцена -->
      <rect :x="stage.x" :y="stage.y" :width="stage.w" :height="stage.h" rx="4" fill="url(#stageGrad)" />
      <text :x="SVG_W / 2" :y="stage.y + 21" text-anchor="middle" fill="#fff" font-size="13" font-weight="600">СЦЕНА</text>

      <!-- Танцпол -->
      <g v-if="dance && danceRect">
        <rect
          :x="danceRect.x" :y="danceRect.y" :width="danceRect.w" :height="danceRect.h"
          rx="14" fill="rgba(240,180,60,0.12)" stroke="#E8B544" stroke-width="2" stroke-dasharray="8 5"
          class="cursor-pointer transition hover:opacity-80" @click="toggleDance"
        />
        <text :x="SVG_W / 2" :y="danceRect.y + 34" text-anchor="middle" fill="#F0C060" font-size="14" font-weight="700">Танцпол</text>
        <text :x="SVG_W / 2" :y="danceRect.y + 52" text-anchor="middle" fill="#E8E0FF" font-size="12">{{ money(dance.price_amount) }} · {{ dance.available_quantity }} билетов</text>
        <text v-if="!dancePickerOpen && !danceSelected" :x="SVG_W / 2" :y="danceRect.y + 72" text-anchor="middle" fill="#C9C0E8" font-size="11">клик — выбрать билеты</text>

        <!-- Селектор количества билетов -->
        <g v-if="dancePickerOpen" :transform="`translate(${SVG_W / 2 - 110}, ${danceRect.y + danceRect.h + 20})`">
          <rect x="0" y="-18" width="220" height="44" rx="12" fill="#1a1530" stroke="#F0C060" stroke-width="1" />
          <g v-for="(q, i) in [1, 2, 3, 4, 5]" :key="q" @click="pickDance(q)" class="cursor-pointer">
            <circle :cx="15 + i * 42" cy="4" :r="14" :fill="q === danceQty ? '#F0C060' : '#2a2140'" stroke="#E8B544" stroke-width="1">
              <title>Купить {{ q }} билет(а)</title>
            </circle>
            <text :x="15 + i * 42" :y="8" text-anchor="middle" fill="#1a1530" font-size="13" font-weight="700">{{ q }}</text>
          </g>
        </g>
      </g>

      <!-- Места -->
      <g v-for="s in seats" :key="String(s.id)">
        <circle
          :cx="px(s).x" :cy="px(s).y" :r="5"
          :fill="seatState(s) === 'sold' ? '#5a3f66' : seatState(s) === 'held' ? '#6a4a7a' : selectedIds.has(String(s.id)) ? '#C9A0FF' : '#8E74FF'"
          class="cursor-pointer transition hover:scale-125"
          @click="toggleSeat(s)"
        >
          <title>Место {{ px(s).n }}</title>
        </circle>
      </g>

      <defs>
        <linearGradient id="stageGrad" x1="0" y1="0" x2="1" y2="0">
          <stop offset="0" stop-color="#6D4AFF" />
          <stop offset="1" stop-color="#FF5C22" />
        </linearGradient>
      </defs>
    </svg>
  </div>
</template>
