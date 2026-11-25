<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Updater\Domain\ReleaseManifest;
use Nabilet\Modules\Updater\Domain\UpdateStatus;
use Nabilet\Tests\Support\TestCase;

/**
 * Чистая часть self-update: формат подписи и правила переходов.
 *
 * Эти правила проверяются без Laravel и БД — ровно поэтому они и вынесены в
 * Domain. Ошибка в канонической строке ломает все ранее подписанные релизы,
 * а ошибка в переходах позволяет тику применить один шаг дважды.
 */
final class UpdateDomainTest extends TestCase
{
    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'version' => '1.17.0',
            'released_at' => '2026-11-25T12:00:00Z',
            'channel' => 'stable',
            'artifact' => 'nabilet-hosting-v1.17.0.zip',
            'sha256' => str_repeat('a', 64),
            'size_bytes' => 17406336,
            'php' => [
                'min' => '8.3.0',
                'max_exclusive' => '9.0.0',
                'extensions' => ['zip', 'pdo', 'openssl'],
            ],
            'from_versions' => ['1.16.0'],
            'migrations' => 2,
            'signature' => 'c2lnbmF0dXJl',
        ];
    }

    public function testCanonicalStringMatchesReleaseBuilderOrder(): void
    {
        $manifest = ReleaseManifest::fromArray($this->payload());

        $expected = implode("\n", [
            'NABILET-UPDATE-V1',
            'schema=1',
            'version=1.17.0',
            'released_at=2026-11-25T12:00:00Z',
            'channel=stable',
            'artifact=nabilet-hosting-v1.17.0.zip',
            'sha256=' . str_repeat('a', 64),
            'size_bytes=17406336',
            'php_min=8.3.0',
            'php_max=9.0.0',
            // Сборщик сортирует расширения, и домен обязан делать то же самое.
            'extensions=openssl,pdo,zip',
            'from_versions=1.16.0',
            'migrations=2',
        ]);

        $this->assertSame($expected, $manifest->canonical());
    }

    public function testDowngradeAndUnknownVersionAreRejected(): void
    {
        $manifest = ReleaseManifest::fromArray($this->payload());

        $this->assertTrue($manifest->appliesTo('1.16.0'));
        $this->assertFalse($manifest->appliesTo('1.17.0'));
        $this->assertFalse($manifest->appliesTo('1.18.0'));
        $this->assertFalse($manifest->appliesTo('1.15.0'));
    }

    public function testEmptyFromVersionsAllowsAnyOlderVersionButNotDowngrade(): void
    {
        $payload = $this->payload();
        $payload['from_versions'] = [];
        $manifest = ReleaseManifest::fromArray($payload);

        $this->assertTrue($manifest->appliesTo('1.0.0'));
        $this->assertFalse($manifest->appliesTo('1.17.0'));
    }

    public function testMalformedChecksumIsRejected(): void
    {
        $payload = $this->payload();
        $payload['sha256'] = '../etc/passwd';

        $thrown = false;
        try {
            ReleaseManifest::fromArray($payload);
        } catch (\InvalidArgumentException) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
    }

    public function testStateMachineAllowsOnlyDeclaredTransitions(): void
    {
        $this->assertTrue(UpdateStatus::canTransition(UpdateStatus::PREPARING, UpdateStatus::READY));
        $this->assertTrue(UpdateStatus::canTransition(UpdateStatus::READY, UpdateStatus::APPLYING));
        $this->assertTrue(UpdateStatus::canTransition(UpdateStatus::APPLYING, UpdateStatus::MIGRATING));
        $this->assertTrue(UpdateStatus::canTransition(UpdateStatus::MIGRATING, UpdateStatus::VERIFYING));
        $this->assertTrue(UpdateStatus::canTransition(UpdateStatus::VERIFYING, UpdateStatus::DONE));
        $this->assertTrue(UpdateStatus::canTransition(UpdateStatus::DONE, UpdateStatus::ROLLING_BACK));

        // Повтор тика не может «перепрыгнуть» шаг или обратить обновление вспять.
        $this->assertFalse(UpdateStatus::canTransition(UpdateStatus::READY, UpdateStatus::MIGRATING));
        $this->assertFalse(UpdateStatus::canTransition(UpdateStatus::DONE, UpdateStatus::PREPARING));
        $this->assertFalse(UpdateStatus::canTransition(UpdateStatus::ROLLED_BACK, UpdateStatus::DONE));
        $this->assertTrue(UpdateStatus::isActive(UpdateStatus::APPLYING));
        $this->assertFalse(UpdateStatus::isActive(UpdateStatus::DONE));
    }
}
