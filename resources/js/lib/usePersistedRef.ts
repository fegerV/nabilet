/**
 * Ref, значение которого переживает перезагрузку страницы.
 *
 * Зачем отдельный модуль: раскладка редактора залов (свёрнуты ли панели, какая
 * ширина у инспектора) — это ПРЕДПОЧТЕНИЕ человека, а не состояние данных. Терять
 * его на каждом F5 раздражает ровно так же, как потерять черновик: человек снова
 * разворачивает панели, которые только что свернул. localStorage переживает и F5,
 * и переходы между разделами, поэтому оно живёт здесь, а не в стейт-менеджере.
 *
 * Запись обёрнута в try/catch сознательно: localStorage недоступен в приватном
 * режиме и в вебвью, а раскладка — не то, ради чего стоит ронять редактор.
 * Чтение — тоже: испорченное значение (руками поправленный ключ) не должно
 * ломать инициализацию, поэтому JSON.parse под защитой и падает в defaultValue.
 */
import { ref, watch, type Ref } from 'vue'

export function usePersistedRef<T>(key: string, defaultValue: T): Ref<T> {
  const storageKey = `nabilet:${key}`

  const read = (): T => {
    if (typeof window === 'undefined') return defaultValue
    try {
      const raw = window.localStorage.getItem(storageKey)
      if (raw === null) return defaultValue
      return JSON.parse(raw) as T
    } catch {
      return defaultValue
    }
  }

  const state = ref(read()) as Ref<T>

  watch(state, (value) => {
    if (typeof window === 'undefined') return
    try {
      window.localStorage.setItem(storageKey, JSON.stringify(value))
    } catch {
      // Приватный режим / переполнение — раскладку можно и не сохранить.
    }
  }, { deep: true })

  return state
}
