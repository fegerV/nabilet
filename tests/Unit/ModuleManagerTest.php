<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Modules\ModuleManager;
use Nabilet\Core\Modules\ModuleManifest;
use Nabilet\Tests\Support\TestCase;

/**
 * Module graph correctness. If boot order is wrong or a cycle slips through, the
 * failure surfaces as a "class not found" or a null service deep inside a request
 * — far from the actual cause. So the graph is validated up front and refuses to
 * boot when it is incoherent.
 */
final class ModuleManagerTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function manifest(string $name, array $overrides = []): ModuleManifest
    {
        return ModuleManifest::fromArray(array_merge([
            'name' => $name,
            'title' => ucfirst($name),
            'version' => '1.0.0',
            'enabled' => true,
            'requires' => [],
        ], $overrides), '/tmp/' . $name);
    }

    // ------------------------------------------------------------- manifest

    public function testManifestParsesAndDefaults(): void
    {
        $m = $this->manifest('telegram', ['requires' => ['notifications']]);

        $this->assertSame('telegram', $m->name);
        $this->assertSame('Telegram', $m->title);
        $this->assertSame('1.0.0', $m->version);
        $this->assertTrue($m->enabled);
        $this->assertSame(['notifications'], $m->requires);
        $this->assertSame('Telegram', $m->studly());
    }

    public function testManifestRejectsBadName(): void
    {
        $this->assertThrows(
            ValidationError::class,
            fn () => $this->manifest('Bad-Name')
        );
    }

    public function testManifestRejectsBadVersion(): void
    {
        $this->assertThrows(
            ValidationError::class,
            fn () => $this->manifest('telegram', ['version' => 'v1'])
        );
    }

    public function testManifestRejectsSelfDependency(): void
    {
        $this->assertThrows(
            ValidationError::class,
            fn () => $this->manifest('telegram', ['requires' => ['telegram']])
        );
    }

    public function testManifestRejectsNonArrayRequires(): void
    {
        $this->assertThrows(
            ValidationError::class,
            fn () => $this->manifest('telegram', ['requires' => 'notifications'])
        );
    }

    // -------------------------------------------------------------- ordering

    public function testBootOrderRespectsDependencies(): void
    {
        $manager = new ModuleManager();
        $manager->register($this->manifest('payments'));
        $manager->register($this->manifest('yookassa', ['requires' => ['payments']]));
        $manager->register($this->manifest('orders', ['requires' => ['payments']]));

        $order = $manager->bootOrder();

        $this->assertContains('payments', $order);
        $this->assertTrue(
            array_search('payments', $order, true) < array_search('yookassa', $order, true),
            'dependency must boot before dependent'
        );
        $this->assertTrue(
            array_search('payments', $order, true) < array_search('orders', $order, true)
        );
    }

    public function testBootOrderIsDeterministic(): void
    {
        $build = static function (): ModuleManager {
            $manager = new ModuleManager();
            $manager->register(ModuleManifest::fromArray([
                'name' => 'alpha', 'version' => '1.0.0', 'enabled' => true, 'priority' => 50,
            ], '/tmp/alpha'));
            $manager->register(ModuleManifest::fromArray([
                'name' => 'beta', 'version' => '1.0.0', 'enabled' => true, 'priority' => 10,
            ], '/tmp/beta'));
            $manager->register(ModuleManifest::fromArray([
                'name' => 'gamma', 'version' => '1.0.0', 'enabled' => true, 'priority' => 50,
            ], '/tmp/gamma'));

            return $manager;
        };

        $this->assertSame($build()->bootOrder(), $build()->bootOrder());
    }

    public function testLowerPriorityBootsFirst(): void
    {
        $manager = new ModuleManager();
        $manager->register(ModuleManifest::fromArray([
            'name' => 'late', 'version' => '1.0.0', 'enabled' => true, 'priority' => 200,
        ], '/tmp/late'));
        $manager->register(ModuleManifest::fromArray([
            'name' => 'early', 'version' => '1.0.0', 'enabled' => true, 'priority' => 10,
        ], '/tmp/early'));

        $this->assertSame(['early', 'late'], $manager->bootOrder());
    }

    public function testDisabledModulesAreExcludedFromBootOrder(): void
    {
        $manager = new ModuleManager();
        $manager->register($this->manifest('events'));
        $manager->register($this->manifest('ai', ['enabled' => false]));

        $this->assertSame(['events'], $manager->bootOrder());
    }

    public function testDiamondDependencyBootsEachModuleOnce(): void
    {
        $manager = new ModuleManager();
        $manager->register($this->manifest('core_kernel'));
        $manager->register($this->manifest('a', ['requires' => ['core_kernel']]));
        $manager->register($this->manifest('b', ['requires' => ['core_kernel']]));
        $manager->register($this->manifest('c', ['requires' => ['a', 'b']]));

        $order = $manager->bootOrder();

        $this->assertCount(4, $order);
        $this->assertSame(1, count(array_keys($order, 'core_kernel', true)));
        $this->assertSame('c', $order[3]);
    }

    // ------------------------------------------------------------ validation

    public function testMissingDependencyIsReported(): void
    {
        $manager = new ModuleManager();
        $manager->register($this->manifest('yookassa', ['requires' => ['payments']]));

        $problems = $manager->validate();

        $this->assertCount(1, $problems);
        $this->assertSame('missing_dependency', $problems[0]['type']);
    }

    public function testBootOrderRefusesToRunWithMissingDependency(): void
    {
        $manager = new ModuleManager();
        $manager->register($this->manifest('yookassa', ['requires' => ['payments']]));

        $this->assertThrows(DomainRuleViolation::class, static fn () => $manager->bootOrder());
    }

    public function testDisabledDependencyIsReported(): void
    {
        $manager = new ModuleManager();
        $manager->register($this->manifest('payments', ['enabled' => false]));
        $manager->register($this->manifest('yookassa', ['requires' => ['payments']]));

        $problems = $manager->validate();

        $this->assertCount(1, $problems);
        $this->assertSame('disabled_dependency', $problems[0]['type']);
    }

    public function testCycleIsDetected(): void
    {
        $manager = new ModuleManager();
        $manager->register($this->manifest('a', ['requires' => ['b']]));
        $manager->register($this->manifest('b', ['requires' => ['a']]));

        $problems = $manager->validate();
        $types = array_column($problems, 'type');

        $this->assertContains('dependency_cycle', $types);
        $this->assertThrows(DomainRuleViolation::class, static fn () => $manager->bootOrder());
    }

    public function testThreeWayCycleIsDetected(): void
    {
        $manager = new ModuleManager();
        $manager->register($this->manifest('a', ['requires' => ['b']]));
        $manager->register($this->manifest('b', ['requires' => ['c']]));
        $manager->register($this->manifest('c', ['requires' => ['a']]));

        $this->assertContains('dependency_cycle', array_column($manager->validate(), 'type'));
    }

    // ------------------------------------------------------- enable / disable

    public function testEnablingAModuleWithMissingDependenciesIsRefused(): void
    {
        $manager = new ModuleManager();
        $manager->register($this->manifest('payments', ['enabled' => false]));
        $manager->register($this->manifest('yookassa', ['enabled' => false, 'requires' => ['payments']]));

        $this->assertThrows(
            DomainRuleViolation::class,
            static fn () => $manager->setEnabled('yookassa', true)
        );
    }

    public function testEnablingSucceedsOnceDependenciesAreEnabled(): void
    {
        $manager = new ModuleManager();
        $manager->register($this->manifest('payments', ['enabled' => false]));
        $manager->register($this->manifest('yookassa', ['enabled' => false, 'requires' => ['payments']]));

        $manager->setEnabled('payments', true);
        $manager->setEnabled('yookassa', true);

        $this->assertTrue($manager->isEnabled('yookassa'));
        $this->assertSame(['payments', 'yookassa'], $manager->bootOrder());
    }

    /**
     * Disabling a module that others depend on would leave them calling into a
     * service that no longer exists — refused at the source.
     */
    public function testDisablingAModuleOthersDependOnIsRefused(): void
    {
        $manager = new ModuleManager();
        $manager->register($this->manifest('payments'));
        $manager->register($this->manifest('yookassa', ['requires' => ['payments']]));

        $this->assertThrows(
            DomainRuleViolation::class,
            static fn () => $manager->setEnabled('payments', false)
        );
    }

    public function testDisablingAnIndependentModuleWorks(): void
    {
        $manager = new ModuleManager();
        $manager->register($this->manifest('events'));
        $manager->register($this->manifest('ai'));

        $manager->setEnabled('ai', false);

        $this->assertFalse($manager->isEnabled('ai'));
        $this->assertSame(['events'], $manager->bootOrder());
    }

    public function testUnknownModuleOperationsAreRefused(): void
    {
        $manager = new ModuleManager();

        $this->assertThrows(DomainRuleViolation::class, static fn () => $manager->setEnabled('ghost', true));
        $this->assertNull($manager->get('ghost'));
        $this->assertFalse($manager->has('ghost'));
    }

    public function testEnabledDependentsReportsReverseEdges(): void
    {
        $manager = new ModuleManager();
        $manager->register($this->manifest('payments'));
        $manager->register($this->manifest('yookassa', ['requires' => ['payments']]));
        $manager->register($this->manifest('orders', ['requires' => ['payments']]));

        $this->assertSame(['orders', 'yookassa'], $manager->enabledDependents('payments'));
    }

    public function testDiscoveryIgnoresDirectoriesWithoutManifest(): void
    {
        $base = sys_get_temp_dir() . '/nabilet-modules-' . bin2hex(random_bytes(4));
        mkdir($base . '/no-manifest', 0777, true);
        mkdir($base . '/with-manifest', 0777, true);
        file_put_contents($base . '/with-manifest/module.json', json_encode([
            'name' => 'discovered', 'version' => '1.0.0', 'enabled' => true,
        ]));

        $manager = new ModuleManager([$base]);
        $failed = $manager->discover();

        $this->assertSame([], $failed);
        $this->assertTrue($manager->has('discovered'));

        // cleanup
        unlink($base . '/with-manifest/module.json');
        rmdir($base . '/with-manifest');
        rmdir($base . '/no-manifest');
        rmdir($base);
    }

    public function testDiscoveryReportsBrokenManifestWithoutCrashing(): void
    {
        $base = sys_get_temp_dir() . '/nabilet-broken-' . bin2hex(random_bytes(4));
        mkdir($base . '/broken', 0777, true);
        file_put_contents($base . '/broken/module.json', '{not valid json');

        $manager = new ModuleManager([$base]);
        $failed = $manager->discover();

        $this->assertSame(['broken'], $failed);
        $this->assertCount(0, $manager->all());

        unlink($base . '/broken/module.json');
        rmdir($base . '/broken');
        rmdir($base);
    }
}
