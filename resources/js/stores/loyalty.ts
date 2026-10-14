/**
 * Состояние «Клуба зрителей»: списки «♡»/«🔔», баланс бонусов и отметки витрины.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ СТОР, А НЕ ЗАГРУЗКА В КАЖДОМ КОМПОНЕНТЕ
 *   «Заполнено ли сердце» — вопрос про ОДНО и то же состояние, заданный в
 *   трёх местах: карточка афиши, страница мероприятия, экраны кабинета.
 *   Если бы каждый спрашивал сам, витрина делала бы по два запроса на карточку
 *   и мигала сердцами при переходах. Поэтому списки грузятся один раз и лежат
 *   здесь, а компоненты спрашивают `isFavorite()` — синхронно.
 *
 * БАЛАНС ЖИВЁТ ЗДЕСЬ ЖЕ, А НЕ НА СТРАНИЦЕ БОНУСОВ
 *   Его показывает не только `/bonus`: чип в шапке и плитка в кабинете
 *   спрашивают то же число. Отдельная загрузка на каждый экран означала бы три
 *   запроса к `/me/bonus` за один переход — и три разных ответа на один вопрос,
 *   если покупка случилась между ними. Поэтому баланс — часть того же `load()`,
 *   что и списки.
 *
 * ОДИН ИСТОЧНИК ПРАВДЫ — СПИСОК
 *   Отметки ВЫВОДЯТСЯ из списков, а не хранятся рядом. Два поля с одним
 *   смыслом рано или поздно расходятся: отметка остаётся, строка удалена —
 *   и сердце показывает «в избранном» то, чего в избранном нет. Плата за
 *   это — теоретический случай «сервер вернул строку без `event`», но он
 *   самолечащийся: покупатель нажимает «♡», сервер отвечает 200 «уже в
 *   избранном» и строка возвращается в список.
 *
 * ГОСТЬ НЕ ГРУЗИТ ЛИЧНОЕ
 *   `/me/*` без аккаунта отвечает 401. Запрос «на всякий случай» не просто
 *   бесполезен — он заставил бы `request()` гасить токен в ответ на 401. Так
 *   что гость живёт с пустыми списками и без баланса: сердца у него контурные,
 *   нажатие уводит на вход, а чип бонусов не показывается вовсе (это решают
 *   компоненты — они владеют навигацией).
 *
 * ОТМЕТКА ПОЯВЛЯЕТСЯ ТОЛЬКО ПОСЛЕ ОТВЕТА СЕРВЕРА
 *   Оптимистичное «сразу закрасим» красивее в демо, но врёт при отказе: сеть
 *   отвалилась, сервер ответил 404 (мероприятие снято с публикации) — а
 *   покупатель видит «♡» и уходит, уверенный, что сохранил. Здесь состояние
 *   меняется только когда сервер подтвердил, а ошибка уходит в тост.
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { isCustomerSession } from '@/lib/customer'
import {
  addFavorite,
  fetchBonusBalance,
  fetchFavorites,
  fetchFollows,
  fetchLoyaltyLevel,
  followEvent,
  removeFavorite,
  unfollowEvent,
  type BonusBalance,
  type LoyaltyEntry,
  type LoyaltyLevelStatus,
} from '@/lib/loyalty'

export const useLoyaltyStore = defineStore('loyalty', () => {
  const favorites = ref<LoyaltyEntry[]>([])
  const follows = ref<LoyaltyEntry[]>([])
  /**
   * Баланс бонусного счёта. `null` — не загружен: гость, ошибка запроса или
   * счёт ещё не приходил. Именно `null`, а не `0`: ноль — это «счёт есть и он
   * пуст», и показывать его гостю значило бы обещать бонусы, которых нет.
   */
  const bonus = ref<BonusBalance | null>(null)
  /** Текущий уровень «Клуба зрителей» (`null` — не загружен или гость). */
  const loyaltyLevel = ref<LoyaltyLevelStatus | null>(null)

  /*
   * Множества `public_id` мероприятий из списков — derived, а не состояние.
   *
   * Строка без `event` (событие удалили, `EventFollowResource` отдаёт
   * `event: null`) в отметку не попадает намеренно: уведомление подписчику
   * привязано к живому событию, и «♡» на удалённом мероприятии было бы
   * обещанием, которое сервер не выполнит.
   */
  const favoriteIds = computed(
    () => new Set(favorites.value.map((entry) => entry.event?.id ?? '').filter((id) => id !== '')),
  )
  const followIds = computed(
    () => new Set(follows.value.map((entry) => entry.event?.id ?? '').filter((id) => id !== '')),
  )

  const loading = ref(false)
  /** Списки уже загружены — повторный `load()` без `force` не ходит в сеть. */
  const loaded = ref(false)
  /** Мероприятия «в полёте»: второй клик по ♡ не должен слать второй запрос. */
  const pending = ref<Set<string>>(new Set())

  function isFavorite(eventId: string): boolean {
    return eventId !== '' && favoriteIds.value.has(eventId)
  }

  function isFollowing(eventId: string): boolean {
    return eventId !== '' && followIds.value.has(eventId)
  }

  /** Убрать из списка все строки этого мероприятия (после снятия отметки). */
  function dropFrom(list: LoyaltyEntry[], eventId: string): LoyaltyEntry[] {
    return list.filter((entry) => entry.event?.id !== eventId)
  }

  /**
   * Загрузить списки и баланс. Гостю — no-op, повторный вызов — тоже.
   *
   * Ошибку не пробрасываем: витрина не должна падать из-за сбойного запроса
   * отметок, которых, может, и нет. Списки остаются пустыми, сердца —
   * контурными; следующая навигация попробует снова.
   *
   * Три запроса идут ПАРАЛЛЕЛЬНО и разделяют одну судьбу: если упал любой,
   * `loaded` остаётся `false`, и следующий переход попробует все три снова.
   * Разводить их по отдельным `try` значило бы показывать половину клуба
   * («сердца есть, баланса нет») и не иметь момента, когда всё уже загружено.
   */
  async function load(force = false): Promise<void> {
    if (!isCustomerSession()) return
    if (loading.value) return
    if (loaded.value && !force) return

    loading.value = true
    try {
      const [nextFavorites, nextFollows, nextBonus, nextLevel] = await Promise.all([
        fetchFavorites(),
        fetchFollows(),
        fetchBonusBalance(),
        fetchLoyaltyLevel(),
      ])
      favorites.value = nextFavorites
      follows.value = nextFollows
      bonus.value = nextBonus
      loyaltyLevel.value = nextLevel
      loaded.value = true
    } catch {
      favorites.value = []
      follows.value = []
      bonus.value = null
      loyaltyLevel.value = null
    } finally {
      loading.value = false
    }
  }

  /**
   * Поставить или снять «♡». Возвращает новое состояние отметки.
   *
   * Ошибка пробрасывается вызывающему: он покажет тост и НЕ изменит вид,
   * потому что состояние здесь меняется только после ответа сервера.
   *
   * Возвращаемое значение — про ТО, ЧТО ВИДИТ покупатель: если сервер создал
   * отметку, но не вернул строку (нарушение конверта), мы не добавляем
   * несуществующую карточку в список, а честно сообщаем, что отметки нет.
   * Следующее нажатие починит: сервер ответит 200 «уже в избранном».
   */
  async function toggleFavorite(eventId: string): Promise<boolean> {
    if (eventId === '' || pending.value.has(eventId)) return isFavorite(eventId)

    pending.value.add(eventId)
    try {
      if (isFavorite(eventId)) {
        await removeFavorite(eventId)
        favorites.value = dropFrom(favorites.value, eventId)
        return isFavorite(eventId)
      }

      const entry = await addFavorite(eventId)
      if (entry) favorites.value = [entry, ...dropFrom(favorites.value, eventId)]
      return isFavorite(eventId)
    } finally {
      pending.value.delete(eventId)
    }
  }

  /** Подписаться или отписаться. Возвращает новое состояние подписки. */
  async function toggleFollow(eventId: string): Promise<boolean> {
    if (eventId === '' || pending.value.has(eventId)) return isFollowing(eventId)

    pending.value.add(eventId)
    try {
      if (isFollowing(eventId)) {
        await unfollowEvent(eventId)
        follows.value = dropFrom(follows.value, eventId)
        return isFollowing(eventId)
      }

      const entry = await followEvent(eventId)
      if (entry) follows.value = [entry, ...dropFrom(follows.value, eventId)]
      return isFollowing(eventId)
    } finally {
      pending.value.delete(eventId)
    }
  }

  /** Забыть всё: выход из кабинета и смена аккаунта в том же браузере. */
  function reset(): void {
    favorites.value = []
    follows.value = []
    bonus.value = null
    loyaltyLevel.value = null
    pending.value = new Set()
    loaded.value = false
  }

  const favoritesCount = computed(() => favorites.value.length)
  const followsCount = computed(() => follows.value.length)
  /** Баланс в бонусах по курсу сервера; 0 — если счёта нет или он пуст. */
  const bonusesCount = computed(() => bonus.value?.bonuses ?? 0)

  return {
    favorites,
    follows,
    bonus,
    loyaltyLevel,
    favoritesCount,
    followsCount,
    bonusesCount,
    loading,
    loaded,
    isFavorite,
    isFollowing,
    load,
    toggleFavorite,
    toggleFollow,
    reset,
  }
})
