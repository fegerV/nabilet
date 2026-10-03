/**
 * Яндекс Метрика — интеграция счётчика для передачи данных в Яндекс Директ.
 *
 * Схема:
 *  - Конфигурация (ID счётчика, имена целей) приходит с сервера:
 *    GET /api/v1/analytics/metrika/config (см. config/metrika.php).
 *    Поэтому админ меняет счётчик без редеплоя JS-сборки.
 *  - Скрипт счётчика догружается лениво при первом же событии — он не блокирует
 *    отрисовку витрины и не грузится, если интеграция выключена (counter_id=0).
 *  - Каждое событие воронки покупки билета отправляется как ym(id,'reachGoal',цель):
 *    именно по этим целям Директ строит конверсии и автостратегии ставок
 *    («оплата», «оформление заказа»). Для цели покупки передаётся revenue —
 *    Директ использует его в стратегиях с оплатой за конверсию.
 *  - UTM-метки из URL сохраняются в localStorage и передаются через setParams,
 *    чтобы в Метрике заказ был привязан к кампании Директа.
 */

const CONFIG_URL = '/api/v1/analytics/metrika/config'
const UTM_STORAGE_KEY = 'nabilet.utm'

interface MetrikaConfig {
  enabled: boolean
  counter_id: number
  counter_auth: string | null
  counter_type: string
  safe_mode: boolean
  ecommerce: boolean
  currency: string
  goals: Record<string, string>
}

/** Глобальная функция Метрики, появляющаяся после загрузки тега счётчика. */
declare global {
  interface Window {
    ym?: (...args: unknown[]) => void
    DataLayer?: Array<Record<string, unknown>>
  }
}

let config: MetrikaConfig | null = null
let configPromise: Promise<MetrikaConfig> | null = null
let scriptLoaded = false

/** Товарный фид/параметры для e-commerce целей Метрики. */
export interface PurchasePayload {
  /** Сумма в рублях (не копейках!). */
  revenue?: number
  orderId?: string
  items?: number
}

async function loadConfig(): Promise<MetrikaConfig> {
  if (config) return config
  if (!configPromise) {
    configPromise = fetch(CONFIG_URL, { headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : null))
      .then((c: MetrikaConfig | null) => {
        config = c ?? { enabled: false, counter_id: 0, counter_auth: null, counter_type: 'web', safe_mode: false, ecommerce: false, currency: 'RUB', goals: {} }
        return config
      })
      .catch(() => {
        // Сервер недоступен — тихо глушим интеграцию, витрина не должна страдать.
        configPromise = null
        config = { enabled: false, counter_id: 0, counter_auth: null, counter_type: 'web', safe_mode: false, ecommerce: false, currency: 'RUB', goals: {} }
        return config
      })
  }
  return configPromise
}

/** Инъект стандартного асинхронного тега Метрики (однократно). */
function injectCounterScript(c: MetrikaConfig): void {
  if (scriptLoaded || typeof document === 'undefined') return
  scriptLoaded = true

  const head = document.head || document.documentElement
  const s = document.createElement('script')
  s.async = true
  s.id = 'ym-metrika-script'
  // &ct= — ключ аутентификации счётчика из кода вставки (нужен при размещении
  // на другом домене); без него — обычный tag.js.
  s.src = c.counter_auth
    ? `https://mc.yandex.ru/metrika/tag.js?ct=${encodeURIComponent(c.counter_auth)}`
    : 'https://mc.yandex.ru/metrika/tag.js'
  head.insertBefore(s, head.firstChild)

  // Инициализация по протоколу Метрики: window.ym(id, 'init', options).
  const initOptions: Record<string, unknown> = {
    webvisor: !c.safe_mode,
    clickMap: !c.safe_mode,
    accurateTrackBounce: true,
    trackLinks: true,
    ecommerce: false, // цели шлём явно через reachGoal, а не через dataLayer
  }
  pushYm(c.counter_id, 'init', initOptions)
}

function pushYm(counterId: number, method: string, ...args: unknown[]): void {
  if (typeof window === 'undefined') return
  if (typeof window.ym === 'function') {
    window.ym(counterId, method, ...args)
    return
  }
  // До загрузки tag.js копит очередь вызовов: window.ym(...) заменяется
  // функцией только после исполнения скрипта, поэтому массив создаём сами.
  const w = window as unknown as { ym?: Array<[number, string, ...unknown[]]> }
  ;(w.ym ||= []).push([counterId, method, ...args])
}

/** UTM из текущего URL (hash-роутинг: ищем и в search, и в хеш-части). */
function collectUtm(): Record<string, string> {
  const out: Record<string, string> = {}
  if (typeof window === 'undefined') return out
  const sources = [window.location.search, window.location.hash.split('?')[1] ?? '']
  for (const src of sources) {
    new URLSearchParams(src).forEach((value, key) => {
      if (/^utm_/i.test(key) && value) out[key.toLowerCase()] = value
    })
  }
  try {
    const stored = JSON.parse(localStorage.getItem(UTM_STORAGE_KEY) ?? '{}')
    Object.assign(out, stored) // последняя известная кампания переживает переходы
  } catch {
    /* ignore */
  }
  if (Object.keys(out).length > 0) {
    try {
      localStorage.setItem(UTM_STORAGE_KEY, JSON.stringify(out))
    } catch {
      /* приватный режим — просто не сохраняем */
    }
  }
  return out
}

/**
 * Отправить событие воронки в Метрику как цель reachGoal (для Директа).
 *
 * @param event внутреннее имя события (ключ из config('metrika.goals'))
 * @param payload необязательные параметры (revenue/orderId для purchase)
 */
export async function trackEvent(event: string, payload: PurchasePayload = {}): Promise<void> {
  const c = await loadConfig()
  if (!c.enabled) return

  injectCounterScript(c)

  const goal = c.goals[event]
  const params: Record<string, unknown> = { ...collectUtm(), ep_page: event }

  if (event === 'payment_success' && payload.revenue !== undefined) {
    // Главная цель для Директа: доход по конверсии + идентификатор заказа
    // (дубли Protection against duplicates не нужны — у нас одна страница успеха).
    params.orderId = payload.orderId ?? undefined
    params.itemsCount = payload.items ?? undefined
    pushYm(c.counter_id, 'reachGoal', goal, { ...params, revenue: payload.revenue, currency: c.currency })
    return
  }

  if (goal) pushYm(c.counter_id, 'reachGoal', goal, params)
}

/** Просмотр страницы SPA: цели как событие + нативный хит Метрики через pageView. */
export function trackPageView(path: string): void {
  void trackEvent('page_view').catch(() => {})
  const c = config
  if (c?.enabled && typeof window !== 'undefined' && typeof window.ym === 'function') {
    // В SPA без pageView все «просмотры» склеятся в один хит на F5.
    window.ym(c.counter_id, 'hit', path)
  }
}

/** Синхронная инициализация при старте приложения: грузит конфиг и счётчик. */
export async function initMetrika(): Promise<void> {
  const c = await loadConfig()
  if (c.enabled) injectCounterScript(c)
}
