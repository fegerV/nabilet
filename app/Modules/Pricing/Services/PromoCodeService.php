<?php

declare(strict_types=1);

namespace Nabilet\Modules\Pricing\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Cart\Models\Cart;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Models\PromoCode;
use Nabilet\Modules\Orders\Models\PromoCodeRedemption;
use Nabilet\Modules\Pricing\Domain\OrderLine;
use Nabilet\Modules\Pricing\Domain\PricingContext;
use Nabilet\Modules\Pricing\Domain\PromoEvaluation;
use Nabilet\Modules\Pricing\Domain\PromoEvaluator;

/**
 * Промокоды: CRUD, валидация против корзины и атомарная фиксация скидки.
 *
 * ПОЧЕМУ СЕРВИС ЖИВЁТ В Pricing, А МОДЕЛЬ — В Orders
 *
 * Таблица `promo_codes` создана в миграции продаж и по смыслу принадлежит
 * заказу: код «расходится» на заказе. Но решение о том, применяется ли код
 * и на сколько, принимает движок цен (`PromoEvaluator`, доменный слой
 * Pricing). Поэтому сервис-оркестратор живёт в Pricing (module.json:
 * provides `promo.applied` / `promo.rejected`), а модели остаются в Orders.
 *
 * ВАЛИДАЦИЯ НЕ РАСХОДУЕТ КОД
 *
 * Контракт (`docs/openapi.yaml`, `/api/v1/promo-codes/validate`) явно говорит:
 * проверка не потребляет код — счётчик `redemptions_count` двигается только
 * при создании заказа (`redeem()`). `evaluateForCart()` — чистая функция без
 * записи: вызывай её хоть сто раз, лимиты не сдвинутся.
 *
 * ОЦЕНКА ИДЁТ ЧЕРЕЗ PromoEvaluator, А НЕ ПОВТОРЯЕТ ЕГО ЛОГИКУ
 *
 * Повтор формулы скидки здесь был бы вторым источником правды: любое
 * изменение правил (§86) пришлось бы делать дважды, и рано или поздно
 * валидация начала бы обещать скидку, которой checkout не даёт.
 */
final class PromoCodeService
{
    public function __construct(private readonly PromoEvaluator $evaluator = new PromoEvaluator())
    {
    }

    /**
     * Список кодов организации (админка).
     */
    public function listForOrganization(int $organizationId, int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        return PromoCode::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->paginate(perPage: max(1, min(100, $perPage)), page: max(1, $page));
    }

    public function findForOrganization(int $organizationId, string $publicId): PromoCode
    {
        $code = PromoCode::query()
            ->where('organization_id', $organizationId)
            ->where('public_id', $publicId)
            ->first();

        if ($code === null) {
            // Сигнатура NotFoundError — (resource, resourceId): класс САМ
            // составляет и сообщение, и код. Ресурс называется в singular
            // snake-case ('promo_code' -> PROMO_CODE_NOT_FOUND), идентификатор
            // уходит в сообщение. Текст сообщения сюда никогда не передаётся:
            // именно так раньше рождались коды вида «CART NOT FOUND._NOT_FOUND».
            throw new NotFoundError('promo_code', $publicId);
        }

        return $code;
    }

    /**
     * Создать код. Нормализация ввода сделана здесь, а не в FormRequest: тот
     * же код должен получаться при создании из админки, из сидов и из тестов.
     *
     * @param array<string, mixed> $input
     */
    public function create(int $organizationId, array $input): PromoCode
    {
        $attributes = $this->normalize($input, partial: false);

        // Уникальность (organization_id, code) обеспечена индексом БД, но
        // предпроверка даёт человекочитаемую ошибку вместо ошибки драйвера.
        $clash = PromoCode::query()
            ->where('organization_id', $organizationId)
            ->where('code', $attributes['code'])
            ->exists();

        if ($clash) {
            throw new ConflictError(
                sprintf('Promo code "%s" already exists.', $attributes['code']),
                'PROMO_CODE_EXISTS'
            );
        }

        $attributes['organization_id'] = $organizationId;

        return PromoCode::create($attributes);
    }

    /**
     * Обновить код (PATCH-семантика: отсутствуют поля — остаются как были).
     *
     * Коллизия (organization_id, code) проверяется и здесь, а не только в
     * create(): без предпроверки переименование на существующий код упиралось
     * бы в ошибку драйвера уникального индекса (500) вместо контрактного
     * PROMO_CODE_EXISTS (409). Исключение — когда код не меняют.
     *
     * @param array<string, mixed> $input
     */
    public function update(PromoCode $code, array $input): PromoCode
    {
        $attributes = $this->normalize($input, partial: true);

        if (isset($attributes['code']) && $attributes['code'] !== $code->code) {
            $clash = PromoCode::query()
                ->where('organization_id', $code->organization_id)
                ->where('code', $attributes['code'])
                ->whereKeyNot($code->id)
                ->exists();

            if ($clash) {
                throw new ConflictError(
                    sprintf('Promo code "%s" already exists.', $attributes['code']),
                    'PROMO_CODE_EXISTS'
                );
            }
        }

        if ($attributes !== []) {
            $code->update($attributes);
        }

        return $code->refresh();
    }

    public function delete(PromoCode $code): void
    {
        // Мягкое удаление: `promo_code_redemptions.promo_code_id` ссылается на
        // эту строку с ON DELETE RESTRICT — жёсткое удаление упало бы на
        // каждом коде, который применили хотя бы раз. SoftDeletes выводит код
        // из оборота, оставляя историю заказов читаемой.
        $code->delete();
    }

    /**
     * Найти код по буквам (публичная валидация и checkout).
     *
     * Сначала точное совпадение в верхнем регистре, затем — как ввели.
     * Колонка `code` в UTF-8 general ci и так не различает регистр, но явный
     * порядок оставляет за нами право сделать её бинарной без сюрпризов.
     */
    public function findByCode(int $organizationId, string $code): ?PromoCode
    {
        $needle = trim($code);

        if ($needle === '') {
            return null;
        }

        return PromoCode::query()
            ->where('organization_id', $organizationId)
            ->where('code', mb_strtoupper($needle))
            ->first()
            ?? PromoCode::query()
                ->where('organization_id', $organizationId)
                ->where('code', $needle)
                ->first();
    }

    /**
     * Проверить код против корзины БЕЗ расхода (POST /promo-codes/validate).
     *
     * @return array{valid: bool, discount_amount: int, rejection_reason: string|null}
     */
    public function validateForCart(PromoCode $code, Cart $cart): array
    {
        return $this->evaluateForCart($code, $cart)->jsonSerialize();
    }

    /**
     * Полная доменная оценка кода против корзины.
     */
    public function evaluateForCart(PromoCode $code, Cart $cart): PromoEvaluation
    {
        [$lines, $priorOrders, $userRedemptions] = $this->buildContext($cart, $code);

        $currency = (string) ($cart->currency ?: 'RUB');

        $context = PricingContext::of(
            lines: $lines,
            currency: $currency,
            customerPriorOrderCount: $priorOrders,
            customerRedemptionsOfCode: $userRedemptions,
        );

        return $this->evaluator->evaluate($code->toDefinition(), $context);
    }

    /**
     * Зафиксировать скидку на заказе: создать redemption и сдвинуть счётчики.
     *
     * ВЫЗЫВАЕТСЯ ИЗНУТРИ ТРАНЗАКЦИИ CHECKOUT
     *
     * Метод сам не открывает транзакцию — он часть чужой (checkout уже внутри
     * `DB::transaction`), и откат заказа обязан откатить и расход кода.
     *
     * АТОМАРНОСТЬ СЧЁТЧИКА
     *
     * `redemptions_count` двигается условным UPDATE (`WHERE redemptions_count
     * < max_redemptions`), а не read-modify-write. Два параллельных checkout
     * на последнем использовании кода иначе прошли бы оба: каждый прочитал
     * «место есть», каждый записал +1, лимит превышен. Условный UPDATE
     * превращает гонку в «повезло одному, второй получает PROMO_CODE_EXHAUSTED».
     *
     * @return int сумма скидки в копейках
     */
    public function redeem(PromoCode $code, Order $order, PromoEvaluation $evaluation, ?int $userId): int
    {
        if (! $evaluation->valid) {
            throw new DomainRuleViolation(
                sprintf('Promo code "%s" cannot be redeemed: %s.', $code->code, (string) $evaluation->rejectionReason),
                'PROMO_CODE_REJECTED'
            );
        }

        $discount = $evaluation->discount->minorUnits();

        $affected = DB::table('promo_codes')
            ->where('id', $code->id)
            ->when(
                $code->max_redemptions !== null,
                static fn (Builder $q): Builder => $q->whereColumn('redemptions_count', '<', 'max_redemptions')
            )
            ->update([
                'redemptions_count' => DB::raw('redemptions_count + 1'),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            throw new ConflictError(
                sprintf('Promo code "%s" has reached its redemption limit.', $code->code),
                'PROMO_CODE_EXHAUSTED'
            );
        }

        PromoCodeRedemption::query()
            ->where('order_id', $order->id)
            ->where('promo_code_id', $code->id)
            ->delete();

        PromoCodeRedemption::create([
            'promo_code_id' => $code->id,
            'order_id' => $order->id,
            'user_id' => $userId,
            'discount_amount' => $discount,
            'redeemed_at' => now(),
        ]);

        return $discount;
    }

    /**
     * Сколько раз ЭТОТ покупатель уже использовал код (per_user_limit).
     */
    public function userRedemptions(PromoCode $code, ?int $userId): int
    {
        if ($userId === null) {
            return 0;
        }

        return PromoCodeRedemption::query()
            ->where('promo_code_id', $code->id)
            ->where('user_id', $userId)
            ->count();
    }

    /**
     * @return array{0: list<OrderLine>, 1: int, 2: int} строки, число прошлых
     *         оплаченных заказов покупателя, число его использований кода
     */
    private function buildContext(Cart $cart, PromoCode $code): array
    {
        $currency = (string) ($cart->currency ?: 'RUB');
        $lines = [];

        $items = $cart->items()->with('inventoryItem.session.event')->get();

        foreach ($items as $item) {
            $session = $item->inventoryItem?->session;

            $lines[] = new OrderLine(
                unitPrice: Money::of((int) $item->unit_price, $currency),
                quantity: max(1, (int) $item->quantity),
                eventId: $session?->event_id === null ? null : (int) $session->event_id,
                eventCategoryId: $session?->event?->category_id === null ? null : (int) $session->event->category_id,
                inventoryItemId: (int) $item->inventory_item_id,
            );
        }

        $userId = $cart->user_id === null ? null : (int) $cart->user_id;

        $priorOrders = 0;

        if ($userId !== null) {
            $priorOrders = Order::query()
                ->where('user_id', $userId)
                ->whereIn('status', ['paid', 'completed', 'refunded', 'partially_refunded'])
                ->count();
        }

        return [$lines, $priorOrders, $this->userRedemptions($code, $userId)];
    }

    /**
     * Нормализация входных данных к колонкам таблицы.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalize(array $input, bool $partial): array
    {
        $out = [];

        if (!$partial || array_key_exists('code', $input)) {
            $codeValue = mb_strtoupper(trim((string) ($input['code'] ?? '')));

            if ($codeValue === '' || mb_strlen($codeValue) > 64) {
                throw new DomainRuleViolation('Promo code must be 1..64 characters.', 'INVALID_PROMO_CODE');
            }

            $out['code'] = $codeValue;
        }

        if (!$partial || array_key_exists('discount_type', $input)) {
            $type = (string) ($input['discount_type'] ?? 'percent');

            if (! in_array($type, ['fixed', 'percent'], true)) {
                throw new DomainRuleViolation('discount_type must be fixed or percent.', 'INVALID_PROMO_TYPE');
            }

            $out['discount_type'] = $type;
        }

        if (!$partial || array_key_exists('value_amount', $input)) {
            $out['value_amount'] = (int) ($input['value_amount'] ?? 0);
        }

        if (!$partial || array_key_exists('value_percent', $input)) {
            $percent = round((float) ($input['value_percent'] ?? 0), 2);

            // DECIMAL(5,2) вмещает не более 999.99, но смысл колонки — процент
            // скидки: максимум 100.00 (домен PromoCodeDefinition режет базисные
            // пункты на 10000). Без этой границы значение 150 молча проходило бы
            // в БД, а движок цен падал бы на нём уже при оценке (непригодный для
            // клиента 500 вместо честного 422 на записи).
            if ($percent < 0 || $percent > 100) {
                throw new DomainRuleViolation('value_percent must be between 0 and 100.', 'INVALID_PROMO_VALUE');
            }

            $out['value_percent'] = number_format($percent, 2, '.', '');
        }

        if (!$partial || array_key_exists('currency', $input)) {
            $out['currency'] = strtoupper((string) ($input['currency'] ?? 'RUB'));
        }

        if (!$partial || array_key_exists('min_order_amount', $input)) {
            $out['min_order_amount'] = (int) ($input['min_order_amount'] ?? 0);
        }

        if (!$partial || array_key_exists('per_user_limit', $input)) {
            $out['per_user_limit'] = max(1, (int) ($input['per_user_limit'] ?? 1));
        }

        if (array_key_exists('max_redemptions', $input)) {
            $out['max_redemptions'] = $input['max_redemptions'] === null ? null : max(1, (int) $input['max_redemptions']);
        }

        if (!$partial || array_key_exists('scope', $input)) {
            $scope = (string) ($input['scope'] ?? 'all');

            if (! in_array($scope, ['all', 'event', 'category', 'first_purchase'], true)) {
                throw new DomainRuleViolation('scope must be all, event, category or first_purchase.', 'INVALID_PROMO_SCOPE');
            }

            $out['scope'] = $scope;
        }

        if (array_key_exists('event_id', $input)) {
            $out['event_id'] = $input['event_id'] === null ? null : (int) $input['event_id'];
        }

        if (array_key_exists('event_category_id', $input)) {
            $out['event_category_id'] = $input['event_category_id'] === null ? null : (int) $input['event_category_id'];
        }

        if (array_key_exists('status', $input)) {
            $status = (string) $input['status'];

            if (! in_array($status, ['active', 'paused', 'expired'], true)) {
                throw new DomainRuleViolation('status must be active, paused or expired.', 'INVALID_PROMO_STATUS');
            }

            $out['status'] = $status;
        }

        foreach (['valid_from', 'valid_until'] as $dateKey) {
            if (array_key_exists($dateKey, $input)) {
                $out[$dateKey] = $input[$dateKey] === null ? null : \Carbon\CarbonImmutable::parse((string) $input[$dateKey]);
            }
        }

        // Перекрёстные требования контракта: percent требует value_percent > 0,
        // scope=event требует event_id (иначе «скидка на событие» молча
        // становится «скидкой ни на что»).
        if (($out['discount_type'] ?? null) === 'percent' && isset($out['value_percent']) && (float) $out['value_percent'] <= 0) {
            throw new DomainRuleViolation('A percent promo code requires value_percent greater than zero.', 'INVALID_PROMO_VALUE');
        }

        if (($out['discount_type'] ?? null) === 'fixed' && isset($out['value_amount']) && (int) $out['value_amount'] <= 0) {
            throw new DomainRuleViolation('A fixed promo code requires value_amount greater than zero.', 'INVALID_PROMO_VALUE');
        }

        if (($out['scope'] ?? null) === 'event' && array_key_exists('event_id', $out) && $out['event_id'] === null) {
            throw new DomainRuleViolation('A promo code scoped to an event requires event_id.', 'INVALID_PROMO_SCOPE');
        }

        if (($out['scope'] ?? null) === 'category' && array_key_exists('event_category_id', $out) && $out['event_category_id'] === null) {
            throw new DomainRuleViolation('A promo code scoped to a category requires event_category_id.', 'INVALID_PROMO_SCOPE');
        }

        return $out;
    }
}
