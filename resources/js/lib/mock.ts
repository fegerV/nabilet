/**
 * Демонстрационные данные витрины и админки.
 *
 * Никаких внешних изображений: постер события — градиент плюс типографика.
 * Это не «заглушка вместо картинки», а осознанное правило: обложка приходит из
 * медиатеки с произвольной яркостью, поэтому интерфейс обязан читаться и на
 * градиенте — так карточка не разваливается, когда организатор загрузит
 * неудачный постер.
 */
import type { EventCard, OrderRow, TicketCard } from './types'

function iso(daysFromNow: number, hour: number, minute = 0): string {
  const d = new Date()
  d.setDate(d.getDate() + daysFromNow)
  d.setHours(hour, minute, 0, 0)
  return d.toISOString()
}

export const EVENTS: EventCard[] = [
  {
    id: 'ev-1',
    title: 'Ночная симфония: Вивальди и Макс Рихтер',
    subtitle: 'Оркестр «Северная столица»',
    category: 'Классика',
    venue: 'Большой зал филармонии',
    city: 'Санкт-Петербург',
    posterFrom: '#4921C9',
    posterTo: '#FF5C22',
    posterAccent: '#FFC53D',
    priceFromMinor: 180000,
    status: 'published',
    sessionsCount: 3,
    sessions: [
      { id: 'ses-11', startsAt: iso(3, 19, 30), hall: 'Большой зал', availableSeats: 184 },
      { id: 'ses-12', startsAt: iso(4, 19, 30), hall: 'Большой зал', availableSeats: 96 },
      { id: 'ses-13', startsAt: iso(5, 17, 0), hall: 'Большой зал', availableSeats: 240 },
    ],
  },
  {
    id: 'ev-2',
    title: 'Stand-up: открытый микрофон',
    subtitle: 'Шесть комиков, один вечер',
    category: 'Стендап',
    venue: 'Клуб «Подвал»',
    city: 'Москва',
    posterFrom: '#0B0918',
    posterTo: '#FF5C22',
    posterAccent: '#FF7F4D',
    priceFromMinor: 90000,
    status: 'published',
    sessionsCount: 2,
    sessions: [
      { id: 'ses-21', startsAt: iso(1, 20, 0), hall: 'Основной зал', availableSeats: 42 },
      { id: 'ses-22', startsAt: iso(8, 20, 0), hall: 'Основной зал', availableSeats: 60 },
    ],
  },
  {
    id: 'ev-3',
    title: 'Электронная ночь: Live Set',
    subtitle: 'Четыре диджея до утра',
    category: 'Электроника',
    venue: 'Крокус Сити Холл',
    city: 'Москва',
    posterFrom: '#2F1780',
    posterTo: '#2AA3FF',
    posterAccent: '#8AC8FF',
    priceFromMinor: 250000,
    status: 'published',
    sessionsCount: 1,
    sessions: [{ id: 'ses-31', startsAt: iso(12, 22, 0), hall: 'Главная сцена', availableSeats: 512 }],
  },
  {
    id: 'ev-4',
    title: 'Детский спектакль «Снежная королева»',
    subtitle: 'Театр «Кукольный дом»',
    category: 'Детям',
    venue: 'Театр «Кукольный дом»',
    city: 'Казань',
    posterFrom: '#0A66AE',
    posterTo: '#00C48C',
    posterAccent: '#A5F5DE',
    priceFromMinor: 70000,
    status: 'sold_out',
    sessionsCount: 4,
    sessions: [
      { id: 'ses-41', startsAt: iso(2, 11, 0), hall: 'Малый зал', availableSeats: 0 },
      { id: 'ses-42', startsAt: iso(2, 15, 0), hall: 'Малый зал', availableSeats: 0 },
    ],
  },
  {
    id: 'ev-5',
    title: 'Кино под открытым небом: ретроспектива',
    subtitle: 'Пять фильмов на большом экране',
    category: 'Кино',
    venue: 'Летняя площадка «Ракушка»',
    city: 'Сочи',
    posterFrom: '#7D250A',
    posterTo: '#FFC53D',
    posterAccent: '#FFE4A8',
    priceFromMinor: 60000,
    status: 'published',
    sessionsCount: 5,
    sessions: [{ id: 'ses-51', startsAt: iso(6, 21, 0), hall: 'Открытая площадка', availableSeats: 300 }],
  },
  {
    id: 'ev-6',
    title: 'Конференция «Продукт и данные»',
    subtitle: 'Два дня, три потока',
    category: 'Конференции',
    venue: 'Технопарк «Цифровой»',
    city: 'Москва',
    posterFrom: '#120F24',
    posterTo: '#6D4AFF',
    posterAccent: '#B0A0FF',
    priceFromMinor: 1200000,
    status: 'published',
    sessionsCount: 2,
    sessions: [{ id: 'ses-61', startsAt: iso(20, 10, 0), hall: 'Конференц-зал A', availableSeats: 88 }],
  },
]

export const CATEGORIES = ['Все', 'Классика', 'Стендап', 'Электроника', 'Детям', 'Кино', 'Конференции']

export const ORDERS: OrderRow[] = [
  { id: 'o1', number: 'NB-260922-0417', customer: 'Анна Ковалёва', email: 'a.kovaleva@example.com', eventTitle: 'Ночная симфония', sessionAt: iso(3, 19, 30), seats: 2, totalMinor: 1785000, status: 'paid', channel: 'site', createdAt: iso(0, 11, 24) },
  { id: 'o2', number: 'NB-260922-0416', customer: 'Игорь Петров', email: 'i.petrov@example.com', eventTitle: 'Stand-up: открытый микрофон', sessionAt: iso(1, 20, 0), seats: 1, totalMinor: 94500, status: 'awaiting_payment', channel: 'telegram', createdAt: iso(0, 10, 58) },
  { id: 'o3', number: 'NB-260922-0415', customer: 'Мария Соколова', email: 'm.sokolova@example.com', eventTitle: 'Электронная ночь: Live Set', sessionAt: iso(12, 22, 0), seats: 4, totalMinor: 1050000, status: 'paid', channel: 'embed', createdAt: iso(0, 10, 12) },
  { id: 'o4', number: 'NB-260922-0414', customer: 'Дмитрий Лебедев', email: 'd.lebedev@example.com', eventTitle: 'Ночная симфония', sessionAt: iso(3, 19, 30), seats: 1, totalMinor: 892500, status: 'payment_failed', channel: 'site', createdAt: iso(0, 9, 40) },
  { id: 'o5', number: 'NB-260921-0413', customer: 'Елена Морозова', email: 'e.morozova@example.com', eventTitle: 'Конференция «Продукт и данные»', sessionAt: iso(20, 10, 0), seats: 3, totalMinor: 3780000, status: 'partially_refunded', channel: 'admin', createdAt: iso(-1, 18, 5) },
  { id: 'o6', number: 'NB-260921-0412', customer: 'Павел Ершов', email: 'p.ershov@example.com', eventTitle: 'Детский спектакль «Снежная королева»', sessionAt: iso(2, 11, 0), seats: 4, totalMinor: 294000, status: 'refunded', channel: 'site', createdAt: iso(-1, 15, 30) },
  { id: 'o7', number: 'NB-260921-0411', customer: 'Ольга Ким', email: 'o.kim@example.com', eventTitle: 'Кино под открытым небом', sessionAt: iso(6, 21, 0), seats: 2, totalMinor: 126000, status: 'expired', channel: 'telegram', createdAt: iso(-1, 12, 2) },
  { id: 'o8', number: 'NB-260920-0410', customer: 'Сергей Волков', email: 's.volkov@example.com', eventTitle: 'Электронная ночь: Live Set', sessionAt: iso(12, 22, 0), seats: 2, totalMinor: 525000, status: 'cancelled', channel: 'site', createdAt: iso(-2, 21, 48) },
]

export const TICKETS: TicketCard[] = [
  { id: 't1', code: 'NB1-9F3A-2C7D', eventTitle: 'Ночная симфония: Вивальди и Макс Рихтер', venue: 'Большой зал филармонии', sessionAt: iso(3, 19, 30), sector: 'Партер A', row: 4, seat: 12, priceMinor: 1200000, status: 'issued', qrPayload: 'NB1.9F3A2C7D.4.12.20260925T1930Z.sig7c1a' },
  { id: 't2', code: 'NB1-4B81-77E0', eventTitle: 'Ночная симфония: Вивальди и Макс Рихтер', venue: 'Большой зал филармонии', sessionAt: iso(3, 19, 30), sector: 'Партер A', row: 4, seat: 13, priceMinor: 1200000, status: 'issued', qrPayload: 'NB1.4B8177E0.4.13.20260925T1930Z.sig2b9f' },
  { id: 't3', code: 'NB1-1D62-3A55', eventTitle: 'Stand-up: открытый микрофон', venue: 'Клуб «Подвал»', sessionAt: iso(-9, 20, 0), sector: 'Основной зал', row: 2, seat: 7, priceMinor: 90000, status: 'used', qrPayload: 'NB1.1D623A55.2.7.20260913T2000Z.sigab12' },
]
