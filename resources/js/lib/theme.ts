/**
 * Цветовая гамма витрины в рантайме.
 *
 * Организатор выбирает бренд-цвет в админке — пересобирать фронт под każдую
 * площадку нельзя, поэтому шкала brand/accent не лежит в tailwind-конфиге
 * литералами, а собирается здесь и выставляется CSS-переменными на <html>.
 * Tailwind-классы (bg-brand-500, text-brand-300, shadow-brand) ссылаются на
 * эти переменные, так что перекраска ничего не ломает.
 *
 * Из одной кисти строится вся шкала 50…950: организатор вводит один цвет,
 * а не одиннадцать оттенков, и не может получить «билет на бирюзовом фоне
 * с бирюзовой же подписью».
 */

const SHADE_STEPS: Array<{ key: number; lightness: number; saturation: number }> = [
  { key: 50, lightness: 96, saturation: 0.4 },
  { key: 100, lightness: 92, saturation: 0.55 },
  { key: 200, lightness: 84, saturation: 0.7 },
  { key: 300, lightness: 74, saturation: 0.85 },
  { key: 400, lightness: 9, saturation: 1 }, // относительно базы: +9
  { key: 500, lightness: 0, saturation: 1 }, // база
  { key: 600, lightness: -9, saturation: 1 },
  { key: 700, lightness: -18, saturation: 1 },
  { key: 800, lightness: -27, saturation: 1 },
  { key: 900, lightness: -36, saturation: 1 },
  { key: 950, lightness: -45, saturation: 1 },
]

export interface Rgb {
  r: number
  g: number
  b: number
}

/** #abc / #aabbcc → RGB. Невалидное — null, вызывающий решает, что делать. */
export function hexToRgb(hex: string): Rgb | null {
  const value = hex.trim().replace('#', '')
  const full =
    value.length === 3
      ? value
          .split('')
          .map((c) => c + c)
          .join('')
      : value
  if (!/^[0-9a-fA-F]{6}$/.test(full)) return null
  return {
    r: parseInt(full.slice(0, 2), 16),
    g: parseInt(full.slice(2, 4), 16),
    b: parseInt(full.slice(4, 6), 16),
  }
}

export function rgbToHex({ r, g, b }: Rgb): string {
  const part = (n: number): string => Math.round(clamp(n, 0, 255)).toString(16).padStart(2, '0')
  return `#${part(r)}${part(g)}${part(b)}`
}

function rgbToHsl({ r, g, b }: Rgb): { h: number; s: number; l: number } {
  const rn = r / 255
  const gn = g / 255
  const bn = b / 255
  const max = Math.max(rn, gn, bn)
  const min = Math.min(rn, gn, bn)
  const l = (max + min) / 2
  const d = max - min
  if (d === 0) return { h: 0, s: 0, l: l * 100 }
  const s = d / (1 - Math.abs(2 * l - 1))
  let h: number
  if (max === rn) h = ((gn - bn) / d) % 6
  else if (max === gn) h = (bn - rn) / d + 2
  else h = (rn - gn) / d + 4
  return { h: ((h * 60) % 360 + 360) % 360, s: s * 100, l: l * 100 }
}

function hslToRgb(h: number, s: number, l: number): Rgb {
  const sn = clamp(s, 0, 100) / 100
  const ln = clamp(l, 0, 100) / 100
  const c = (1 - Math.abs(2 * ln - 1)) * sn
  const hp = (((h % 360) + 360) % 360) / 60
  const x = c * (1 - Math.abs((hp % 2) - 1))
  let rgb: [number, number, number]
  if (hp < 1) rgb = [c, x, 0]
  else if (hp < 2) rgb = [x, c, 0]
  else if (hp < 3) rgb = [0, c, x]
  else if (hp < 4) rgb = [0, x, c]
  else if (hp < 5) rgb = [x, 0, c]
  else rgb = [c, 0, x]
  const m = ln - c / 2
  return { r: (rgb[0] + m) * 255, g: (rgb[1] + m) * 255, b: (rgb[2] + m) * 255 }
}

function clamp(value: number, min: number, max: number): number {
  return Math.max(min, Math.min(max, value))
}

/** Относительная яркость (WCAG) — по ней выбираем цвет текста на плашке. */
function luminance({ r, g, b }: Rgb): number {
  const channel = (v: number): number => {
    const c = v / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  }
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
}

/**
 * Шкала из одной кисти.
 *
 * Яркость базы ограничиваем сверху (58%): белая подпись на кнопке
 * «Выбрать» должна читаться даже если организатор задал лаймовый бренд.
 */
export function buildScale(baseHex: string, strongHex?: string): Record<string, Rgb> {
  const base = hexToRgb(baseHex)
  const strong = strongHex ? hexToRgb(strongHex) : null
  if (!base) return {}

  const { h, s } = rgbToHsl(base)
  const baseLightness = clamp(rgbToHsl(base).l, 26, 58)

  const scale: Record<string, Rgb> = {}
  for (const step of SHADE_STEPS) {
    const lightness =
      step.key <= 300
        ? step.lightness
        : clamp(baseLightness + step.lightness, 6, 96)
    const saturation = step.key <= 300 ? Math.min(s * step.saturation, 92) : s
    scale[String(step.key)] = hslToRgb(h, saturation, lightness)
  }

  // «Сильный» оттенок из конфига перекрывает вычисленный 600-й, если задан.
  if (strong) scale['600'] = strong

  return scale
}

/** Цвет текста, контрастный к заливке: тёмные чернила на светлом бренде. */
export function onColor(baseHex: string): Rgb {
  const base = hexToRgb(baseHex)
  if (!base) return { r: 255, g: 255, b: 255 }
  return luminance(base) > 0.45 ? { r: 15, g: 12, b: 30 } : { r: 255, g: 255, b: 255 }
}

function triplet({ r, g, b }: Rgb): string {
  return `${Math.round(r)} ${Math.round(g)} ${Math.round(b)}`
}

export interface ThemeBrush {
  brand: string
  brandStrong?: string
  accent: string
  accentStrong?: string
  radius?: number
  surface?: string
  mode?: string
}

/**
 * Выставить переменные темы.
 *
 * `target` — элемент, на который вешаем переменные. Для витрины это <html>,
 * для предпросмотра в конструкторе — контейнер превью: те же классы Tailwind
 * внутри контейнера подхватывают свои значения, а остальная админка не
 * перекрашивается, пока организатор подбирает цвет.
 */
/**
 * Набор CSS-переменных кистей.
 *
 * Нужен в двух видах: как объект для `:style` (предпросмотр в конструкторе —
 * Vue сам diff'ит и снимает старые значения) и как запись на <html> (витрина).
 */
export function themeVars(brush: ThemeBrush): Record<string, string> {
  const vars: Record<string, string> = {}
  const brandScale = buildScale(brush.brand, brush.brandStrong)
  const accentScale = buildScale(brush.accent, brush.accentStrong)

  for (const [shade, rgb] of Object.entries(brandScale)) {
    vars[`--brand-${shade}`] = triplet(rgb)
  }
  for (const [shade, rgb] of Object.entries(accentScale)) {
    vars[`--accent-${shade}`] = triplet(rgb)
  }

  vars['--brand-on'] = triplet(onColor(brush.brand))
  vars['--accent-on'] = triplet(onColor(brush.accent))

  if (typeof brush.radius === 'number') {
    vars['--radius'] = `${clamp(brush.radius, 0, 28)}px`
  }

  return vars
}

export function applyTheme(brush: ThemeBrush, target: HTMLElement = document.documentElement): void {
  for (const [key, value] of Object.entries(themeVars(brush))) {
    target.style.setProperty(key, value)
  }

  if (brush.surface && target === document.documentElement) {
    if (brush.surface === 'tinted') document.documentElement.removeAttribute('data-surface')
    else document.documentElement.setAttribute('data-surface', brush.surface)
  }
}

/** Сбросить кастомные значения — вернуть дефолт из app.css. */
export function resetTheme(target: HTMLElement = document.documentElement): void {
  const keys: string[] = []
  for (const name of [...target.style]) {
    if (name.startsWith('--brand-') || name.startsWith('--accent-') || name === '--radius') keys.push(name)
  }
  for (const key of keys) target.style.removeProperty(key)
  document.documentElement.removeAttribute('data-surface')
}

/** Светлый ли цвет — чтобы не ставить белую подпись на жёлтую плашку. */
export function isLightColor(hex: string): boolean {
  const rgb = hexToRgb(hex)
  return rgb ? luminance(rgb) > 0.45 : false
}
