<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Core\Users\Domain\UserStatus;
use Nabilet\Tests\Support\TestCase;

/**
 * Словарь `users.status`.
 *
 * Проверяется одно свойство, ради которого класс и появился: правило валидации
 * и решение о входе читают ОДИН список значений. Пока словарь был строкой
 * `in:active,inactive,banned` внутри правила контроллера, проверка при входе о
 * нём не знала вовсе — и бан не мешал войти.
 *
 * Второе — fail-closed. Теперь неизвестное значение отвергает и база
 * (`ck_users_status`), но проверка в коде остаётся: она закрывает строки,
 * записанные до появления ограничения, и базы, где оно не применилось.
 */
final class UserStatusTest extends TestCase
{
    public function testTheDictionaryIsTheThreeValuesTheApiAlreadyAccepted(): void
    {
        $this->assertSame(['active', 'inactive', 'banned'], UserStatus::all());
    }

    public function testTheValidationRuleIsBuiltFromTheSameList(): void
    {
        // Одно определение — два потребителя. Разойтись они теперь не могут.
        $this->assertSame('in:active,inactive,banned', UserStatus::validationRule());
        $this->assertSame(
            UserStatus::all(),
            explode(',', substr(UserStatus::validationRule(), strlen('in:')))
        );
    }

    public function testOnlyActiveMayAuthenticate(): void
    {
        $this->assertTrue(UserStatus::mayAuthenticate(UserStatus::ACTIVE));
        $this->assertFalse(UserStatus::mayAuthenticate(UserStatus::INACTIVE));
        $this->assertFalse(UserStatus::mayAuthenticate(UserStatus::BANNED));
    }

    public function testAnUnknownValueFailsClosed(): void
    {
        $this->assertFalse(UserStatus::mayAuthenticate('suspended'));
        $this->assertFalse(UserStatus::mayAuthenticate('ACTIVE'));
        $this->assertFalse(UserStatus::mayAuthenticate(''));
        $this->assertFalse(UserStatus::mayAuthenticate(null));
    }
}
