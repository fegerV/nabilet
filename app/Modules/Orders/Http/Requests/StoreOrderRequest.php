<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `bail`/`integer` guard the BIGINT comparison. MySQL coerces a string
            // rather than rejecting it (`WHERE id = '1abc'` matches row 1, warning
            // 1292), so without `bail` the `exists` rule would still run and could
            // report a row for a value `integer` already rejected.
            // See `CartController::addItem()` for the measurements.
            // `cart_id` — форма спеки (`CreateOrderRequest`): «создать заказ ИЗ
            // КОРЗИНЫ». Организация и позиции тогда не нужны — они выводится из
            // корзины (`CartCheckoutService::checkoutByPublicId`), а цента всё
            // равно берутся с сервера.
            // `required_without:items` — не «на всякий случай»: так один запрос не
            // может оказаться одновременно «из корзины» и «прямым» — иначе две
            // разные книги заказов соревнуются в одном вызове.
            'cart_id' => ['bail', 'required_without:items', 'string', 'exists:carts,public_id'],
            'organization_id' => ['bail', 'required_without:cart_id', 'integer', 'exists:organizations,id'],
            'user_id' => ['bail', 'nullable', 'integer', 'exists:users,id'],
            'items' => ['required_without:cart_id', 'array', 'min:1'],
            'items.*.inventory_item_id' => ['bail', 'required', 'integer', 'exists:inventory_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10'],
            // `items.*.price` ЗДЕСЬ СОЗНАТЕЛЬНО ОТСУТСТВУЕТ.
            //
            // Цена всегда берётся из `inventory_items.price_amount` — клиент на
            // неё не влияет (`OrderService::createOrder`). Раньше правило
            // `['required','integer','min:0']` стояло, но значение не
            // использовалось: контракт API обещал поле, которого сервер не
            // читает. Это не уязвимость сегодня и мина под завтра.
            //
            // Почему мина. `$request->validated()` возвращает ТОЛЬКО поля, для
            // которых объявлено правило. Пока правило есть, `price` попадает в
            // массив, который уходит в сервис, и достаточно одного рефакторинга
            // (`$order->items()->create($itemData)` вместо поштучного переноса
            // полей), чтобы клиентская цена оказалась рядом с `total_amount` в
            // БД. Убрав правило, мы убираем и поле из `validated()` — то есть
            // закрываем не симптом, а сам канал.
            //
            // Если понадобится сверять цену с клиентом (например, показывать
            // «цена изменилась»), это делается сравнением с серверной ценой и
            // отказом при расхождении, а не доверием присланному числу.
            'promo_code' => ['nullable', 'string', 'max:64'],
            // Сколько бонусов списать — в минорных единицах, как и
            // `MoneyTotals.bonus_amount`. Правило действует ТОЛЬКО в ветке
            // `cart_id`: списание — часть оформления корзины, где есть бонусный
            // счёт покупателя, лимит `BonusPolicy` и строка журнала
            // (`BonusService::spendForOrder`). Прямая форма (`items`) бонусов не
            // знает, и поле в ней отвергается, а не игнорируется: клиент,
            // приславший сумму, должен узнать, что она ни на что не повлияла, а
            // не увидеть полный итог и решить, что списание прошло.
            //
            // `prohibited_if:cart_id,null` — не «на всякий случай»: в прямой
            // форме `cart_id` отсутствует, `Arr::get` даёт `null`, и правило
            // переводит список значений в `[null]`, то есть «запрещено, когда
            // `cart_id` пуст». Отдельного `prohibited_without` в этой версии
            // Laravel нет — проверено `ValidatesAttributes`.
            'bonus_amount' => ['nullable', 'integer', 'min:0', 'max:100000000', 'prohibited_if:cart_id,null'],
            // Реферальный код из ссылки `/r/{code}` (фаза 4). Тот же характер,
            // что у `bonus_amount`: атрибуция — часть оформления корзины, где
            // известны адрес и аккаунт друга (`CartCheckoutService::convert`).
            // Прямая форма (`items`) ссылок не знает, и поле в ней отвергается:
            // клиент, приславший код, должен узнать, что он ни на что не
            // повлиял, а не молча лишиться награды.
            //
            // `max:32` с запасом: код сейчас 16 символов (`ReferralCode`), и
            // запас — на расширение алфавита без ломки контракта.
            'referral_code' => ['nullable', 'string', 'max:32', 'prohibited_if:cart_id,null'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            // Форма спеки (`CreateOrderRequest`) называет поле `holder_name`;
            // приложение исторически принимает `customer_name`. Оба — одно и то
            // же, и оба факультативны в ветке `cart_id` (контакты могли быть
            // уже сохранены в корзине), но обязательны в прямой форме.
            'customer_email' => ['required_without:cart_id', 'email', 'max:255'],
            'customer_name' => ['required_without:cart_id', 'string', 'max:255'],
            'holder_name' => ['bail', 'nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
