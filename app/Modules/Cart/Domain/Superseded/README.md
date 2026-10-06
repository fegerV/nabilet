# Superseded: домен корзины

**Эти классы не участвуют в боевом пути.** Каталог — надгробие, не библиотека.

## Что здесь

| Класс | Что описывал |
|---|---|
| `Cart` | корзина как доменный объект (**не** Eloquent-модель `Cart\Models\Cart`) |
| `CartItem` | строка корзины (**не** Eloquent-модель `Cart\Models\CartItem`) |
| `CartPolicy` | правила допустимости изменений корзины |
| `CartDecision` | результат решения политики |

## Почему здесь

Весь каталог `Domain/` использовался только `tests/Unit/CartTest.php`. Ни один
класс не встречается в `app/` вне самого каталога.

## Где на самом деле живёт логика корзины

- Eloquent-модели — `Nabilet\Modules\Cart\Models\{Cart, CartItem}`;
- правила и лимиты — `CartService` (`addItem`, `removeItem`, `checkout`,
  `extendHold`, `releaseCartInventory`) и `CartController` (лимит из
  `nabilet.checkout.max_items_per_order`);
- токен покупателя — `CartToken` + `CartService::resolveToken()`.

Имена совпадают с боевыми моделями — это и есть причина, по которой каталог
унесён из `Cart\Domain\` в `Superseded\`: чтобы `Cart\Domain\Cart` нельзя было
случайно принять за `Cart\Models\Cart`.

До включения в боевой путь **правка любого файла здесь не меняет поведение
системы**.
