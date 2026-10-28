/**
 * Покупка в один экран: контакты → заказ → оплата.
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ КОМПОЗАБЛ, А НЕ ЕЩЁ ОДИН ЭКРАН
 *   Витрина вела покупателя по цепочке «событие → схема зала → корзина →
 *   оформление → оплата», и на каждом шаге это была отдельная страница со своей
 *   загрузкой. Покупатель терял контекст, а встраиваемый виджет (билетная рамка
 *   на чужом сайте) так и вовсе не мог существовать: у чужой страницы нет наших
 *   маршрутов. Этот композабл — вся денежная часть процесса без маршрутов:
 *   `checkoutSession` → `initiatePayment` → подтверждение. Всё происходит там
 *   же, где выбирали места.
 *
 * ДЕМО-ОПЛАТА ПОДТВЕРЖДАЕТСЯ НА МЕСТЕ
 *   Виджeт Яндекс Афиши не уводит покупателя на отдельную страницу оплаты: в
 *   демо-режиме платёж подтверждается прямо в рамке. Мы делаем то же самое —
 *   НО ТОЛЬКО ДЛЯ НАШЕГО СИМУЛЯТОРА (`isDemoConfirmation()`). Ссылка
 *   настоящего провайдера открывается внешней навигацией: подтвердить платёж
 *   ЮKassa из своего кода невозможно, и попытка означала бы «деньги списаны по
 *   чеку, который мы выдумали».
 *
 * ЧТО ЗДЕСЬ НЕ ПРОИСХОДИТ
 *   Цена не считается на клиенте: итог берётся из ответа `POST /cart/checkout`
 *   (`setOrder`). Клиент только показывает лимит бонусов (`spendCapMinor` —
 *   зеркало `BonusPolicy::maxSpendable`), а решает сервер, отвечая 422
 *   `BONUS_LIMIT_EXCEEDED` на превышение.
 */
import { computed, ref, type Ref } from 'vue'
import { useCartStore } from '@/stores/cart'
import { useLoyaltyStore } from '@/stores/loyalty'
import { useUiStore } from '@/stores/ui'
import { checkoutSession } from '@/lib/inventory'
import { ApiError } from '@/lib/api'
import {
  anchorDemoUrl,
  confirmDemoPayment,
  demoPaymentId,
  initiatePayment,
  isDemoConfirmation,
  newIdempotencyKey,
} from '@/lib/payments'
import { customerAuthMessage, customerUser, isCustomerSession, loginCustomer, type CustomerUser } from '@/lib/customer'
import { applyBonuses } from '@/lib/loyalty'
import { clearRememberedReferralCode, rememberedReferralCode } from '@/lib/referral'
import { money, plural } from '@/lib/format'
import { trackEvent } from '@/lib/metrika'

/** Где покупатель находится внутри виджета. */
export type PurchaseStep = 'seats' | 'contacts' | 'done'

export interface PurchaseOutcome {
  orderId: string
  totalMinor: number
  email: string
}

export interface UsePurchaseFlowOptions {
  /** Сеанс, к которому относятся выбранные места. */
  sessionId: Ref<string>
  /** Перейти к оформлению: снимает холды другого сеанса (реализует вызвавший). */
  onAbandonSelection?: () => Promise<void> | void
}

export function usePurchaseFlow(options: UsePurchaseFlowOptions) {
  const { sessionId } = options
  const cart = useCartStore()
  const loyalty = useLoyaltyStore()
  const ui = useUiStore()

  const step = ref<PurchaseStep>('seats')

  /* ── Контакты покупателя ──────────────────────────────────────────────────
   * Повтор e-mail обязателен: билет уходит ровно на этот адрес, и опечатка в
   * одну букву означает письмо, которое не придёт никогда. Сервер сверяет оба
   * поля тем же правилом (`same:customer_email`), но ловим мы это до запроса.
   */
  const name = ref('')
  const email = ref('')
  const emailConfirm = ref('')
  const phone = ref('')
  const agree = ref(false)
  const paying = ref(false)
  const submitted = ref(false)
  const serverErrors = ref<Record<string, string>>({})
  const outcome = ref<PurchaseOutcome | null>(null)

  /**
   * Ключ идемпотентности платежа (S-07).
   *
   * Живёт в пределах одного «намерения оплатить»: двойной клик или ретрай
   * транспорта шлют ОДИН ключ, и сервер возвращает тот же платёж вместо
   * второго. После ошибки сбрасывается — новая попытка это уже другой запрос.
   */
  const paymentKey = ref('')

  function idempotencyKey(): string {
    if (!paymentKey.value) paymentKey.value = newIdempotencyKey()
    return paymentKey.value
  }

  /** Авторизован ли покупатель. Не реактивно по природе, поэтому храним явно. */
  const loggedIn = ref(isCustomerSession())

  const EMAIL_RE = /^[^@\s]+@[^@\s]+\.[a-zа-я]{2,}$/i

  const localErrors = computed(() => {
    const out: Record<string, string> = {}
    if (name.value.trim().length < 2) out.name = 'Укажите имя, как в документе'
    if (!EMAIL_RE.test(email.value)) out.email = 'Нужен e-mail — на него придёт билет'
    if (email.value.trim() !== '' && emailConfirm.value.trim() !== email.value.trim()) {
      out.emailConfirm = 'Адреса не совпадают — проверьте e-mail'
    }
    if (phone.value.replace(/\D/g, '').length !== 11) out.phone = 'Телефон — 11 цифр, начиная с 7'
    if (!agree.value) out.agree = 'Без согласия на оферту билет не выпустим'
    return out
  })

  /** Серверные ошибки важнее локальных: их вернул источник истины. */
  const errors = computed<Record<string, string>>(() => ({ ...localErrors.value, ...serverErrors.value }))

  const canPay = computed(() => Object.keys(errors.value).length === 0 && cart.count > 0)

  const missingHint = computed(() => {
    if (cart.count === 0) return 'Сначала выберите места'
    return Object.values(errors.value)[0] ?? ''
  })

  /**
   * Адрес корректен и подтверждён повтором — последняя точка, где покупатель
   * ещё может заметить опечатку.
   */
  const emailOk = computed(() => EMAIL_RE.test(email.value) && emailConfirm.value.trim() === email.value.trim())

  /* ── Бонусы: лимит показывает витрина, решает сервер ──────────────────────
   * `spendCapMinor()` — зеркало `BonusPolicy::maxSpendable`. Клиентское число
   * здесь только предпросмотр: оно не может быть истиной, потому что баланс
   * мог измениться в другой вкладке между отрисовкой и нажатием.
   */
  /** Галочка списания по умолчанию снята: трата бонусов должна быть осознанной. */
  const useBonuses = ref(false)

  /**
   * Правило применения бонусов — один владелец: `applyBonuses()` из `@/lib/loyalty`.
   * Здесь только передаём корзину и флаг; все пять величин считаются там.
   * Ниже — тонкие обёртки, чтобы остальной код страницы не менялся.
   */
  const bonus = computed(() =>
    applyBonuses({
      subtotalMinor: cart.subtotalMinor,
      discountMinor: cart.discountMinor,
      balanceMinor: loyalty.bonus?.balance ?? 0,
      cartTotalMinor: cart.totalMinor,
      useBonuses: useBonuses.value,
    }),
  )

  const bonusCapMinor = computed(() => bonus.value.capMinor)
  const bonusMinor = computed(() => bonus.value.appliedMinor)
  const totalMinor = computed(() => bonus.value.totalMinor)
  const bonusCapUnits = computed(() => bonus.value.capUnits)
  const bonusLeftUnits = computed(() => bonus.value.leftUnits)

  /**
   * Блок бонусов вообще показывается: счёт загружен, на нём что-то есть и лимит
   * списания положителен. Это UI-ворота, а не величина из `applyBonuses()`:
   * гость или пустой счёт блок не видит, иначе показывали бы «спишите 0 бонусов».
   */
  const canUseBonuses = computed(
    () => loyalty.bonus !== null && loyalty.bonusesCount > 0 && bonusCapMinor.value > 0,
  )

  const bonusLabel = computed(
    () => `Списать ${bonusCapUnits.value} ${plural(bonusCapUnits.value, 'бонус', 'бонуса', 'бонусов')}`,
  )

  const bonusHint = computed(
    () =>
      `−${money(bonusCapMinor.value)} · после покупки останется ${bonusLeftUnits.value} ` +
      `${plural(bonusLeftUnits.value, 'бонус', 'бонуса', 'бонусов')}`,
  )

  /** Код приглашения: друг открыл `/#/r/{code}` — код ждёт ровно до этого момента. */
  const referralCode = ref(rememberedReferralCode())

  /** Имена полей §66 → имена полей формы. */
  const SERVER_FIELD_MAP: Record<string, string> = {
    customer_name: 'name',
    customer_email: 'email',
    customer_email_confirmation: 'emailConfirm',
    customer_phone: 'phone',
  }

  /** Заполнить форму из профиля: авторизованному нечего вводить повторно. */
  function prefillFromUser(user: CustomerUser | null): void {
    if (!user) return
    const fullName = [user.first_name, user.last_name].filter((part) => !!part && part.trim() !== '').join(' ')
    if (name.value.trim() === '' && fullName !== '') name.value = fullName
    if (email.value.trim() === '' && user.email) {
      email.value = user.email
      emailConfirm.value = user.email
    }
    if (phone.value.trim() === '' && user.phone) phone.value = user.phone
  }

  /** Подставить сохранённый профиль при открытии формы. */
  function prefillFromSession(): void {
    const user = customerUser()
    if (user) prefillFromUser(user)
  }

  /**
   * Вход в существующий аккаунт прямо в рамке покупки.
   *
   * Аккаунт покупателю заводится сам при первой оплате
   * (`CustomerAccountService::resolveForCheckout()`), поэтому «войти» здесь —
   *   это «не создавать второй аккаунт с тем же e-mail», а не обязательное
   * условие покупки: после входа подставляются имя, телефон и адрес, а вместе
   * с ними становится доступно списание бонусов.
   */
  async function login(mail: string, password: string): Promise<CustomerUser | null> {
    if (!EMAIL_RE.test(mail.trim()) || password === '') {
      ui.notify('sun', 'Не хватает данных', 'Введите e-mail и пароль от кабинета.')
      return null
    }
    try {
      const user = await loginCustomer(mail, password)
      loggedIn.value = true
      prefillFromUser(user)
      // Баланс бонусов приходит вместе с профилем — он теперь доступен.
      void loyalty.load(true)
      ui.notify('mint', 'Вы вошли', 'Билеты сохранятся в личном кабинете.')
      return user
    } catch (e) {
      ui.notify('rose', 'Не удалось войти', customerAuthMessage(e))
      return null
    }
  }

  /** Шаг «контакты»: предзаполняем профиль и фиксируем начало оформления. */
  function openContacts(): void {
    if (cart.count === 0) return
    prefillFromSession()
    submitted.value = false
    serverErrors.value = {}
    step.value = 'contacts'
    void trackEvent('checkout_started', {
      params: { session: sessionId.value, tickets: cart.count },
    }).catch(() => {})
  }

  /** Вернуться к схеме зала: выбор и холды не трогаем. */
  function backToSeats(): void {
    step.value = 'seats'
  }

  /** Сбросить форму (начало новой покупки после успешной). */
  function reset(): void {
    step.value = 'seats'
    outcome.value = null
    submitted.value = false
    serverErrors.value = {}
    useBonuses.value = false
    paymentKey.value = ''
  }

  function abandonSelection(): Promise<void> | void {
    // Холды снимает вызвавший компонент: только он знает, к какому сеансу они
    // относятся, и что показать на схеме после снятия.
    void options.onAbandonSelection?.()
  }

  function applyServerErrors(error: ApiError): void {
    const mapped: Record<string, string> = {}
    for (const [field, messages] of Object.entries(error.details ?? {})) {
      const key = SERVER_FIELD_MAP[field]
      if (key && Array.isArray(messages) && messages.length > 0) mapped[key] = String(messages[0])
    }
    serverErrors.value = mapped
  }

  /**
   * Разбор отказа оплаты.
   *
   * Порядок ветвей не случаен: 409 — это и «место заняли», и «корзина
   * протухла», а 404 от чекаута — не «сбой», а конец жизни корзины (аудит
   * D-8). Любая ошибка завершает попытку: следующий клик обязан получить
   * новый ключ идемпотентности, иначе сервер вернул бы платёж прежней,
   * неудавшейся попытки вместе с её мёртвой ссылкой на оплату.
   */
  function handlePayError(error: unknown): void {
    paymentKey.value = ''

    if (!(error instanceof ApiError)) {
      ui.notify('rose', 'Оплата не началась', error instanceof Error ? error.message : 'Попробуйте ещё раз')
      return
    }

    if (error.status === 422 && error.details) {
      applyServerErrors(error)
      ui.notify('sun', 'Проверьте данные', 'Некоторые поля заполнены неверно.')
      return
    }

    if (error.code === 'CART_EXPIRED' || error.status === 410) {
      ui.notify('rose', 'Время удержания истекло', 'Места освобождены — выберите их заново.')
      cart.release()
      abandonSelection()
      return
    }

    if (error.status === 404) {
      ui.notify('rose', 'Корзина не найдена', 'Заказ уже оформлен или корзина устарела — выберите места заново.')
      cart.release()
      abandonSelection()
      return
    }

    if (error.code === 'CART_EMPTY') {
      ui.notify('rose', 'Корзина пуста', 'Сервер не нашёл удержанных мест для этого сеанса.')
      abandonSelection()
      return
    }

    if (error.code === 'SALES_CLOSED') {
      ui.notify('rose', 'Продажи закрыты', error.message)
      return
    }

    if (
      error.code === 'PROMO_CODE_REJECTED' ||
      error.code === 'PROMO_CODE_NOT_FOUND' ||
      error.code === 'PROMO_CODE_EXHAUSTED'
    ) {
      ui.notify('sun', 'Промокод не применён', error.message)
      return
    }

    if (error.code === 'BONUS_LIMIT_EXCEEDED') {
      // Галочку снимаем, а не «поправляем молча»: покупатель подтверждал
      // конкретную цену, и подмена суммы под ним была бы хуже отказа.
      useBonuses.value = false
      void loyalty.load(true)
      ui.notify('sun', 'Бонусы не списаны', `${error.message} Оформите заказ без бонусов или повторите попытку.`)
      return
    }

    if (error.code === 'DEMO_DISABLED' || error.status === 403) {
      ui.notify('rose', 'Оплата недоступна', 'Платёжный провайдер не настроен — обратитесь к организатору.')
      return
    }

    ui.notify('rose', 'Оплата не началась', error.message)
  }

  /**
   * Оплата: заказ → платёж → подтверждение.
   *
   * ПОЧЕМУ ДЕМО ПОДТВЕРЖДАЕТСЯ ЗДЕСЬ. Виджет Яндекс Афиши не отправляет
   * покупателя на отдельную страницу оплаты: в демо-режиме платёж
   * подтверждается в той же рамке, и сделка закрывается одним экраном. Наш
   * симулятор — та же наша страница (`isDemoConfirmation`), поэтому вызываем
   * `POST /payments/demo-pay` прямо отсюда. Ссылка настоящего провайдера
   * открывается внешней навигацией: подделать подтверждение ЮKassa нельзя.
   */
  async function pay(): Promise<void> {
    submitted.value = true
    serverErrors.value = {}

    if (!canPay.value) {
      ui.notify('sun', 'Не хватает данных', missingHint.value)
      return
    }
    if (!sessionId.value) {
      ui.notify('rose', 'Сеанс не определён', 'Вернитесь к событию и выберите места заново.')
      return
    }

    paying.value = true
    try {
      // 1. Заказ из удержанных мест. Бонусы уходят тем же запросом: списание —
      //    часть оформления, и считать его отдельным вызовом значило бы получить
      //    заказ с одной суммой, а списание — с другой.
      const { data: order } = await checkoutSession(sessionId.value, {
        customer_name: name.value.trim(),
        customer_email: email.value.trim(),
        customer_email_confirmation: emailConfirm.value.trim(),
        customer_phone: phone.value.trim(),
        bonus_amount: bonusMinor.value,
        referral_code: referralCode.value ?? undefined,
      })

      // 2. Итог — из ответа сервера, он источник правды о цене.
      cart.setOrder(order.order_id, Number(order.total_amount))

      if (referralCode.value) clearRememberedReferralCode()

      void trackEvent('payment_attempt', {
        params: { order_id: String(order.order_id), tickets: cart.count },
      }).catch(() => {})

      // 3. Платёж. Гостевой заказ авторизуется заголовком X-Cart-Token,
      //    который подставляет api.ts (`PaymentController::mayPay()`).
      const { confirmation_url, payment } = await initiatePayment(order.order_id, idempotencyKey())

      if (!confirmation_url) {
        outcome.value = {
          orderId: String(order.order_id),
          totalMinor: Number(order.total_amount),
          email: email.value.trim(),
        }
        step.value = 'done'
        ui.notify('sun', 'Нет ссылки на оплату', 'Заказ создан. Оплатить его можно из «Моих билетов».')
        return
      }

      // 4. Демо-режим: подтверждаем сами и показываем результат здесь же.
      //    Реальный провайдер — внешняя навигация, других вариантов нет.
      if (isDemoConfirmation(confirmation_url)) {
        // Идентификатор берём из ссылки: `public_id` здесь не принимается
        // (422 NOT_DEMO_PAYMENT), см. `demoPaymentId()`.
        await confirmDemoPayment(demoPaymentId(confirmation_url, payment))
        outcome.value = {
          orderId: String(order.order_id),
          totalMinor: Number(order.total_amount),
          email: email.value.trim(),
        }
        step.value = 'done'
        cart.release()
        ui.notify('mint', 'Оплата прошла', 'Билеты выпущены и отправлены на вашу почту.')
        return
      }

      window.location.href = anchorDemoUrl(confirmation_url) ?? confirmation_url
    } catch (error) {
      handlePayError(error)
    } finally {
      paying.value = false
    }
  }

  return {
    step,
    outcome,
    name,
    email,
    emailConfirm,
    phone,
    agree,
    useBonuses,
    paying,
    submitted,
    errors,
    canPay,
    emailOk,
    missingHint,
    loggedIn,
    bonusCapMinor,
    bonusMinor,
    totalMinor,
    canUseBonuses,
    bonusLabel,
    bonusHint,
    openContacts,
    backToSeats,
    login,
    pay,
    reset,
  }
}
