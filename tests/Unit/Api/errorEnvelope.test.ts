/**
 * Разбор §66-конверта ошибки в клиенте.
 *
 * Здесь сторожится конкретный продовый дефект: сервер отдаёт карту ошибок
 * по полям на пути `error.details.fields` (`ValidationError::toResponse()`),
 * а `request()` читал `error.errors` — поле, которого сервер не отправляет.
 * Из-за этого `ApiError.details` всегда был `null`, и серверные ошибки
 * валидации (e-mail, телефон на оформлении) не доходили до инпутов вообще:
 * покупатель видел общую плашку «Оплата не началась» без объяснения.
 *
 * `upload()` при этом читал правильный путь — то есть корневой причиной было
 * дублирование парсинга. Тесты фиксируют ЕДИНЫЙ контракт для обоих путей.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, request, upload } from '@/lib/api'

function stubFetch(status: number, payload: unknown): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => new Response(JSON.stringify(payload), {
      status,
      headers: { 'Content-Type': 'application/json' },
    })),
  )
}

async function captureError(run: () => Promise<unknown>): Promise<ApiError> {
  try {
    await run()
  } catch (error) {
    if (error instanceof ApiError) return error
    throw error
  }
  throw new Error('ожидалась ApiError, но запрос завершился успешно')
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('request(): конверт §66', () => {
  it('читает карту полей из error.details.fields', async () => {
    stubFetch(422, {
      error: {
        code: 'VALIDATION_ERROR',
        message: 'Проверьте данные',
        details: { fields: { customer_email: ['Нужен e-mail'] } },
      },
    })

    const err = await captureError(() => request('/cart/checkout', { method: 'POST' }))

    expect(err.status).toBe(422)
    expect(err.code).toBe('VALIDATION_ERROR')
    expect(err.message).toBe('Проверьте данные')
    expect(err.details).toEqual({ customer_email: ['Нужен e-mail'] })
  })

  it('принимает плоскую карту полей в error.details', async () => {
    stubFetch(422, {
      error: { code: 'VALIDATION_ERROR', message: 'Плохо', details: { phone: ['11 цифр'] } },
    })

    const err = await captureError(() => request('/cart/items', { method: 'POST' }))

    expect(err.details).toEqual({ phone: ['11 цифр'] })
  })

  it('понимает устаревшую форму error.errors', async () => {
    stubFetch(422, {
      error: { code: 'VALIDATION_ERROR', message: 'Плохо', errors: { name: ['Укажите имя'] } },
    })

    const err = await captureError(() => request('/cart/items', { method: 'POST' }))

    expect(err.details).toEqual({ name: ['Укажите имя'] })
  })

  it('достаёт машинный код из конверта (CART_EXPIRED — тоже 409)', async () => {
    stubFetch(409, {
      error: { code: 'CART_EXPIRED', message: 'Корзина истекла', details: null },
    })

    const err = await captureError(() => request('/cart/items', { method: 'POST' }))

    // Ветка UI выбирается по КОДУ, а не по статусу: 409 бывает и у «место занято».
    expect(err.code).toBe('CART_EXPIRED')
    expect(err.status).toBe(409)
    expect(err.details).toBeNull()
  })

  it('не падает на не-JSON ответе и сохраняет статус', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('<html>502</html>', { status: 502 })))

    const err = await captureError(() => request('/cart'))

    expect(err.status).toBe(502)
    expect(err.message).toBe('HTTP 502')
    expect(err.details).toBeNull()
  })
})

describe('upload(): тот же контракт, что у request()', () => {
  it('разбирает details.fields так же, как request', async () => {
    stubFetch(422, {
      error: {
        code: 'VALIDATION_ERROR',
        message: 'Проверьте данные',
        details: { fields: { poster_file: ['Слишком большой файл'] } },
      },
    })

    const err = await captureError(() => upload('/events', new FormData()))

    expect(err.details).toEqual({ poster_file: ['Слишком большой файл'] })
  })
})
