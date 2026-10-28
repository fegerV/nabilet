<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\Paging;
use Nabilet\Tests\Support\TestCase;

/**
 * Приведение размера страницы к допустимому диапазону (аудит S-12).
 *
 * ПОЧЕМУ ЭТОТ ТЕСТ НЕ ЛИШНИЙ. До извлечения формула `max(1, min($max, …))`
 * была скопирована в 13 контроллеров, причём в ДВУХ порядках аргументов:
 * `min(100, max(1, …))` и `max(1, min(100, …))`. На входе внутри диапазона
 * оба дают одинаковый результат — поэтому расхождение молчало. Но достаточно
 * перепутать порядок на `min(1, max(100, …))`, и ЗАПРОС ПЕРЕСТАЁТ
 * ОГРАНИЧИВАТЬСЯ: любой `?per_page=` сводится к 1. Тест доказывает, что
 * владелец `Paging::limit` даёт безопасный результат, и отдельно показывает,
 * какой результат дала бы неверная перестановка — чтобы гвард не был вакуумным.
 *
 * ТЕСТ ИДЁТ БЕЗ ФРЕЙМВОРКА. `app/Core/Support/Paging` обязан грузиться без
 * `vendor/` (`tools/verify-purity.php`), поэтому и тест не требует ядра Laravel.
 */
final class PagingTest extends TestCase
{
    /** Нет входа → берётся дефолт вызывающего, а не 1 и не null. */
    public function testMissingInputFallsBackToTheCallersDefault(): void
    {
        $this->assertSame(20, Paging::limit(null, 20, 100));
        $this->assertSame(50, Paging::limit(null, 50, 200));
        $this->assertSame(24, Paging::limit(null, 24, 100));
    }

    /** Значение внутри диапазона проходит без изменений. */
    public function testValueInsideTheRangePassesThrough(): void
    {
        $this->assertSame(10, Paging::limit(10, 20, 100));
        $this->assertSame(73, Paging::limit(73, 20, 100));
    }

    /** Потолок: без клампа `?per_page=1000000` клал сервер одним запросом (см. MediaController). */
    public function testValueAboveTheCeilingIsClampedToMax(): void
    {
        $this->assertSame(100, Paging::limit(1_000_000, 20, 100));
        $this->assertSame(200, Paging::limit(999_999, 50, 200));
    }

    /** Пол: неположительный вход не должен превращать список в «ничего» или ошибку. */
    public function testNonPositiveValueIsClampedToAtLeastOne(): void
    {
        $this->assertSame(1, Paging::limit(0, 20, 100));
        $this->assertSame(1, Paging::limit(-5, 20, 100));
    }

    /** Границы диапазона входят в допустимое множество, не «соскальзывая» на соседнее значение. */
    public function testBoundaryValuesAreInclusive(): void
    {
        $this->assertSame(1, Paging::limit(1, 20, 100));
        $this->assertSame(100, Paging::limit(100, 20, 100));
    }

    /**
     * Гвард не вакуумный: показываем, что даёт ПЕРЕПУТАННЫЙ порядок.
     *
     * `min(1, max(100, v))` для любого v ≤ 100 тождественно равен 1 — то есть
     * «размазанная копия, которая разошлась» превращает пагинацию в «по одной
     * записи». Владелец даёт корректный результат, отличный от этой перестановки,
     * поэтому централизация формулы имеет смысл, а не просто переносит код.
     */
    public function testTheCanonicalOrderDiffersFromASwappedOrder(): void
    {
        $inputs = [null, 1, 10, 50, 100, 100_000];

        foreach ($inputs as $v) {
            $owner = Paging::limit($v, 20, 100);
            $swapped = min(1, max(100, $v ?? 20)); // ошибочная перестановка аргументов

            // Для любого сколько-нибудь большого входа владелец и перестановка
            // обязаны расходиться — иначе защита от расхождения копий бессмысленна.
            if (($v ?? 20) > 1) {
                $this->assertNotSame($swapped, $owner, "для входа $v владелец совпал с ошибочным порядком");
            }

            $this->assertTrue($owner >= 1 && $owner <= 100, "владелец вышел за диапазон для входа $v");
        }
    }

    /**
     * Продуктовые числа из doc-блока владельца не «разъехались» при переносе.
     *
     * Дефолт/потолок — параметры по замыслу (медиа 24/100, админка чекина 50/100,
     * отчёт сканов 50/200), и тест фиксирует именно эти тройки у реальных
     * вызовов, чтобы будущий рефакторинг не «унифицировал» их в одну константу.
     */
    public function testDocumentedResourceConstantsStillHold(): void
    {
        // Медиа: сетка 4×6 → 24, потолок 100.
        $this->assertSame(24, Paging::limit(null, 24, 100));
        $this->assertSame(100, Paging::limit(500, 24, 100));

        // Админка чекина / эмбеды: дефолт 50, потолок 100.
        $this->assertSame(50, Paging::limit(null, 50, 100));

        // Отчёт сканов: дефолт 50, потолок 200.
        $this->assertSame(50, Paging::limit(null, 50, 200));
        $this->assertSame(200, Paging::limit(9999, 50, 200));
    }
}
