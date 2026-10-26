<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Auth\Domain\OAuthState;
use Nabilet\Tests\Support\TestCase;

/**
 * `state` OAuth связывает начало входа с его завершением — и только это.
 *
 * ЧТО ЗДЕСЬ ПРОВЕРЯЕТСЯ И ПОЧЕМУ
 *
 *   Без сверки `state` callback принимает любой валидный `code`, а значит
 *   злоумышленник может начать вход под своим аккаунтом и подсунуть ссылку
 *   жертве: жертва окажется в ЧУЖОМ кабинете (login CSRF). Тесты фиксируют обе
 *   стороны правила: выданное значение принимается, всё остальное — нет.
 *
 *   Отдельно проверяется форма отказа при `null` с любой стороны: «куки нет»
 *   (вход начат не нами или истёк) и «параметра нет» одинаково означают, что
 *   связать начало с завершением нечем. Наивная реализация с `hash_equals()`
 *   напрямую на `null` дала бы TypeError, то есть 500 вместо отказа.
 *
 *   Энтропия и различимость: два вызова `issue()` обязаны давать разные
 *   значения, иначе повторный вход в той же вкладке переиспользовал бы `state`,
 *   и одноразовость стала бы фикцией.
 */
final class OAuthStateTest extends TestCase
{
    public function test_an_issued_state_matches_itself(): void
    {
        $state = OAuthState::issue();

        static::assertTrue(OAuthState::matches($state, $state));
    }

    /** Два вызова подряд — разные значения. */
    public function test_every_issued_state_is_unique(): void
    {
        $seen = [];

        for ($i = 0; $i < 20; $i++) {
            $seen[] = OAuthState::issue();
        }

        static::assertCount(20, array_unique($seen), 'state обязан быть одноразовым и неповторяемым');
    }

    /**
     * Форма значения задана константой, а не «как получится».
     *
     * `BYTES * 2` hex-символов: значение уходит в URL и в куку, поэтому мусор
     * произвольной длины здесь означал бы, что кто-то заменил генератор.
     */
    public function test_the_state_has_the_declared_shape(): void
    {
        $state = OAuthState::issue();

        static::assertSame(OAuthState::BYTES * 2, strlen($state));
        static::assertSame(1, preg_match('/^[0-9a-f]+$/', $state), 'state — hex, чтобы жить в URL без кодирования');
    }

    public function test_a_foreign_state_never_matches(): void
    {
        static::assertFalse(OAuthState::matches(OAuthState::issue(), OAuthState::issue()));
        static::assertFalse(OAuthState::matches('abc', 'abd'));
        static::assertFalse(OAuthState::matches('ABC', 'abc'), 'сравнение регистрозависимое');
    }

    /**
     * Отсутствие любой из сторон — отказ, а не исключение.
     *
     * `null` приходит из `$request->cookie()`, когда куки нет: браузер сменился,
     * вход начат не нами или кука истекла. Это штатный отказ, и он обязан быть
     * `false`, а не падением.
     */
    public function test_a_missing_side_is_a_refusal_not_a_crash(): void
    {
        $state = OAuthState::issue();

        static::assertFalse(OAuthState::matches(null, $state));
        static::assertFalse(OAuthState::matches($state, null));
        static::assertFalse(OAuthState::matches(null, null));
        static::assertFalse(OAuthState::matches('', $state));
        static::assertFalse(OAuthState::matches($state, ''));
        static::assertFalse(OAuthState::matches('', ''));
    }
}
