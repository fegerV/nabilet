/**
 * Локальный рендер QR — единственная точка генерации кода в проекте.
 *
 * ПОЧЕМУ ЭТОТ МОДУЛЬ СУЩЕСТВУЕТ
 *
 * До него QR рисовался внешним сервисом:
 *
 *     https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<payload>
 *
 * `qr_payload` билета — это `NB1.<publicId>.<token>.<signature>` (QrSigner §30),
 * то есть ПОДПИСАННЫЙ ТОКЕН ВХОДА: кто его получил, тот проходит по билету.
 * Отправляя его в query-параметре чужому сервису, приложение отдавало третьей
 * стороне не только сам токен, но и IP/Referer/User-Agent покупателя, а также
 * позволяло накапливать чужие билеты в логах и кэшах CDN. Это тот же класс
 * утечки, что и открытый `GET /tickets/{id}/qr`, который уже закрыли.
 *
 * Поэтому кодирование выполняется в браузере библиотекой `qrcode`. Данные
 * не покидают процесс: ни `fetch`, ни `<img src="https://...">`, ни
 * `navigator.sendBeacon`. Проверяется тестом `tests/Unit/TicketBuilder/qr.test.ts`,
 * который подменяет сетевые примитивы и падает при любом обращении наружу.
 *
 * ЧТО ИМЕННО ВЫБРАНО
 *
 * `toDataURL` (PNG через canvas) — для экрана: `<img>` с data-URI работает
 * везде и не требует blob-URL, который надо не забыть отозвать.
 *
 * `toString({type:'svg'})` — для писем и печати: вектор не мылится, не зависит
 * от devicePixelRatio и, в отличие от canvas, доступен в среде без DOM
 * (например, в тестах или при серверной сборке письма).
 */

import QRCode from 'qrcode'

/** Уровни коррекции ошибок, поддерживаемые библиотекой. */
export type QrErrorCorrection = 'L' | 'M' | 'Q' | 'H'

export interface QrRenderOptions {
  /** Сторона квадрата в пикселях (SVG — во внутренних единицах viewBox). */
  width?: number
  /** Тихая зона в модулях. 0 — без полей. */
  margin?: number
  /**
   * Уровень коррекции. `M` (~15 %) — разумный компромисс: билет печатают на
   * бумаге и показывают с треснувшего экрана, а `H` (~30 %) заметно увеличивает
   * плотность кода и мешает сканеру на маленьком размере.
   */
  errorCorrectionLevel?: QrErrorCorrection
  /** Цвет модулей. */
  dark?: string
  /** Цвет фона. */
  light?: string
}

/**
 * Значения по умолчанию.
 *
 * Тёмный код на белом фоне, а не инверсия: сканеры читают тёмное-на-светлом
 * надёжнее, и это единственная причина, по которой QR не перекрашивается
 * в тёмную тему (см. `TicketCard.vue`).
 */
export const QR_DEFAULT_OPTIONS: Required<QrRenderOptions> = {
  width: 320,
  margin: 1,
  errorCorrectionLevel: 'M',
  dark: '#120F24',
  light: '#FFFFFF',
}

/**
 * Что подставляется в предпросмотр, когда у элемента нет данных.
 *
 * Это ЗАГЛУШКА ДЛЯ МАКЕТА, а не билет: она печатается в конструкторе, где
 * администратор двигает элемент и смотрит на размеры. В настоящий билет
 * подставляется реальный `qr_payload` (см. `TicketCard.vue`).
 */
export const QR_PREVIEW_FALLBACK = 'https://nabilet.ru/ticket/preview'

/**
 * Хосты, куда подписанный QR-токен уходить НЕ ДОЛЖЕН.
 *
 * Список нужен двум вещам: тесту (падает, если такой хост вернётся в код) и
 * человеку, который читает код и хочет понять, что именно запрещено.
 * `api.qrserver.com` — историческая утечка, остальные — типовые сервисы,
 * которыми её обычно «чинят».
 */
export const FORBIDDEN_QR_HOSTS: readonly string[] = [
  'api.qrserver.com',
  'chart.googleapis.com',
  'quickchart.io',
  'api.qr-code-generator.com',
  'qrserver.com',
  'goqr.me',
]

/** Ссылается ли адрес на внешний сервис генерации изображений. */
export function isForbiddenQrSource(source: string): boolean {
  const lower = source.toLowerCase()
  return FORBIDDEN_QR_HOSTS.some((host) => lower.includes(host))
}

/**
 * PNG в виде data-URI — для `<img>` на экране.
 *
 * Возвращает `Promise`, потому что canvas в браузере рисуется асинхронно;
 * вызывающий код обязан учитывать, что быстрый повторный вызов может
 * завершиться раньше предыдущего (см. счётчик поколений в `TicketCard.vue`).
 *
 * @throws если `data` пуста — молча отдавать QR «ни о чём» нельзя: пустой
 *         код выглядит рабочим, но на входе по нему не пройти.
 */
export async function renderQrDataUrl(
  data: string,
  options: QrRenderOptions = {},
): Promise<string> {
  if (data.trim() === '') {
    throw new Error('Нельзя построить QR без данных: пустой код неотличим от рабочего.')
  }

  return QRCode.toDataURL(data, { ...QR_DEFAULT_OPTIONS, ...options })
}

/**
 * SVG-строка — для писем, печати и любой среды без DOM.
 *
 * Canvas здесь не нужен, поэтому функция работает и в Node (тесты, сборка
 * письма на сервере). Тег `<svg>` не содержит внешних ссылок: `qrcode`
 * генерирует только `<rect>`/`<path>`.
 */
export async function renderQrSvg(data: string, options: QrRenderOptions = {}): Promise<string> {
  if (data.trim() === '') {
    throw new Error('Нельзя построить QR без данных: пустой код неотличим от рабочего.')
  }

  return QRCode.toString(data, {
    ...QR_DEFAULT_OPTIONS,
    ...options,
    type: 'svg',
  })
}
