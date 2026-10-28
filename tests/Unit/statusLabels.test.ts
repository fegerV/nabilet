/**
 * Словарь статусов — один владелец, и это ПРОВЕРЯЕТСЯ, а не обещается.
 *
 * ЧТО ЗДЕСЬ ОХРАНЯЕТСЯ. `NStatusBadge` был объявленным владельцем подписей и
 * цветов, и его докблок обещал: «статус `awaiting_payment` везде «Ждём оплаты»
 * и везде одного цвета». Обещание держалось на честном слове — рядом жили
 * четыре копии, потому что владелец не давал взять ОДНУ подпись, не притащив
 * компонент:
 *
 *   - `AdminAnalyticsPage` — восемь подписей заказов, побайтово равных словарю;
 *   - `TicketCard` — пять подписей билетов плюс расхождение по `refunded`;
 *   - `SessionFormModal` и `AdminEventFormPage` — два одинаковых списка
 *     состояний сеанса, включая `held`, которого у сеанса НЕ СУЩЕСТВУЕТ (это
 *     состояние позиции инвентаря). Копия пережила исправление на бэкенде:
 *     администратор выбирал «Проведён», форма отправляла `held`, сервер отвечал
 *     422 тем самым правилом, которое из словаря это значение и убрало;
 *   - `lib/raffles.ts` — подписи плюс `raffleStatusClass()`, «тесная копия
 *     палитры»: `drawn` был покрашен в `indigo` из дефолтной палитры Tailwind,
 *     то есть мимо токенов проекта.
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ ФАЙЛ, А НЕ ДОПОЛНЕНИЕ К `statusBadge.test.ts`.
 *   `statusBadge.test.ts` сторожит РЕАКТИВНОСТЬ компонента (подпись обязана
 *   меняться при смене пропа). Здесь сторожится САМ СЛОВАРЬ: его значения,
 *   порядок опций, отображение тонов на `NBadge` и отсутствие в интерфейсе
 *   цветов мимо палитры проекта. Это разные поломки с разными причинами, и
 *   валить их в один файл значит потерять, какая из них случилась.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import {
  EVENT_STATUS,
  HALL_STATUS,
  ORDER_STATUS,
  PAYMENT_STATUS,
  RAFFLE_STATUS,
  SESSION_STATUS,
  TICKET_STATUS,
  USER_STATUS,
  VENUE_STATUS,
  findStatusMeta,
  statusLabel,
  statusMeta,
  statusOptions,
  type StatusKind,
} from '@/lib/statusLabels'
import config from '../../tailwind.config'

/**
 * Корень проекта — от рабочего каталога, а не от `import.meta.url`.
 *
 * Под jsdom `import.meta.url` приходит не файловым URL, и `fileURLToPath` на
 * нём падает с «The URL must be of scheme file». Vitest запускается из корня
 * проекта (и в CI тоже), поэтому `process.cwd()` — тот же корень без
 * зависимости от того, как окружение переписало URL модуля.
 */
const ROOT = process.cwd()

/** Все словари — чтобы проверки ниже не перечисляли их по одному. */
const VOCABULARIES: Array<{ kind: StatusKind; dictionary: Record<string, unknown> }> = [
  { kind: 'order', dictionary: ORDER_STATUS },
  { kind: 'ticket', dictionary: TICKET_STATUS },
  { kind: 'event', dictionary: EVENT_STATUS },
  { kind: 'session', dictionary: SESSION_STATUS },
  { kind: 'hall', dictionary: HALL_STATUS },
  { kind: 'venue', dictionary: VENUE_STATUS },
  { kind: 'payment', dictionary: PAYMENT_STATUS },
  { kind: 'user', dictionary: USER_STATUS },
  { kind: 'raffle', dictionary: RAFFLE_STATUS },
]

describe('словарь статусов', () => {
  it('не подписывает сеансу состояние `held` и знает все семь настоящих', () => {
    // `held` — состояние позиции инвентаря (`InventoryItemStateMachine::HELD`),
    // попавшее в словарь сеанса копированием из соседнего. В `ck_sessions_status`
    // его нет; бэкенд вычистил его в `SessionStateMachine::all()`, а фронтенд
    // остался на старом списке и предлагал администратору «Проведён» — запрос
    // получал 422. `draft`, `sold_out` и `closed` при этом были недостижимы.
    const values = statusOptions('session').map((option) => option.value)

    expect(values).not.toContain('held')
    expect(values).toEqual([
      'draft',
      'scheduled',
      'on_sale',
      'sold_out',
      'closed',
      'completed',
      'cancelled',
    ])
  })

  it('отдаёт опции формы в порядке жизненного цикла, а не по алфавиту', () => {
    // Порядок — часть правила: администратор читает список как последовательность
    // состояний. Алфавит поставил бы `cancelled` первым, а `scheduled` — последним.
    expect(statusOptions('session').map((option) => option.value)[0]).toBe('draft')
    expect(statusOptions('session').map((option) => option.value).at(-1)).toBe('cancelled')
  })

  it('не держит двух источников подписи: опция и бейдж читают одно и то же', () => {
    // Если `statusOptions` начнёт собирать подпись отдельно от `statusMeta`,
    // форма и таблица разойдутся ровно так же, как разошлись копии.
    for (const { kind, dictionary } of VOCABULARIES) {
      for (const value of Object.keys(dictionary)) {
        const option = statusOptions(kind).find((candidate) => candidate.value === value)

        expect(option, `${kind}/${value} есть в словаре, но не в опциях`).toBeDefined()
        expect(option?.label).toBe(statusLabel(kind, value))
      }
    }
  })

  it('различает «статуса нет в словаре» и «статус без подписи»', () => {
    // `findStatusMeta` отвечает `null` — это нужно подписи под QR билета: там
    // незнакомый код обязан оставить нейтральное «Покажите на входе», а не
    // вывалиться английской строкой на экран покупателя.
    expect(findStatusMeta('ticket', 'issued')).not.toBeNull()
    expect(findStatusMeta('ticket', 'something_new')).toBeNull()

    // `statusMeta` — запасной вариант для администратора: сырой код полезнее
    // пустой ячейки, потому что по нему можно искать в бэкенде.
    expect(statusMeta('ticket', 'something_new')).toEqual({
      label: 'something_new',
      tone: 'neutral',
      dot: false,
    })

    // Пустая строка — не статус: показываем прочерк, а не пустое место.
    expect(statusMeta('ticket', '').label).toBe('—')
  })

  it('использует только те тона, которые `NBadge` умеет рисовать', () => {
    // Тон — это ключ в `TONES` компонента `NBadge`. Неизвестный тон не падает:
    // `TONES[props.tone]` даёт `undefined`, и в `class` уезжает строка
    // «undefined» — плашка теряет фон МОЛЧА, без единой ошибки в консоли.
    // Сторож читает `TONES` из самого компонента, а не из копии списка: копия
    // разошлась бы ровно так же, как разошлись копии словаря.
    const nbadge = readFileSync(join(ROOT, 'resources/js/components/ui/NBadge.vue'), 'utf8')
    const tonesBlock = /const TONES: Record<Tone, string> = \{([\s\S]*?)\n\}/.exec(nbadge)?.[1] ?? ''
    const known = new Set(
      [...tonesBlock.matchAll(/^\s{2}(\w+):/gm)].map((match) => match[1]),
    )

    expect(known.size).toBeGreaterThan(0)

    for (const { kind, dictionary } of VOCABULARIES) {
      for (const [value, meta] of Object.entries(dictionary as Record<string, { tone: string }>)) {
        expect(known, `${kind}/${value}: тон «${meta.tone}» не рисуется`).toContain(meta.tone)
      }
    }
  })

  it('не выходит за семантическую палитру проекта', () => {
    // ЧЕСТНО О ГРАНИЦЕ, КОТОРУЮ ЗДЕСЬ СТОРОЖАТ.
    // `theme.extend.colors` РАСШИРЯЕТ дефолтную палитру Tailwind, а не заменяет
    // её, поэтому `bg-amber-500` и `text-indigo-300` рисуются нормально — это
    // НЕ «молча выброшенный класс». Проблема другая: такие цвета обходят
    // словарь проекта, где за каждым смыслом закреплён свой токен (`sun` —
    // ожидание, `mint` — успех, `rose` — опасность, `brand` — акцент, `sky` —
    // информация). Пока правило соблюдалось на глаз, в трёх местах появились
    // цвета из дефолтной палитры: «Разыгран» был синим, предупреждение в логах
    // и «разные цены» — янтарными. Всё работало, но один и тот же смысл
    // выглядел по-разному, а `NBadge` в тех же местах терял `ring`.
    //
    // Проверяются только имена из СТАНДАРТНОЙ палитры Tailwind: семантические
    // токены проекта (`content`, `surface`, `line`, `muted`, `subtle`) заданы
    // руками в `resources/css/app.css` и в дефолтную палитру не входят, поэтому
    // ложных срабатываний не дают. `white`/`black`/`transparent`/`current` — не
    // цвета палитры, а ключевые слова, их проверка не касается.
    //
    // Вне области: `app/Modules/Installer/…/install.blade.php`. Это экран ДО
    // установки, он живёт без темы приложения и намеренно пользуется дефолтной
    // палитрой — проверка сканирует `resources/js`, поэтому он не задет.
    const DEFAULT_FAMILIES = new Set([
      'slate', 'gray', 'zinc', 'neutral', 'stone', 'red', 'orange', 'amber',
      'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue',
      'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose',
    ])

    const configured = new Set(Object.keys(config.theme?.extend?.colors ?? {}))
    expect(configured.size).toBeGreaterThan(0)

    const utility =
      /(?:^|[\s"'`:])(?:bg|text|ring|ring-offset|border|from|to|via|fill|stroke|divide|outline|placeholder|caret|decoration|accent|shadow)-([a-z]+)(?:-\d{2,3})?\b/g

    const offenders: string[] = []

    for (const file of walk(join(ROOT, 'resources/js'))) {
      // Комментарии выбрасываем: в докблоках эти же классы цитируются как
      // история, и сторож не должен матчить собственное объяснение.
      const code = readFileSync(file, 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\/[^\n]*/g, '')

      for (const match of code.matchAll(utility)) {
        const family = match[1]

        if (DEFAULT_FAMILIES.has(family) && !configured.has(family)) {
          offenders.push(`${file.slice(ROOT.length)}: ${match[0].trim()}`)
        }
      }
    }

    expect(offenders, 'цвет мимо семантической палитры проекта').toEqual([])
  })
})

/** Все `.ts`/`.vue` под каталогом. Симлинков в проекте нет — рекурсия простая. */
function walk(directory: string): string[] {
  const files: string[] = []

  for (const entry of readdirSync(directory)) {
    const path = join(directory, entry)

    if (statSync(path).isDirectory()) {
      files.push(...walk(path))
    } else if (path.endsWith('.ts') || path.endsWith('.vue')) {
      files.push(path)
    }
  }

  return files
}
