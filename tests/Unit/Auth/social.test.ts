/**
 * Соц-вход на клиенте: куда возвращаемся и что показываем при отказе.
 *
 * ЧТО ЗДЕСЬ СТОРОЖИТСЯ
 *
 *   1. OPEN REDIRECT. `?redirect=` на форме пароля принимает только внутренний
 *      путь — та же проверка нужна и здесь, потому что «куда вернуться после
 *      входа» тоже приходит из адреса. `//evil.example` браузер трактует как
 *      протокол-относительный URL, то есть уводит на чужой сайт сразу после
 *      успешного входа; `javascript:` — исполняет код. Обе формы обязаны
 *      отбрасываться, а не «как-нибудь кодироваться».
 *
 *   2. СЛОВАРЬ ОШИБОК. Коды приходят от нашего сервера
 *      (`SocialAuthController::redirectWithError`), поэтому это закрытый список.
 *      Неизвестный код обязан давать общий текст, а не подставляться в интерфейс
 *      как есть: строка из URL — это чужой ввод, и показывать её покупателю
 *      нельзя.
 *
 *   3. ОДНОРАЗОВОСТЬ «КУДА ВЕРНУТЬСЯ». Путь читается и сразу стирается:
 *      иначе следующий вход в той же вкладке уводил бы не туда, куда человек
 *      шёл сейчас, а туда, куда он шёл в прошлый раз.
 */
import { beforeEach, describe, expect, it } from 'vitest'
import { rememberSocialReturn, socialErrorText, socialReturnPath } from '@/lib/social'

describe('socialReturnPath', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('по умолчанию ведёт в кабинет', () => {
    expect(socialReturnPath()).toBe('/account')
  })

  it('возвращает сохранённый внутренний путь', () => {
    rememberSocialReturn('/tickets?tab=past')
    expect(socialReturnPath()).toBe('/tickets?tab=past')
  })

  it('стирает сохранённый путь после чтения', () => {
    rememberSocialReturn('/account')
    socialReturnPath()
    expect(socialReturnPath()).toBe('/account')
    expect(sessionStorage.getItem('nabilet_social_return')).toBeNull()
  })

  it('отбрасывает протокол-относительный путь', () => {
    rememberSocialReturn('//evil.example/steal')
    expect(socialReturnPath()).toBe('/account')
  })

  it('отбрасывает абсолютный внешний адрес', () => {
    rememberSocialReturn('https://evil.example/steal')
    expect(socialReturnPath()).toBe('/account')
  })

  it('отбрасывает javascript:-URL', () => {
    rememberSocialReturn('javascript:alert(1)')
    expect(socialReturnPath()).toBe('/account')
  })

  it('отбрасывает не-строку', () => {
    rememberSocialReturn(undefined)
    rememberSocialReturn(null)
    rememberSocialReturn(42)
    expect(socialReturnPath()).toBe('/account')
  })
})

describe('socialErrorText', () => {
  it('объясняет каждый код, который отдаёт сервер', () => {
    const codes = [
      'provider_not_configured',
      'unknown_provider',
      'missing_code',
      'invalid_state',
      'exchange_failed',
    ]

    for (const code of codes) {
      const text = socialErrorText(code)
      expect(text.length).toBeGreaterThan(0)
      // Код — не текст для покупателя: он не должен просачиваться в интерфейс.
      expect(text).not.toContain(code)
    }
  })

  it('на неизвестный код отвечает общим текстом, а не подставляет его', () => {
    const text = socialErrorText('<script>alert(1)</script>')

    expect(text).not.toContain('<script>')
    expect(text).toContain('Не удалось войти')
  })

  it('на отсутствующий код отвечает общим текстом', () => {
    expect(socialErrorText(null)).toContain('Не удалось войти')
  })
})
