<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\DatabaseInvariants;
use Nabilet\Tests\Support\TestCase;

/** Порог исполнения CHECK вынесен из инсталлятора в общий владелец правила. */
final class DatabaseInvariantsTest extends TestCase
{
    public function testMysqlBefore8016NeedsAnUpgrade(): void
    {
        $this->assertSame('8.0.16', DatabaseInvariants::missingCheckSupport('5.7.44-log'));
        $this->assertSame('8.0.16', DatabaseInvariants::missingCheckSupport('8.0.15'));
    }

    public function testModernMysqlAndMariadbAreFine(): void
    {
        $this->assertNull(DatabaseInvariants::missingCheckSupport('8.0.16'));
        $this->assertNull(DatabaseInvariants::missingCheckSupport('8.4.11'));
        $this->assertNull(DatabaseInvariants::missingCheckSupport('10.2.1-MariaDB'));
        $this->assertNull(DatabaseInvariants::missingCheckSupport('11.4.5-MariaDB'));
    }

    public function testOldMariadbHasItsOwnThreshold(): void
    {
        $this->assertTrue(DatabaseInvariants::isMariaDb('10.1.48-MariaDB'));
        $this->assertSame('10.2.1', DatabaseInvariants::missingCheckSupport('10.1.48-MariaDB'));
    }

    public function testUnknownVersionDoesNotProduceAFalseAlarm(): void
    {
        $this->assertNull(DatabaseInvariants::missingCheckSupport(''));
    }
}
