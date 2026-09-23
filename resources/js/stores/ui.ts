/**
 * Глобальное состояние оболочки: тема, тосты, открытость командной палитры.
 *
 * Тема переключается без перезагрузки (ТЗ §41) и сохраняет выбор пользователя;
 * если выбора не было, следует системной теме. Класс `dark` живёт на <html>,
 * поэтому Filament и SPA выглядят одинаково в момент переключения.
 */
import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'

export type Theme = 'light' | 'dark' | 'auto'

export interface Toast {
  id: number
  tone: 'neutral' | 'mint' | 'rose' | 'sun' | 'brand'
  title: string
  description?: string
  /** Сколько держим на экране, мс. 0 — держим, пока не закроют. */
  duration: number
}

const THEME_KEY = 'nabilet.theme'

export const useUiStore = defineStore('ui', () => {
  const theme = ref<Theme>(readStoredTheme())
  const resolvedTheme = ref<'light' | 'dark'>('dark')
  const toasts = ref<Toast[]>([])
  const commandOpen = ref(false)
  const navOpen = ref(false)
  let toastId = 0

  function readStoredTheme(): Theme {
    try {
      const stored = localStorage.getItem(THEME_KEY)
      if (stored === 'light' || stored === 'dark' || stored === 'auto') return stored
    } catch {
      /* приватный режим — работаем по системной теме */
    }
    return 'auto'
  }

  const systemQuery = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null

  function apply(): void {
    const dark = theme.value === 'dark' || (theme.value === 'auto' && (systemQuery?.matches ?? true))
    resolvedTheme.value = dark ? 'dark' : 'light'
    document.documentElement.classList.toggle('dark', dark)
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light'
  }

  function setTheme(next: Theme): void {
    theme.value = next
    try {
      localStorage.setItem(THEME_KEY, next)
    } catch {
      /* нечего делать: тема останется на сессию */
    }
    apply()
  }

  function toggleTheme(): void {
    setTheme(resolvedTheme.value === 'dark' ? 'light' : 'dark')
  }

  function notify(tone: Toast['tone'], title: string, description?: string, duration = 4200): void {
    const id = (toastId += 1)
    toasts.value.push({ id, tone, title, description, duration })
    if (duration > 0) {
      window.setTimeout(() => dismiss(id), duration)
    }
  }

  function dismiss(id: number): void {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  systemQuery?.addEventListener('change', () => {
    if (theme.value === 'auto') apply()
  })

  watch(theme, apply, { immediate: false })

  const isDark = computed(() => resolvedTheme.value === 'dark')

  apply()

  return { theme, resolvedTheme, isDark, toasts, commandOpen, navOpen, setTheme, toggleTheme, notify, dismiss }
})
