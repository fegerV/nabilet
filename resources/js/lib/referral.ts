/**
 * Реферальная программа «Клуба зрителей» (фаза 4 `docs/LOYALTY-PLAN.md`).
 *
 * ЗЕРКАЛО `Domain\ReferralCode`, А НЕ ВТОРАЯ РЕАЛИЗАЦИЯ
 *   Алфавит, длина и нормализация (trim + верхний регистр) повторяют
 *   `app/Modules/Loyalty/Domain/ReferralCode.php`: код приходит из ссылки и
 *   письма, его пересылают в мессенджерах и переписывают руками. Сервер решает,
 *   существует ли код и положена ли награда, — витрина лишь не теряет код по
 *   пути и не сохраняет заведомо мусорный.
 *
 * ПОЧЕМУ АТРИБУЦИЯ ЖИВЁТ В localStorage, А НЕ В COOKIE
 *   Cookie уходила бы на каждый запрос, включая статику и чужие домены в
 *   iframe (embed-виджет живёт внутри витрины и шлёт свои запросы). Код нужен
 *   РОВНО в одном месте — теле чекаута (`referral_code`), поэтому он
 *   хранится локально и отправляется только туда.
 *
 * ПОЧЕМУ КОД ПРИКРЕПЛЯЕТСЯ НАВСЕГДА, А НЕ НА СЕССИЮ
 *   Друга приглашают один раз, а решение о покупке он принимает в средний
 *   срок: ссылка открыта вчера, заказ оформлен через неделю. Срок жизни кода —
 *   до первой оплаты по приглашению, и это решает сервер
 *   (`ReferralService::attachToOrder()`), а не срок хранения в браузере.
 */
import { get } from './api'

/** Алфавит без визуально похожих пар: `0`/`O` и `1`/`I` исключены. */
export const REFERRAL_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'

/** Длина кода — как в `ReferralCode::LENGTH`. */
export const REFERRAL_LENGTH = 16

/** Ключ хранения: тот же стиль именования, что у токенов и темы. */
const STORAGE_KEY = 'nabilet_referral_code'

/**
 * Привести введённое к форме, в которой код ищет сервер.
 *
 * `trim` + верхний регистр: ` abc123 ` из мессенджера — это `ABC123`, а не
 * другой код. Всё остальное ( REGISTR, кириллица при ручном вводе) не
 * «чинится»: сервер такой код просто не найдёт и не наградит.
 */
export function normalizeReferralCode(raw: string): string {
  return raw.trim().toUpperCase()
}

/**
 * Похоже ли на код: длина и алфавит. Без обращения к серверу.
 *
 * Зачем проверять на клиенте: `localStorage` переживает перезагрузку, и
 * сохранённый однажды код приложится к ЧУЖОМУ заказу, если в той же вкладке
 * кто-то купит без приглашения. Храним только то, что вообще может быть кодом.
 */
export function isWellFormedReferralCode(code: string): boolean {
  if (code.length !== REFERRAL_LENGTH) return false
  for (const ch of code) {
    if (!REFERRAL_ALPHABET.includes(ch)) return false
  }
  return true
}

/**
 * Запомнить код приглашения. Возвращает сохранённый код или `null`.
 *
 * `null` — код не похож на настоящий (опечатка, мусор в query, ручной ввод):
 * молча ничего не сохраняем. Ломать покупку из-за битой ссылки нельзя, а
 * запоминать битый код бессмысленно — сервер его не узнает.
 */
export function rememberReferralCode(raw: unknown): string | null {
  if (typeof raw !== 'string') return null

  const code = normalizeReferralCode(raw)

  if (!isWellFormedReferralCode(code)) return null

  try {
    localStorage.setItem(STORAGE_KEY, code)
  } catch {
    // Приватный режим Safari: сохранение невозможно. Молча продолжаем —
    // приглашение просто не сработает, а витрина не должна падать.
    return null
  }

  return code
}

/** Запомненный код приглашения или `null`. */
export function rememberedReferralCode(): string | null {
  try {
    return localStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

/**
 * Забыть код приглашения — вызывается после его применения к заказу.
 *
 * Без этого второй заказ того же покупателя тащил бы код за собой: сервер,
 * правда, откажет (`uq_referrals_inviter_email` + проверка «уже награждён»),
 * но лишний запрос в базе и лишний повод для расхождений ни к чему.
 */
export function clearRememberedReferralCode(): void {
  try {
    localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Нечего чистить — значит и нет.
  }
}

/* ── «Пригласите друга» в кабинете ────────────────────────────────────────────
 * Один эндпоинт под `auth:api`: код, готовая ссылка, условия программы и
 * статистика приглашений. Всё, что показывает блок в клубе, приходит отсюда:
 * вёрстка не считает награды и не знает порогов — это знание сервера.
 * ────────────────────────────────────────────────────────────────────────── */

/** Схема ответа `GET /me/referral`. */
export interface ReferralInfo {
  /** Код приглашения (`referral_codes.code`). */
  code: string
  /** Готовая ссылка `/r/{code}` от `config('app.url')`. */
  link: string
  /** Включена ли реферальная программа (настройка админки). */
  enabled: boolean
  /** Награда приглашающему, минорные единицы. */
  inviter_bonus: number
  /** Награда другу, минорные единицы. */
  invited_bonus: number
  stats: {
    /** Сколько друзей оформило хотя бы один заказ по ссылке. */
    invited: number
    /** Сколько из них оплатило первый заказ (награда выплачена). */
    rewarded: number
    /** Сколько бонусов принесло приглашение, минорные единицы. */
    earned: number
  }
}

/**
 * `GET /me/referral` — код, ссылка и статистика.
 *
 * Гости сюда не попадают: путь под `auth:api`, поэтому блок приглашения живёт
 * в кабинете, а не на витрине. Числа приводятся к целым: суммы — деньги, и
 * `NaN` в блоке («Вы заработали NaN ₽») выглядит как поломка, а не сбой поля.
 */
export async function fetchReferral(): Promise<ReferralInfo> {
  const res = await get<Partial<ReferralInfo> | null>('/me/referral')

  const data = res.data ?? {}

  const toInt = (value: unknown): number => {
    const n = Number(value)
    return Number.isFinite(n) ? Math.trunc(n) : 0
  }

  return {
    code: String(data.code ?? ''),
    link: String(data.link ?? ''),
    enabled: Boolean(data.enabled),
    inviter_bonus: toInt(data.inviter_bonus),
    invited_bonus: toInt(data.invited_bonus),
    stats: {
      invited: toInt(data.stats?.invited),
      rewarded: toInt(data.stats?.rewarded),
      earned: toInt(data.stats?.earned),
    },
  }
}
