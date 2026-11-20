/**
 * Запасные каналы продаж для встраиваемого виджета схемы зала.
 *
 * Чистая логика вынесена из Vue-компонента, чтобы её можно было проверить
 * Vitest без монтирования: от того, что окажется в `href`, зависит, сможет ли
 * покупатель дозвониться/написать, когда сервер продаж недоступен.
 *
 * Источник данных — `storefront.config.support` (настройки организации).
 */
import type { SupportConfig } from '@/lib/storefront'

export type SupportActionKind = 'call' | 'telegram' | 'whatsapp' | 'email'

export interface SupportAction {
  kind: SupportActionKind
  label: string
  href: string
}

/** tel: — только цифры и плюс; пробелы/скобки/дефисы мешают набору на телефоне. */
export function telHref(phone: string): string {
  const digits = phone.replace(/[^\d+]/g, '')
  return digits.length >= 5 ? `tel:${digits}` : ''
}

/** http(s)-ссылку можно смело ставить в href; остальное отбрасываем. */
function safeHttpUrl(value: string): string {
  const url = value.trim()
  return /^https?:\/\//i.test(url) ? url : ''
}

/** Кнопки в порядке «главный канал → запасной». */
export function supportActions(support: SupportConfig | undefined): SupportAction[] {
  if (!support) return []

  const actions: SupportAction[] = []
  const phone = telHref(support.phone ?? '')
  if (phone) actions.push({ kind: 'call', label: 'Позвонить', href: phone })

  const telegram = safeHttpUrl(support.telegram ?? '')
  if (telegram) actions.push({ kind: 'telegram', label: 'Написать в Telegram', href: telegram })

  const whatsapp = safeHttpUrl(support.whatsapp ?? '')
  if (whatsapp) actions.push({ kind: 'whatsapp', label: 'Написать в WhatsApp', href: whatsapp })

  const email = (support.email ?? '').trim()
  if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && email.length <= 255) {
    actions.push({ kind: 'email', label: 'Написать на e-mail', href: `mailto:${email}` })
  }

  return actions
}

/** Сообщение по умолчанию, если конфиг ещё не доехал с сервера. */
export const FALLBACK_MESSAGE = 'Не удалось загрузить схему зала. Позвоните или напишите — билет подберём вручную.'
