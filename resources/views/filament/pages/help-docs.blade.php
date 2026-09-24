<x-filament-panels::page>
    <div class="space-y-8">

        {{-- ============================================================
             ШАПКА: цель панели и быстрые ссылки
             ============================================================ --}}
        <div class="rounded-xl border border-line bg-surface p-5">
            <div class="flex items-start gap-3">
                <x-heroicon-o-book-open class="w-10 h-10 text-primary-500 shrink-0" />
                <div>
                    <h2 class="text-xl font-semibold">Добро пожаловать в Nabilet Admin</h2>
                    <p class="text-gray-600 dark:text-gray-400 mt-1">
                        Это панель управления билетной платформой: залы и схемы рассадки, мероприятия,
                        сеансы и цены, заказы, платежи, билеты и проверка на входе.
                        Ниже — пошаговые инструкции и ответы на частые вопросы.
                    </p>
                    <div class="flex flex-wrap gap-2 mt-3">
                        <a href="#onboarding" class="text-sm underline text-primary-600 hover:text-primary-700">Быстрый старт</a>
                        <span class="text-gray-400">·</span>
                        <a href="#events" class="text-sm underline text-primary-600 hover:text-primary-700">Мероприятия</a>
                        <span class="text-gray-400">·</span>
                        <a href="#venues" class="text-sm underline text-primary-600 hover:text-primary-700">Залы и схемы</a>
                        <span class="text-gray-400">·</span>
                        <a href="#sales" class="text-sm underline text-primary-600 hover:text-primary-700">Продажи</a>
                        <span class="text-gray-400">·</span>
                        <a href="#tickets" class="text-sm underline text-primary-600 hover:text-primary-700">Билеты и QR</a>
                        <span class="text-gray-400">·</span>
                        <a href="#faq" class="text-sm underline text-primary-600 hover:text-primary-700">FAQ</a>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================================================
             ОНБОРДИНГ: первый запуск
             ============================================================ --}}
        <div id="onboarding" class="scroll-mt-8">
            <x-filament::section>
                <x-slot name="heading">🚀 Быстрый старт: с чего начать</x-slot>
                <x-slot name="description">Последовательность шагов для запуска первой продажи</x-slot>
                <ol class="list-decimal space-y-3 pl-6">
                    <li>
                        <strong>Создайте организатора</strong> — раздел
                        <em>Organizations</em>. Это юридическое лицо, от которого продаются билеты.
                    </li>
                    <li>
                        <strong>Создайте площадку</strong> — <em>Venues</em>: город, адрес, координаты.
                        Укажите организатора в поле <em>Organization</em>.
                    </li>
                    <li>
                        <strong>Добавьте зал</strong> — <em>Halls</em> (связан с площадкой).
                        Укажите вместимость и нажмите <em>Edit Schema</em>, чтобы нарисовать схему рассадки.
                    </li>
                    <li>
                        <strong>Нарисуйте схему</strong> — сектор, ряды, места с ценами.
                        Сохраните и <strong>опубликуйте версию схемы</strong> (кнопка Publish).
                    </li>
                    <li>
                        <strong>Создайте мероприятие</strong> — <em>Events</em>: название,
                        описание, постер, возрастной ценз. Статус оставьте <em>Draft</em> пока не готовы продавать.
                    </li>
                    <li>
                        <strong>Заведите сеанс</strong> — <em>Sessions</em>: дата и время, зал,
                        статус <em>On Sale</em> и окно продаж (когда открыть и закрыть).
                        После публикации сеанса система сама создаст инвентарь по схеме зала.
                    </li>
                    <li>
                        <strong>Проверьте готовность</strong> — на странице редактирования мероприятия
                        нажмите <em>Проверить готовность к публикации</em> и убедитесь, что все пункты зелёные.
                    </li>
                    <li>
                        <strong>Опубликуйте</strong> — статус мероприятия <em>Published</em>.
                        Карточка появится на витрине (главная страница) и билеты станут доступны к покупке.
                    </li>
                </ol>
            </x-filament::section>
        </div>

        {{-- ============================================================
             ЛОГИЧЕСКАЯ МОДЕЛЬ (как всё связано)
             ============================================================ --}}
        <div>
            <x-filament::section>
                <x-slot name="heading">🧩 Как устроены данные</x-slot>
                <x-slot name="description">Цепочка объектов, которую важно понимать</x-slot>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left border border-line">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="p-3 font-semibold">Раздел</th>
                                <th class="p-3 font-semibold">Что это</th>
                                <th class="p-3 font-semibold">На что влияет</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr>
                                <td class="p-3 font-mono">Organization</td>
                                <td>Организатор (юрлицо)</td>
                                <td>Владелец всех объектов и выручки</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Venue</td>
                                <td>Площадка (концертный зал, стадион)</td>
                                <td>География: город, адрес, координаты</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Hall + Schema</td>
                                <td>Зал и его схема рассадки</td>
                                <td>Сектора, ряды, места, цены рядов</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Event</td>
                                <td>Мероприятие (афиша)</td>
                                <td>Витрина: карточка на главной</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Session</td>
                                <td>Сеанс: дата × зал × окно продаж</td>
                                <td>Создаёт инвентарь (места в продаже)</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Inventory</td>
                                <td>Продаваемые места с ценами</td>
                                <td>Схема зала на странице выбора мест</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Order / Payment</td>
                                <td>Заказ и платёж покупателя</td>
                                <td>Статусы, выручка, возвраты</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Ticket</td>
                                <td>Билет с QR-кодом</td>
                                <td>Проверка на входе: Checkin</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-sm text-gray-500 mt-3">
                    💡 Правило: <strong>схема зала публикуется один раз и дальше не меняется</strong>.
                    Если нужно изменить рассадку — создайте новую версию схемы (Duplicate), а не редактируйте опубликованную.
                </p>
            </x-filament::section>
        </div>

        {{-- ============================================================
             МЕРОПРИЯТИЯ
             ============================================================ --}}
        <div id="events" class="scroll-mt-8">
            <x-filament::section>
                <x-slot name="heading">🎟️ Мероприятия (Events)</x-slot>
                <x-slot name="description">Создание, статусы и публикация</x-slot>

                <h3 class="font-semibold mt-4">Поля формы</h3>
                <div class="overflow-x-auto mt-2">
                    <table class="w-full text-sm text-left border border-line">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="p-3 font-semibold">Поле</th>
                                <th class="p-3 font-semibold">Как заполнить</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr>
                                <td class="p-3 font-mono">Title</td>
                                <td>Название, как оно появится на афише. Понятное и без кавычек-«ёлочек».</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Slug</td>
                                <td>Часть URL: латиницей, строчными, через дефис (например <code>test-concert</code>). Генерируется из названия, но лучше задать явно.</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Category</td>
                                <td>Раздел витрины (Классика, Стендап, Детям…). Если категории нет — создайте её заранее.</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Age Limit</td>
                                <td>Возрастной ценз: <code>0+</code>, <code>6+</code>, <code>12+</code>, <code>16+</code>, <code>18+</code>.</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Duration</td>
                                <td>Продолжительность в минутах (с антрактом, если есть).</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Short Description</td>
                                <td>1–2 предложения для карточки на афише. Без HTML.</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Description</td>
                                <td>Полное описание: программа, артисты. Поддерживает форматирование.</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Poster / Cover</td>
                                <td>Постер — вертикальная картинка карточки. Рекомендуем 2:3, до 5 МБ. Cover — широкий баннер.</td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Status</td>
                                <td>
                                    <em>Draft</em> — черновик, скрыт с витрины ·
                                    <em>Published</em> — в продаже ·
                                    <em>Archived</em> — закрыт, история ·
                                    <em>Cancelled</em> — отменён.
                                </td>
                            </tr>
                            <tr>
                                <td class="p-3 font-mono">Published At</td>
                                <td>Момент выхода на витрину. Если пусто — берётся момент нажатия «Published».</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h3 class="font-semibold mt-4">Перед публикацией</h3>
                <ul class="list-disc space-y-2 pl-6 mt-2">
                    <li>Заполнены: название, slug, описание, постер.</li>
                    <li>Создан хотя бы один <strong>сеанс</strong> со статусом <em>On Sale</em>.</li>
                    <li>Зал имеет <strong>опубликованную схему</strong> с рядами и ценами.</li>
                    <li>Инвентарь создан: на странице сеанса проверьте, что места появились в продаже.</li>
                    <li>Совет: купите 1 тестовый билет и проверьте QR перед запуском.</li>
                </ul>
            </x-filament::section>
        </div>

        {{-- ============================================================
             ЗАЛЫ И СХЕМЫ
             ============================================================ --}}
        <div id="venues" class="scroll-mt-8">
            <x-filament::section>
                <x-slot name="heading">🏟️ Залы, схемы и цены</x-slot>
                <x-slot name="description">Как устроена рассадка и откуда берутся цены</x-slot>
                <ul class="list-disc space-y-2 pl-6 mt-2">
                    <li>Зал принадлежит площадке (Venue) и имеет вместимость.</li>
                    <li>Внутри зала — <strong>версии схемы</strong> (Schema Versions). Редактор позволяет рисовать сектора, ряды и места.</li>
                    <li><strong>Цены задаются на ряд</strong>: у каждого ряда внутри сектора есть цена. Все места ряда продаются по цене ряда.</li>
                    <li>Редкие случаи (ложи, особые места) можно переопределить на отдельном месте.</li>
                    <li>Когда схема опубликована — она становится источником инвентаря для всех новых сеансов.</li>
                    <li>Вместимость зала должна совпадать с числом мест на схеме: это проверяется при публикации.</li>
                </ul>
                <div class="mt-3 rounded-lg bg-gray-50 dark:bg-gray-800 p-3 text-sm">
                    ⚠️ <strong>Важно:</strong> после публикации схема становится неизменной.
                    Для изменений создайте новую версию и опубликуйте её — старые сеансы продолжат работать на старой.
                </div>
            </x-filament::section>
        </div>

        {{-- ============================================================
             ПРОДАЖИ
             ============================================================ --}}
        <div id="sales" class="scroll-mt-8">
            <x-filament::section>
                <x-slot name="heading">💳 Продажи: заказы, платежи, возвраты</x-slot>
                <x-slot name="description">Жизненный цикл заказа</x-slot>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left border border-line">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="p-3 font-semibold">Статус заказа</th>
                                <th class="p-3 font-semibold">Значение</th>
                                <th class="p-3 font-semibold">Действия админа</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr>
                                <td class="p-3"><span class="text-gray-500">Pending</span></td>
                                <td>Покупатель начал оформление, платёж не прошёл</td>
                                <td>Ничего не делать, истечёт сам</td>
                            </tr>
                            <tr>
                                <td class="p-3"><span class="text-success-600">Paid</span></td>
                                <td>Оплачен, билеты выпущены</td>
                                <td>Можно вернуть по запросу (см. ниже)</td>
                            </tr>
                            <tr>
                                <td class="p-3"><span class="text-warning-600">Held</span></td>
                                <td>Места удержаны (в корзине/брони)</td>
                                <td>Автоматически снимается через N минут</td>
                            </tr>
                            <tr>
                                <td class="p-3"><span class="text-danger-600">Cancelled</span></td>
                                <td>Отменён до оплаты</td>
                                <td>Места возвращаются в продажу</td>
                            </tr>
                            <tr>
                                <td class="p-3"><span class="text-gray-500">Refunded</span></td>
                                <td>Оплата возвращена</td>
                                <td>Платёж уходит в провайдера, билеты гасятся</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <h3 class="font-semibold mt-4">Как оформить возврат</h3>
                <ol class="list-decimal space-y-1.5 pl-6 mt-1">
                    <li>Откройте заказ (Orders).</li>
                    <li>Убедитесь, что статус <em>Paid</em> и до события есть время.</li>
                    <li>Нажмите <em>Refund</em>. Система создаст возврат в платёжном провайдере.</li>
                    <li>После подтверждения провайдера билеты станут недействительными, места — снова в продаже.</li>
                </ol>
                <div class="mt-3 rounded-lg bg-gray-50 dark:bg-gray-800 p-3 text-sm">
                    💡 Если возврат «завис» — проверьте раздел <em>Payments</em>:
                    статус платежа и статус возврата должны совпасть.
                </div>
            </x-filament::section>
        </div>

        {{-- ============================================================
             БИЛЕТЫ И QR
             ============================================================ --}}
        <div id="tickets" class="scroll-mt-8">
            <x-filament::section>
                <x-slot name="heading">📱 Билеты, QR и проверка на входе</x-slot>
                <x-slot name="description">От покупки до контроля</x-slot>
                <ul class="list-disc space-y-2 pl-6 mt-2">
                    <li>После оплаты покупатель получает билеты на email и в личный кабинет.</li>
                    <li>Каждый билет имеет уникальный QR. Скан на входе (раздел <em>Check-in</em> / CheckinDevices) подтверждает действительность.</li>
                    <li>Повторный скан того же QR помечается как <em>used</em> — двойное использование невозможно.</li>
                    <li>Контролёру не нужен интернет: QR содержит подпись.</li>
                    <li>Если билет не сканируется — проверьте, не отменён ли заказ и не возвращён ли платёж.</li>
                </ul>
                <div class="mt-3 rounded-lg bg-gray-50 dark:bg-gray-800 p-3 text-sm">
                    ⚠️ Билеты печатаются автоматически. Ручная выдача билета (например, на месте) — только через раздел <em>Tickets</em> с указанием заказа.
                </div>
            </x-filament::section>
        </div>

        {{-- ============================================================
             FAQ
             ============================================================ --}}
        <div id="faq" class="scroll-mt-8">
            <x-filament::section>
                <x-slot name="heading">❓ Частые вопросы</x-slot>
                <x-slot name="description">Короткие ответы на типичные задачи</x-slot>
                <div class="space-y-3">
                    <details class="group rounded-lg border border-line">
                        <summary class="font-medium cursor-pointer p-3">Почему мероприятие не видно на витрине?</summary>
                        <p class="p-3 text-sm text-gray-600 dark:text-gray-400">
                            Проверьте по порядку: (1) статус мероприятия = <em>Published</em>,
                            (2) есть сеанс со статусом <em>On Sale</em>,
                            (3) у сеанса опубликованная схема и созданный инвентарь,
                            (4) дата сеанса в будущем и внутри окна продаж.
                        </p>
                    </details>
                    <details class="group rounded-lg border border-line">
                        <summary class="font-medium cursor-pointer p-3">Как поменять цену на ряд после старта продаж?</summary>
                        <p class="p-3 text-sm text-gray-600 dark:text-gray-400">
                            Цены зафиксированы схемой на момент создания сеанса. Чтобы изменить цену:
                            создайте новую версию схемы с новыми ценами, привяжите к ней <strong>новый сеанс</strong>.
                            Уже открытые сеансы продолжают продаваться по старым ценам.
                        </p>
                    </details>
                    <details class="group rounded-lg border border-line">
                        <summary class="font-medium cursor-pointer p-3">Что значит «схема не может быть изменена»?</summary>
                        <p class="p-3 text-sm text-gray-600 dark:text-gray-400">
                            Опубликованная схема — неизменяемый документ (так защищаются уже проданные билеты).
                            Используйте <em>Duplicate</em>, чтобы скопировать её в новую версию, отредактировать и опубликовать.
                        </p>
                    </details>
                    <details class="group rounded-lg border border-line">
                        <summary class="font-medium cursor-pointer p-3">Покупатель не получил билеты на почту</summary>
                        <p class="p-3 text-sm text-gray-600 dark:text-gray-400">
                            Проверьте заказ в <em>Orders</em>: если статус <em>Paid</em>, но письмо не ушло —
                            проверьте email покупателя и раздел уведомлений. Билеты также доступны в личном кабинете.
                        </p>
                    </details>
                    <details class="group rounded-lg border border-line">
                        <summary class="font-medium cursor-pointer p-3">Нужен ли интернет контролёру на входе?</summary>
                        <p class="p-3 text-sm text-gray-600 dark:text-gray-400">
                            Нет. Статическая проверка QR работает офлайн: подпись в коде проверяется устройством.
                            Для горячего списка отменённых билетов требуется синхронизация раз в N минут.
                        </p>
                    </details>
                    <details class="group rounded-lg border border-line">
                        <summary class="font-medium cursor-pointer p-3">Как создать событие «стоячий зритель» (танцпол)?</summary>
                        <p class="p-3 text-sm text-gray-600 dark:text-gray-400">
                            В схеме зала добавьте <em>стоячую зону</em> вместо рядов мест.
                            Зона имеет свою цену и вместимость; в заказе продаётся «билет без места».
                        </p>
                    </details>
                    <details class="group rounded-lg border border-line">
                        <summary class="font-medium cursor-pointer p-3">Куда делась выручка?</summary>
                        <p class="p-3 text-sm text-gray-600 dark:text-gray-400">
                            Выручка = сумма успешных платежей (статус <em>succeeded</em>) в разделе <em>Payments</em>.
                            Виджет «Выручка» на дашборде считает именно её. Комиссия платёжного провайдера не вычитается.
                        </p>
                    </details>
                </div>
            </x-filament::section>
        </div>

        {{-- ============================================================
             ГОРЯЧИЕ КЛАВИШИ И СОВЕТЫ
             ============================================================ --}}
        <div>
            <x-filament::section>
                <x-slot name="heading">⌨️ Полезно знать</x-slot>
                <x-slot name="description">Горячие клавиши и правила</x-slot>
                <ul class="list-disc space-y-2 pl-6">
                    <li><kbd class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-xs font-mono">Ctrl K</kbd> / <kbd class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-xs font-mono">⌘ K</kbd> — глобальный поиск по разделам.</li>
                    <li>Тёмная тема переключается иконкой солнца/луны в шапке.</li>
                    <li>Никогда не удаляйте залы и мероприятия, на которые есть заказы — удаление упадёт на внешних ключах. Вместо этого меняйте статус на <em>Archived</em>.</li>
                    <li>Перед запуском крупного события проведите тестовую покупку 2 билетов из разных рядов.</li>
                </ul>
            </x-filament::section>
        </div>

    </div>
</x-filament-panels::page>