/**
 * Словарь статусов фронтенда обязан совпадать со словарём бэкенда.
 *
 * ЗАЧЕМ. Копия словаря расходится с владельцем не сразу, а через одну правку —
 * и расходится молча, потому что сравнивать два экрана никто не станет. Ровно
 * это и случилось с состояниями сеанса: бэкенд вычистил `held` из
 * `SessionStateMachine::all()` (это состояние позиции инвентаря, в
 * `ck_sessions_status` его нет) и вернул `draft`, `sold_out`, `closed`, — а на
 * фронтенде список остался прежним. Администратор выбирал «Проведён», форма
 * отправляла `held`, и сервер отвечал 422 тем самым правилом, которое это
 * значение из словаря и убрало.
 *
 * ПОЧЕМУ ТЕСТ, А НЕ ГЕЙТ. `tools/verify-status-literals.php` уже стережёт
 * PHP-сторону: он берёт словарь из класса через reflection и запрещает
 * литералы рядом с `status` в `app/`. Про фронтенд он не знает ничего — и не
 * должен: гейт читает PHP-токены. Мост между двумя языками — этот файл: он
 * читает классы-владельцы с диска и сверяет множества значений.
 *
 * ЧТО НЕ ПОКРЫТО И ПОЧЕМУ.
 *   - `payments.status`: колонка допускает пять значений (`ck_payments_status`),
 *     а машина знает те же пять, но фронтенд рисует ещё `refunded` и
 *     `partially_refunded` — это состояния ВОЗВРАТА из `refunds.status`, другой
 *     таблицы. Сверять словарь платежа с машиной значило бы запретить бейджу
 *     показывать возврат.
 *   - `halls.status` / `venues.status`: PHP-класса-владельца нет вовсе, словарь
 *     объявлен только в схеме. Сверять не с чем — и это отдельный, уже
 *     записанный долг, а не то, что можно закрыть здесь.
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import {
  EVENT_STATUS,
  ORDER_STATUS,
  RAFFLE_STATUS,
  SESSION_STATUS,
  TICKET_STATUS,
  USER_STATUS,
} from '@/lib/statusLabels'

/**
 * Значения `public const` из класса-владельца.
 *
 * Читаются ИМЕННО КОНСТАНТЫ, а не список внутри `all()`: константа — то, чем
 * класс объявляет словарь, и `all()` в этом проекте собирается из них же.
 * Комментарии выбрасываются: в докблоках эти значения цитируются как история
 * («`held` — состояние позиции инвентаря»), и сторож не должен матчить
 * собственное объяснение.
 */
function constantsOf(relativePath: string): string[] {
  const source = readFileSync(fileURLToPath(new URL(relativePath, import.meta.url)), 'utf8')
  const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '')

  return [...code.matchAll(/public const\s+[A-Z_]+\s*=\s*'([a-z_]+)'\s*;/g)].map((match) => match[1])
}

const PARITY = [
  {
    column: 'orders.status',
    owner: '../../app/Modules/Orders/StateMachines/OrderStateMachine.php',
    dictionary: ORDER_STATUS,
  },
  {
    column: 'tickets.status',
    owner: '../../app/Modules/Tickets/StateMachines/TicketStateMachine.php',
    dictionary: TICKET_STATUS,
  },
  {
    column: 'events.status',
    owner: '../../app/Modules/Events/Domain/EventStatus.php',
    dictionary: EVENT_STATUS,
  },
  {
    column: 'sessions.status',
    owner: '../../app/Modules/Sessions/StateMachines/SessionStateMachine.php',
    dictionary: SESSION_STATUS,
  },
  {
    column: 'raffles.status',
    owner: '../../app/Modules/Loyalty/StateMachines/RaffleStateMachine.php',
    dictionary: RAFFLE_STATUS,
  },
  {
    column: 'users.status',
    owner: '../../app/Modules/Core/Users/Domain/UserStatus.php',
    dictionary: USER_STATUS,
  },
] as const

describe('словарь статусов фронтенда совпадает с бэкендом', () => {
  for (const { column, owner, dictionary } of PARITY) {
    it(`${column}: те же значения, что объявляет владелец`, () => {
      const php = constantsOf(owner)

      // Владелец обязан что-то объявлять: пустой разбор означал бы, что тест
      // сравнивает словарь с пустым множеством и всегда «проходит».
      expect(php.length, `${owner}: константы не разобрались`).toBeGreaterThan(0)

      // Сравнение множеств, а не порядка: порядок объявления констант — дело
      // владельца, а порядок опций формы задаётся словарём фронтенда отдельно.
      expect(Object.keys(dictionary).sort()).toEqual(php.sort())
    })
  }

  it('сеанс не предлагает `held` — этого состояния у сеанса не существует', () => {
    // Именная проверка к общей выше: если однажды кто-то «починит» паритет,
    // добавив `held` в PHP-машину, этот тест скажет, что чинили не то.
    expect(constantsOf('../../app/Modules/Sessions/StateMachines/SessionStateMachine.php')).not.toContain(
      'held',
    )
  })
})
