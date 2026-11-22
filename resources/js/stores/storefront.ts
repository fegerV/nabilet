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
  WIDGET_LABELS,
  defaultConfig,
  defaultSettings,
  type StorefrontConfig,
  type StorefrontSection,
  type ThemeConfig,
} from '@/lib/storefront'

export const useStorefrontStore = defineStore('storefront', () => {
  const config = ref<StorefrontConfig>(defaultConfig())
  const loading = ref(true)
  const error = ref<string | null>(null)
  /** Конфиг ещё ни разу не сохранялся организацией — админка это покажет. */
  const isDefault = ref(true)
  /** Организация, чью витрину видит гость, и организация, которую правит админ. */
  const publicOrganizationId = ref<string | null>(null)
  const editingOrganizationId = ref<string | null>(null)

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
      publicOrganizationId.value = (res as unknown as { meta?: { organization_id?: string | null } }).meta?.organization_id ?? null
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

  /** Только мета публичной витрины — без применения темы к интерфейсу админки. */
  async function loadPublicMeta(): Promise<void> {
    try {
      const res = await get<StorefrontConfig>('/storefront')
      publicOrganizationId.value = (res as unknown as { meta?: { organization_id?: string | null } }).meta?.organization_id ?? null
    } catch {
      // Мета — подсказка, а не рабочие данные конструктора.
    }
  }

  /** Конфиг для конструктора (админ): отдельный эндпоинт, нужен флаг is_default. */
  async function loadAdmin(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const res = await get<{ data: StorefrontConfig; meta?: { is_default?: boolean; organization_id?: string | null } }>('/admin/storefront')
      const payload = res.data as unknown as { data?: StorefrontConfig; meta?: { is_default?: boolean; organization_id?: string | null } }
      config.value = mergeWithDefaults((payload.data ?? payload) as StorefrontConfig)
      const meta = (res as unknown as { meta?: typeof payload.meta }).meta ?? payload.meta
      isDefault.value = meta?.is_default ?? false
      editingOrganizationId.value = meta?.organization_id ?? null
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
      config.value = defaultConfig()
    } finally {
      loading.value = false
    }
  }

  /**
   * Сохранение возвращает не только успех, но и предупреждения нормализации:
   * сервер отбрасывает лишнее (лимиты, небезопасные значения), и администратор
   * обязан увидеть это сразу, а не после перезагрузки страницы.
   */
  async function save(next: StorefrontConfig): Promise<{ ok: boolean; warnings: string[] }> {
    try {
      const res = await send<StorefrontConfig>('/admin/storefront', 'PUT', { config: next })
      const payload = res.data as unknown as { data?: StorefrontConfig } | StorefrontConfig
      const saved = (payload as { data?: StorefrontConfig }).data ?? (payload as StorefrontConfig)
      const warnings = (res as unknown as { meta?: { warnings?: string[] } }).meta?.warnings ?? []
      config.value = mergeWithDefaults(saved)
      isDefault.value = false
      return { ok: true, warnings }
    } catch {
      return { ok: false, warnings: [] }
    }
  }

  async function reset(): Promise<boolean> {
    try {
      const res = await send<StorefrontConfig>('/admin/storefront/reset', 'POST')
      const payload = res.data as unknown as { data?: StorefrontConfig } | StorefrontConfig
      const saved = (payload as { data?: StorefrontConfig }).data ?? (payload as StorefrontConfig)
      config.value = mergeWithDefaults(saved)
      isDefault.value = true
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
    publicOrganizationId,
    editingOrganizationId,
    load,
    loadPublicMeta,
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
    header: {
      ...base.header,
      ...(payload.header ?? {}),
      nav: Array.isArray(payload.header?.nav) ? payload.header.nav : base.header.nav,
    },
    footer: {
      ...base.footer,
      ...(payload.footer ?? {}),
      columns: Array.isArray(payload.footer?.columns) ? payload.footer.columns : base.footer.columns,
      links: Array.isArray(payload.footer?.links) ? payload.footer.links : base.footer.links,
      social: Array.isArray(payload.footer?.social) ? payload.footer.social : base.footer.social,
    },
    support: { ...base.support, ...(payload.support ?? {}) },
    // ДОПОЛНЯЕМ КАЖДУЮ СЕКЦИЮ дефолтами её виджета, а не только верхние ветки:
    // старый/урезанный конфиг без `settings.columns` не должен ронять виджет.
    sections: mergeSections(Array.isArray(payload.sections) ? payload.sections : null, base.sections),
  }
}

function mergeSections(payload: unknown[] | null, fallback: StorefrontSection[]): StorefrontSection[] {
  if (!payload) return fallback.map((section) => ({ ...section, settings: { ...section.settings } }))

  const merged: StorefrontSection[] = []
  for (const [index, raw] of payload.entries()) {
    if (!raw || typeof raw !== 'object') continue
    const item = raw as Partial<StorefrontSection>
    const type = item.type
    if (!type || !(type in WIDGET_LABELS)) continue

    merged.push({
      id: typeof item.id === 'string' && item.id ? item.id : `sec_${type}_${index}`,
      type,
      visible: item.visible !== false,
      title: typeof item.title === 'string' ? item.title : '',
      subtitle: typeof item.subtitle === 'string' ? item.subtitle : '',
      settings: {
        ...defaultSettings(type),
        ...(item.settings && typeof item.settings === 'object' ? item.settings : {}),
      },
    })
  }

  return merged
}
