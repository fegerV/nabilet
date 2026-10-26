/**
 * Соц-вход на витрине (ТЗ §17).
 *
 * ЧТО ЗДЕСЬ ЕСТЬ И ЧЕГО ЗДЕСЬ НЕТ
 *
 *   Фронт НЕ собирает authorize-URL и НЕ знает ни `client_id`, ни адресов
 *   провайдеров. Он уводит браузер на наш `/auth/{provider}/start` — там сервер
 *   выдаёт `state` (в HttpOnly-куку) и сам редиректит к провайдеру. Причина
 *   простая: `client_id` и `redirect_uri` обязаны совпадать с теми, что уйдут в
 *   обмен кода, а «включён ли провайдер» решается конфигурацией сервера. Собери
 *   фронт URL сам — и однажды конфиг и бандл разъедутся, а человек получит
 *   `redirect_uri mismatch` уже после ввода пароля.
 *
 *   Обратный путь тоже серверный: провайдер возвращает браузер на
 *   `/auth/{provider}/callback`, сервер проверяет `state`, находит или заводит
 *   аккаунт и редиректит на `#/auth/callback?token=…` либо `?error=…`. Здесь
 *   остаётся только принять токен — см. `completeSocialLogin()`.
 *
 * ПОЧЕМУ ТОКЕН ПРИНИМАЕТСЯ ИЗ ХЭША, А НЕ ИЗ QUERY
 *
 *   `#/auth/callback?token=…` не уходит на сервер: фрагмент остаётся в браузере.
 *   Значит, токен не попадает ни в логи прокси, ни в `Referer` при переходе по
 *   любой ссылке со страницы. Поэтому же после приёма страница делает
 *   `router.replace` — чтобы токен не остался в истории.
 */
import { request, setCustomerToken } from './api'
import { saveCustomerUser, type CustomerUser } from './customer'

/** Провайдеры, у которых есть OAuth-переход. Telegram работает иначе. */
export type SocialProviderId = 'yandex' | 'vk'

export interface SocialProviderOption {
  id: SocialProviderId
  label: string
}

/**
 * Кнопки на экране входа.
 *
 * Список ЗДЕСЬ — только порядок и подписи. Включённость решает сервер: если
 * провайдер не настроен, `/auth/{provider}/start` вернёт браузер с
 * `error=provider_not_configured`, и человек увидит внятное сообщение, а не
 * «страница не найдена». Дублировать конфигурацию во фронте (через переменные
 * сборки) значило бы держать второй источник правды о том, что включено.
 */
export const SOCIAL_PROVIDERS: readonly SocialProviderOption[] = [
  { id: 'yandex', label: 'Яндекс' },
  { id: 'vk', label: 'VK' },
]

/** Ключ, под которым запоминается «куда вернуться после входа». */
const RETURN_KEY = 'nabilet_social_return'

/**
 * Запомнить, куда вернуть покупателя после входа.
 *
 * Только внутренний путь: `//evil.example` увёл бы человека на чужой сайт
 * сразу после успешного входа — та же проверка, что у `?redirect=` на форме
 * пароля, и по той же причине.
 */
export function rememberSocialReturn(path: unknown): void {
  if (typeof path !== 'string' || !path.startsWith('/') || path.startsWith('//')) return

  try {
    sessionStorage.setItem(RETURN_KEY, path)
  } catch {
    /* приватный режим — вернёмся на кабинет по умолчанию */
  }
}

/** Куда вернуться после входа; по умолчанию — кабинет. */
export function socialReturnPath(): string {
  try {
    const saved = sessionStorage.getItem(RETURN_KEY)
    sessionStorage.removeItem(RETURN_KEY)
    if (saved && saved.startsWith('/') && !saved.startsWith('//')) return saved
  } catch {
    /* ignore */
  }

  return '/account'
}

/**
 * Начать OAuth-вход: увести браузер на серверный `/start`.
 *
 * `window.location.assign`, а не `router.push`: дальше начинается обычная
 * навигация по чужим доменам (провайдер), и SPA-роутер здесь ни при чём.
 */
export function startSocialLogin(provider: SocialProviderId, returnTo?: string): void {
  rememberSocialReturn(returnTo)
  window.location.assign(`/auth/${provider}/start`)
}

/**
 * Принять токен, выданный callback'ом, и загрузить профиль.
 *
 * Профиль читается сразу: без него шапка витрины показывала бы «войти» до
 * перезагрузки, а кабинет — пустые поля. Токен ставится ДО запроса — иначе
 * `/me` ушёл бы без `Authorization`.
 */
export async function completeSocialLogin(token: string): Promise<CustomerUser> {
  setCustomerToken(token)

  const res = await request<{ data: CustomerUser }>('/me', { as: 'customer' })
  saveCustomerUser(res.data)

  return res.data
}

/**
 * Понятный текст по коду ошибки из `?error=` callback'а.
 *
 * Коды приходят от нашего же сервера (`SocialAuthController::redirectWithError`),
 * поэтому это закрытый словарь, а не свободная строка: неизвестный код — общий
 * текст, а не «показать как есть».
 */
export function socialErrorText(code: string | null): string {
  switch (code) {
    case 'provider_not_configured':
      return 'Этот способ входа сейчас недоступен. Войдите по e-mail и паролю.'
    case 'unknown_provider':
      return 'Такой способ входа не поддерживается.'
    case 'missing_code':
      return 'Провайдер не вернул код подтверждения. Попробуйте войти ещё раз.'
    case 'invalid_state':
      return 'Вход устарел или был начат в другом браузере. Попробуйте ещё раз.'
    case 'exchange_failed':
      return 'Не удалось подтвердить вход у провайдера. Попробуйте ещё раз.'
    default:
      return 'Не удалось войти. Попробуйте ещё раз или войдите по e-mail и паролю.'
  }
}

/* ── Telegram ─────────────────────────────────────────────────────────────
 * Telegram не участвует в OAuth-переходе: Mini App уже открыт внутри клиента,
 * и `initData` лежит в `window.Telegram.WebApp`. Сервер проверяет подпись
 * (`TelegramInitData`) и отвечает тем же `AuthResponse`, что и парольный вход.
 *
 * `initDataUnsafe` НЕ используется: он не подписан, и доверять ему нельзя —
 * именно об этом предупреждает документация Telegram и требует спека.
 */
interface TelegramWebApp {
  initData?: string
}

interface TelegramGlobal {
  WebApp?: TelegramWebApp
}

function telegramWebApp(): TelegramWebApp | null {
  const global = window as unknown as { Telegram?: TelegramGlobal }
  return global.Telegram?.WebApp ?? null
}

/** Подписанный `initData`, если страница открыта внутри Telegram. */
export function telegramInitData(): string | null {
  const data = telegramWebApp()?.initData
  return typeof data === 'string' && data !== '' ? data : null
}

/** Открыта ли витрина внутри Telegram Mini App. */
export function isTelegramMiniApp(): boolean {
  return telegramInitData() !== null
}

/** Вход по `initData` Telegram. */
export async function loginWithTelegram(initData: string): Promise<CustomerUser> {
  const res = await request<{ data: { user: CustomerUser; token: string; token_type: string } }>(
    '/telegram/auth',
    { method: 'POST', body: { init_data: initData } },
  )

  setCustomerToken(res.data.token)
  saveCustomerUser(res.data.user)

  return res.data.user
}
