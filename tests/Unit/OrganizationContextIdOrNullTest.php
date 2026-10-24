<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Tests\Support\TestCase;

/**
 * `OrganizationContext::idOrNull()` — организация запроса как int или null.
 *
 * Ключевой случай — ПУСТАЯ СТРОКА. Внутри `withoutScope()` контекст выставляет
 * пустую строку как явный системный режим (установщик, миграции, задания).
 * `(int) ''` даёт 0, то есть организацию с номером ноль: запрос ушёл бы с
 * `organization_id = 0`, не нашёл ничего и выглядел бы как «данных нет» вместо
 * «контекста нет». Поэтому здесь null.
 *
 * Метод намеренно мягкий: он про чтение, где отсутствие организации означает
 * «список пуст», а не ошибку. Fail-closed отказ остался у `id()`.
 */
final class OrganizationContextIdOrNullTest extends TestCase
{
    public function testNoContextIsNull(): void
    {
        $this->assertNull((new OrganizationContext())->idOrNull());
    }

    public function testANumericContextIsTheInteger(): void
    {
        $context = new OrganizationContext();
        $context->set('42');

        $this->assertSame(42, $context->idOrNull());
    }

    public function testAnEmptyStringIsNullNotZero(): void
    {
        $context = new OrganizationContext();
        $context->set('');

        $this->assertNull($context->idOrNull(), 'пустая строка не должна становиться организацией 0');
    }

    public function testANonNumericContextIsNull(): void
    {
        $context = new OrganizationContext();
        $context->set('org-not-a-number');

        $this->assertNull($context->idOrNull());
    }

    public function testClearedContextIsNull(): void
    {
        $context = new OrganizationContext();
        $context->set('7');
        $context->clear();

        $this->assertNull($context->idOrNull());
    }

    /**
     * `idOrNull()` и `tryId()` говорят об одном и том же контексте.
     *
     * Расхождение здесь означало бы, что чтение и запись смотрят на разные
     * значения, и «организации нет» в одном месте значило бы «организация 5»
     * в другом.
     */
    public function testItReadsTheSameContextAsTryId(): void
    {
        $context = new OrganizationContext();
        $context->set('5');

        $this->assertSame($context->tryId(), (string) $context->idOrNull());
    }
}
