/**
 * Конфиг витрины: чтение, применение темы, сохранение из конструктора.
 *
 * Тема применяется не «потом, когда дойдём до виджетов», а сразу при загрузке:
 * если сначала отрисовать дефолтный фиолет, а через 300 мс перекрасить в
 * бренд организатора, пользователь видит мигание. Поэтому витрина ждёт
 * конфиг до первой отрисовки (это один лёгкий запрос), а при его ошибке
 * берёт локальные дефолты — афиша обязана открыться всегда.
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { get, send } from '@/lib/api'
import { applyTheme, resetTheme } from '@/lib/theme'
import {
  defaultConfig,
  type StorefrontConfig,
  type ThemeConfig,
} from '@/lib/storefront'

export const useStorefrontStore = defineStore('storefront', () => {
  const config = ref<StorefrontConfig>(defaultConfig())
  const loading = ref(true)
  const error = ref<string | null>(null)
  /** Конфиг ещё ни разу не сохранялся организацией — админка это покажет. */
  const isDefault = ref(true)

  const sections = computed(() => config.value.sections.filter((s) => s.visible))

  function apply(): void {
    applyTheme(config.value.theme)
  }

  /** Публичный конфиг витрины. Ошибка не роняет страницу: берём дефолт. */
  async function load(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const res = await get<StorefrontConfig>('/storefront')
      const data = res.data as unknown as { data?: StorefrontConfig } | StorefrontConfig
      const payload = (data as { data?: StorefrontConfig }).data ?? (data as StorefrontConfig)
      if (payload && Array.isArray(payload.sections)) {
        config.value = mergeWithDefaults(payload)
        apply()
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
      config.value = defaultConfig()
    } finally {
      loading.value = false
    }
  }

  /** Конфиг для конструктора (админ): отдельный эндпоинт, нужен флаг is_default. */
  async function loadAdmin(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const res = await get<{ data: StorefrontConfig; meta?: { is_default?: boolean } }>('/admin/storefront')
      const payload = res.data as unknown as { data?: StorefrontConfig; meta?: { is_default?: boolean } }
      config.value = mergeWithDefaults((payload.data ?? payload) as StorefrontConfig)
      isDefault.value = payload.meta?.is_default ?? false
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
      config.value = defaultConfig()
    } finally {
      loading.value = false
    }
  }

  async function save(next: StorefrontConfig): Promise<boolean> {
    try {
      const res = await send<StorefrontConfig>('/admin/storefront', 'PUT', { config: next })
      const payload = res.data as unknown as { data?: StorefrontConfig } | StorefrontConfig
      const saved = (payload as { data?: StorefrontConfig }).data ?? (payload as StorefrontConfig)
      config.value = mergeWithDefaults(saved)
      isDefault.value = false
      apply()
      return true
    } catch {
      return false
    }
  }

  async function reset(): Promise<boolean> {
    try {
      const res = await send<StorefrontConfig>('/admin/storefront/reset', 'POST')
      const payload = res.data as unknown as { data?: StorefrontConfig } | StorefrontConfig
      const saved = (payload as { data?: StorefrontConfig }).data ?? (payload as StorefrontConfig)
      config.value = mergeWithDefaults(saved)
      isDefault.value = true
      apply()
      return true
    } catch {
      return false
    }
  }

  /** Вернуть дефолтные кисти (например, при закрытии конструктора без сохранения). */
  function clearTheme(): void {
    resetTheme()
  }

  /** Предпросмотр темы в конструкторе: красим только контейнер превью. */
  function previewTheme(theme: ThemeConfig, target: HTMLElement): void {
    applyTheme(theme, target)
  }

  return {
    config,
    sections,
    loading,
    error,
    isDefault,
    load,
    loadAdmin,
    save,
    reset,
    apply,
    clearTheme,
    previewTheme,
  }
})

/**
 * Конфиг с сервера может быть неполным (старая запись, ручная правка) —
 * недостающие ветки добираются из дефолта, чтобы виджет не упал на
 * `settings.columns === undefined`.
 */
function mergeWithDefaults(payload: StorefrontConfig): StorefrontConfig {
  const base = defaultConfig()
  if (!payload || typeof payload !== 'object') return base

  return {
    version: 1,
    theme: { ...base.theme, ...(payload.theme ?? {}) },
    branding: { ...base.branding, ...(payload.branding ?? {}) },
    header: { ...base.header, ...(payload.header ?? {}) },
    footer: { ...base.footer, ...(payload.footer ?? {}) },
    sections: Array.isArray(payload.sections) ? payload.sections : base.sections,
  }
}
