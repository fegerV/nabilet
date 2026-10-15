<script setup lang="ts">
/**
 * Полный холст билета на витрине «Мои билеты».
 *
 * Письмо и PDF уже несут оформленный макет (см. `TicketPdfRenderer`), а витрина
 * до этого показывала только семантическую карточку. Здесь рендерим тот же
 * абсолютно спозиционированный холст, что и конструктор/PDF, но с реальными
 * данными билета: переменные `{{event.name}}` и т.п. подставляются из карточки,
 * QR рисуется локально (`qrcode`) по `qrPayload` — подписанный токен не уходит
 * наружу. Масштаб — под ширину колонки, пропорции сохраняются.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import QRCode from 'qrcode'
import {
  flattenVariables,
  type BuilderElement,
  type TicketVariables,
} from '@/lib/ticketBuilder'
import { dateTime, time } from '@/lib/format'
import type { TicketTemplateConfig } from '@/lib/types'

const props = defineProps<{
  config: TicketTemplateConfig
  ticket: {
    eventTitle: string
    venue: string
    sessionAt: string
    sector: string
    row: number
    seat: number
    code: string
    holder?: string
    qrPayload: string
    status: string
  }
}>()

const els = computed<BuilderElement[]>(() => props.config.elements as BuilderElement[])

const qrUrl = ref('')
watch(
  () => props.ticket.qrPayload,
  async (payload) => {
    qrUrl.value = ''
    if (!payload) return
    try {
      qrUrl.value = await QRCode.toDataURL(payload, {
        width: 320,
        margin: 1,
        errorCorrectionLevel: 'M',
        color: { dark: '#120F24', light: '#FFFFFF' },
      })
    } catch {
      qrUrl.value = ''
    }
  },
  { immediate: true },
)

const variables = computed<TicketVariables>(() => ({
  event: {
    name: props.ticket.eventTitle,
    date: props.ticket.sessionAt ? dateTime(props.ticket.sessionAt) : '',
    time: props.ticket.sessionAt ? time(props.ticket.sessionAt) : '',
    venue: props.ticket.venue,
  },
  ticket: {
    number: props.ticket.code,
    holder: props.ticket.holder ?? '',
  },
  seat: {
    row: props.ticket.row ? String(props.ticket.row) : '',
    number: props.ticket.seat ? String(props.ticket.seat) : '',
    info: [
      props.ticket.sector,
      props.ticket.row ? `Ряд ${props.ticket.row}` : '',
      props.ticket.seat ? `Место ${props.ticket.seat}` : '',
    ].filter(Boolean).join(', '),
  },
}))

const background = computed(() => {
  const g = props.config.backgroundGradient
  if (g && g.from && g.to) {
    return `linear-gradient(${g.direction || 'to bottom'}, ${g.from}, ${g.to})`
  }
  return props.config.backgroundColor || '#ffffff'
})

const flat = computed(() => flattenVariables(variables.value))

function renderText(content?: unknown): string {
  if (typeof content !== 'string' || !content) return ''
  return content.replace(/\{\{([\w.]+)\}\}/g, (whole, key: string) => flat.value[key] ?? whole)
}

function num(value: unknown, fallback = 0): number {
  if (typeof value === 'number' && Number.isFinite(value)) return value
  const parsed = parseFloat(String(value))
  return Number.isFinite(parsed) ? parsed : fallback
}

function textStyle(el: BuilderElement): Record<string, string> {
  const s: Record<string, string> = {
    position: 'absolute',
    left: `${num(el.x)}px`,
    top: `${num(el.y)}px`,
    width: `${num(el.width, 200)}px`,
    fontSize: `${num(el.fontSize, 14)}px`,
    fontWeight: el.fontWeight === 'bold' ? 'bold' : 'normal',
    color: typeof el.color === 'string' && el.color ? el.color : '#111827',
    lineHeight: '1.2',
    whiteSpace: 'pre-wrap',
    wordBreak: 'break-word',
    overflow: 'hidden',
  }
  if (typeof el.fontFamily === 'string' && el.fontFamily) {
    s.fontFamily = el.fontFamily
  }
  return s
}

function rectStyle(el: BuilderElement): Record<string, string> {
  const s: Record<string, string> = {
    position: 'absolute',
    left: `${num(el.x)}px`,
    top: `${num(el.y)}px`,
    width: `${num(el.width, 100)}px`,
    height: `${num(el.height, 100)}px`,
    background: typeof el.fill === 'string' && el.fill ? el.fill : 'transparent',
    borderRadius: `${num(el.borderRadius ?? el.radius, 0)}px`,
  }
  const sw = num(el.strokeWidth, el.stroke ? 1 : 0)
  if (sw > 0 && typeof el.stroke === 'string' && el.stroke) {
    s.border = `${sw}px solid ${el.stroke}`
  }
  return s
}

function circleStyle(el: BuilderElement): Record<string, string> {
  const r = num(el.r, 50)
  const s: Record<string, string> = {
    position: 'absolute',
    left: `${num(el.x)}px`,
    top: `${num(el.y)}px`,
    width: `${r * 2}px`,
    height: `${r * 2}px`,
    borderRadius: '50%',
    background: typeof el.fill === 'string' && el.fill ? el.fill : 'transparent',
  }
  const sw = num(el.strokeWidth, el.stroke ? 1 : 0)
  if (sw > 0 && typeof el.stroke === 'string' && el.stroke) {
    s.border = `${sw}px solid ${el.stroke}`
  }
  return s
}

function lineStyle(el: BuilderElement): Record<string, string> {
  const x = num(el.x ?? el.x1, 0)
  const y = num(el.y ?? el.y1, 0)
  const x2 = num(el.x2, x + num(el.width, 200))
  const y2 = num(el.y2, y)
  const len = Math.hypot(x2 - x, y2 - y)
  const angle = (Math.atan2(y2 - y, x2 - x) * 180) / Math.PI
  const sw = Math.max(num(el.strokeWidth, el.stroke ? 1 : 0), 1)
  const stroke =
    typeof el.stroke === 'string' && el.stroke
      ? el.stroke
      : typeof el.color === 'string' && el.color
        ? el.color
        : '#111827'
  return {
    position: 'absolute',
    left: `${x}px`,
    top: `${y}px`,
    width: `${len}px`,
    height: `${sw}px`,
    background: stroke,
    transform: `rotate(${angle}deg)`,
    transformOrigin: '0 0',
  }
}

function qrStyle(el: BuilderElement): Record<string, string> {
  const w = num(el.size ?? el.width, 140)
  return {
    position: 'absolute',
    left: `${num(el.x)}px`,
    top: `${num(el.y)}px`,
    width: `${w}px`,
    height: `${w}px`,
  }
}

function placeholderStyle(el: BuilderElement): Record<string, string> {
  const w = num(el.width, 100)
  const h = num(el.height, 100)
  return {
    position: 'absolute',
    left: `${num(el.x)}px`,
    top: `${num(el.y)}px`,
    width: `${w}px`,
    height: `${h}px`,
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    background: '#eef2f7',
    border: '1px dashed #cbd5e1',
    color: '#64748b',
    fontSize: '11px',
  }
}

// Масштаб под ширину колонки: холст рисуется в натуральных пикселях, а
// контейнер сжимается через CSS transform, сохраняя пропорции.
const root = ref<HTMLElement | null>(null)
const scale = ref(1)
let observer: ResizeObserver | null = null

function measure(): void {
  if (!root.value) return
  const w = root.value.clientWidth
  if (w > 0) {
    scale.value = w / (props.config.width || 400)
  }
}

onMounted(() => {
  if (root.value && typeof ResizeObserver !== 'undefined') {
    observer = new ResizeObserver(measure)
    observer.observe(root.value)
  }
  measure()
})

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <div ref="root" class="ticket-canvas-root">
    <div
      :style="{
        width: `${config.width * scale}px`,
        height: `${config.height * scale}px`,
        position: 'relative',
      }"
    >
      <div
        :style="{
          width: `${config.width}px`,
          height: `${config.height}px`,
          transform: `scale(${scale})`,
          transformOrigin: 'top left',
          position: 'absolute',
          top: '0',
          left: '0',
        }"
      >
        <div
          class="ticket-canvas"
          :style="{ width: `${config.width}px`, height: `${config.height}px`, background }"
        >
          <template v-for="(el, i) in els" :key="(el.id as number | string) ?? i">
            <div v-if="el.type === 'text'" :style="textStyle(el)">{{ renderText(el.content) }}</div>
            <div v-else-if="el.type === 'rectangle'" :style="rectStyle(el)" />
            <div v-else-if="el.type === 'circle'" :style="circleStyle(el)" />
            <div v-else-if="el.type === 'line'" :style="lineStyle(el)" />
            <div v-else-if="el.type === 'qr'" :style="qrStyle(el)">
              <img v-if="qrUrl" :src="qrUrl" alt="QR билета" style="width:100%;height:100%;display:block" />
              <div v-else class="qr-fallback">QR</div>
            </div>
            <div v-else :style="placeholderStyle(el)">
              {{ el.type === 'barcode' ? '|| || ||' : 'IMG' }}
            </div>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ticket-canvas-root {
  width: 100%;
}

.ticket-canvas {
  position: relative;
  overflow: hidden;
  border-radius: 10px;
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18);
}

.qr-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: repeating-linear-gradient(
    45deg,
    #f1f5f9,
    #f1f5f9 6px,
    #e2e8f0 6px,
    #e2e8f0 12px
  );
  color: #64748b;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.08em;
}
</style>
