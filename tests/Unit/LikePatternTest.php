<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use InvalidArgumentException;
use Nabilet\Core\Support\LikePattern;
use Nabilet\Tests\Support\TestCase;

/**
 * Экранирование `LIKE` (аудит S-11).
 *
 * ПОЧЕМУ ЭТО НЕ КОСМЕТИКА. `%` и `_` в шаблоне `LIKE` — это СИМВОЛЫ ШАБЛОНА, а
 * не данные. Значение биндится через PDO, поэтому SQL-инъекции нет, но форма
 * запроса оказывается под контролем пользователя:
 *
 *   - `city=%`  → «выбрать всю таблицу»: MySQL читает все строки вместо индекса,
 *                 и такой запрос дёшево повторять;
 *   - `search=a_b` → совпадёт с `aXb`, хотя пользователь искал литеральное `a_b`.
 *
 * Раньше экранирование было ровно в одном из трёх фильтров, и в том единственном
 * — через `addcslashes`, который не сообщает СУБД, какой символ считать
 * экранирующим: при серверном режиме `NO_BACKSLASH_ESCAPES` (обычная настройка
 * managed-хостингов) `\%` снова означает «любые символы».
 *
 * ТЕСТ ИДЁТ БЕЗ ФРЕЙМВОРКА. `app/Core/Support` обязан загружаться и работать без
 * `vendor/` (`tools/verify-purity.php`), поэтому класс обязан быть чистым, а его
 * тест — не требовать ядра Laravel.
 */
final class LikePatternTest extends TestCase
{
    public function testEscapesTheThreeWildcardCharacters(): void
    {
        $this->assertSame('\\%', LikePattern::escape('%'));
        $this->assertSame('\\_', LikePattern::escape('_'));
        $this->assertSame('\\\\', LikePattern::escape('\\'));
    }

    /**
     * Замена идёт ОДНИМ проходом — слэш не экранируется дважды.
     *
     * Порядок и количество проходов — не деталь: при наивной последовательной
     * замене (`str_replace('\\', '\\\\')` последним) слэш заэкранировал бы
     * слэши, только что добавленные перед `%` и `_`, и в запрос ушло бы `\\%`
     * вместо `\%` — то есть «литеральный слэш, затем ШАБЛОН». Это не ошибка
     * выполнения, а тихо неверная выдача, поэтому проверяется явно.
     */
    public function testTheEscapingIsASinglePass(): void
    {
        // Пользователь ввёл `\%` — «литеральный слэш, затем процент».
        // Ожидаем ровно: экранированный слэш + экранированный процент.
        $this->assertSame('\\\\\\%', LikePattern::escape('\\%'));

        // Тот же результат, собранный по частям: сначала слэш, потом процент.
        $this->assertSame(LikePattern::escape('\\').LikePattern::escape('%'), LikePattern::escape('\\%'));
    }

    public function testContainsWrapsTheEscapedValue(): void
    {
        $this->assertSame('%Алма%', LikePattern::contains('Алма'));
        $this->assertSame('%\\%%', LikePattern::contains('%'));
        $this->assertSame('%a\\_b%', LikePattern::contains('a_b'));
    }

    public function testStartsWithAppendsTheWildcardOnly(): void
    {
        $this->assertSame('Алма%', LikePattern::startsWith('Алма'));
        $this->assertSame('\\%%', LikePattern::startsWith('%'));
    }

    /** Юникод не ломается: экранирование — побайтовые замены, а не посимвольные. */
    public function testMultibyteValuesSurvive(): void
    {
        $this->assertSame('%Санкт-Петербург%', LikePattern::contains('Санкт-Петербург'));
        $this->assertSame('%東京%', LikePattern::contains('東京'));
    }

    public function testEmptyValueIsAnEmptyPattern(): void
    {
        $this->assertSame('%%', LikePattern::contains(''));
        $this->assertSame('%', LikePattern::startsWith(''));
    }

    /**
     * `ESCAPE` указывается ЯВНО и совпадает с `ESCAPE_CHARACTER`.
     *
     * Расхождение здесь — молчаливая поломка: экранирование выглядело бы
     * сделанным, а СУБД считала бы экранирующим другой символ.
     */
    public function testExpressionDeclaresTheEscapeCharacter(): void
    {
        $sql = LikePattern::expression('customer_email');

        $this->assertSame("customer_email LIKE ? ESCAPE '\\\\'", $sql);
        $this->assertStringContainsString(
            LikePattern::ESCAPE_CHARACTER,
            $sql,
            'символ из ESCAPE_CHARACTER обязан совпадать с ESCAPE в SQL'
        );
    }

    public function testExpressionAcceptsAQualifiedColumnName(): void
    {
        $this->assertSame("venues.city LIKE ? ESCAPE '\\\\'", LikePattern::expression('venues.city'));
    }

    /**
     * Имя колонки в `expression()` подставляется в SQL строкой.
     *
     * Все вызовы сегодня литеральные, поэтому это защита от БУДУЩЕГО
     * рефакторинга: если имя однажды приедет из конфигурации или запроса,
     * отсутствие проверки превратит `LIKE` в точку инъекции.
     */
    public function testExpressionRefusesANonIdentifier(): void
    {
        $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => LikePattern::expression('city; DROP TABLE venues'),
        );

        $this->assertThrows(
            InvalidArgumentException::class,
            static fn () => LikePattern::expression('city LIKE ?'),
        );
    }
}
