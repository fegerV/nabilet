<?php

declare(strict_types=1);

namespace Tests\Feature\Updater;

use Nabilet\Core\Errors\AppError;
use Nabilet\Modules\Updater\Domain\ReleaseManifest;
use Nabilet\Modules\Updater\Services\ManifestVerifier;
use Tests\TestCase;

/**
 * Подпись манифеста — единственный барьер между лентой обновлений и подменой
 * релиза. Тесты проверяют канонические байты, отказ подмены и платформенные
 * проверки; ключи создаются на лету, в репозитории их нет.
 */
final class ManifestVerifierTest extends TestCase
{
    private string $confPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->confPath = sys_get_temp_dir() . '/nabilet-manifest-' . bin2hex(random_bytes(6)) . '.cnf';
        file_put_contents($this->confPath, "[req]\ndistinguished_name = dn\n[dn]\n[openssl_init]\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->confPath);
        parent::tearDown();
    }

    public function test_signed_manifest_is_accepted(): void
    {
        [$private, $public] = $this->keys();
        config(['updates.public_key' => $public, 'updates.channel' => 'stable']);

        $manifest = $this->manifest(str_repeat('a', 64));
        $signed = $this->sign($manifest, $private);

        app(ManifestVerifier::class)->verify($signed);

        $this->assertNotSame('', $signed->signature);
    }

    public function test_unsigned_manifest_is_rejected(): void
    {
        [, $public] = $this->keys();
        config(['updates.public_key' => $public]);

        try {
            app(ManifestVerifier::class)->verify($this->manifest(str_repeat('a', 64)));
            $this->fail('Манифест без подписи не должен приниматься.');
        } catch (AppError $e) {
            $this->assertSame('UPDATE_SIGNATURE_MISSING', $e->errorCode);
        }
    }

    public function test_tampered_payload_breaks_signature(): void
    {
        [$private, $public] = $this->keys();
        config(['updates.public_key' => $public]);
        $signed = $this->sign($this->manifest(str_repeat('a', 64)), $private);

        $tampered = new ReleaseManifest(
            $signed->version, $signed->releasedAt, $signed->channel, $signed->artifact,
            str_repeat('b', 64), $signed->sizeBytes, $signed->phpMin, $signed->phpMax,
            $signed->extensions, $signed->fromVersions, $signed->migrations, $signed->signature,
        );

        try {
            app(ManifestVerifier::class)->verify($tampered);
            $this->fail('Подмена SHA-256 должна ломать подпись.');
        } catch (AppError $e) {
            $this->assertSame('UPDATE_SIGNATURE_INVALID', $e->errorCode);
        }
    }

    public function test_incompatible_platform_and_channel_are_rejected(): void
    {
        [$private, $public] = $this->keys();
        config(['updates.public_key' => $public, 'updates.channel' => 'stable']);

        $incompatible = new ReleaseManifest(
            '1.16.1', '2026-11-27T00:00:00Z', 'stable', 'release.zip', str_repeat('a', 64),
            1000, '99.0.0', '100.0.0', [], [], 0, '',
        );
        $signed = $this->sign($incompatible, $private);
        try {
            app(ManifestVerifier::class)->verify($signed);
            $this->fail('Несовместимый PHP должен отвергаться.');
        } catch (AppError $e) {
            $this->assertSame('UPDATE_PHP_INCOMPATIBLE', $e->errorCode);
        }

        $otherChannel = new ReleaseManifest(
            '1.16.1', '2026-11-27T00:00:00Z', 'beta', 'release.zip', str_repeat('a', 64),
            1000, '8.3.0', '9.0.0', [], [], 0, '',
        );
        try {
            app(ManifestVerifier::class)->verify($this->sign($otherChannel, $private));
            $this->fail('Чужой канал должен отвергаться.');
        } catch (AppError $e) {
            $this->assertSame('UPDATE_CHANNEL_MISMATCH', $e->errorCode);
        }
    }

    private function manifest(string $sha): ReleaseManifest
    {
        return new ReleaseManifest(
            '1.16.1', '2026-11-27T00:00:00Z', 'stable', 'release.zip', $sha,
            1000, '8.3.0', '9.0.0', ['openssl', 'json'], [], 0, '',
        );
    }

    /** @return array{0: string, 1: string} PEM приватного и публичного ключа */
    private function keys(): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $this->confPath];
        $key = openssl_pkey_new($options);
        self::assertNotFalse($key);
        $private = '';
        self::assertTrue(openssl_pkey_export($key, $private, null, ['config' => $this->confPath]));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        return [$private, (string) $details['key']];
    }

    private function sign(ReleaseManifest $manifest, string $privatePem): ReleaseManifest
    {
        $key = openssl_pkey_get_private($privatePem);
        self::assertNotFalse($key);
        $signature = '';
        self::assertTrue(openssl_sign($manifest->canonical(), $signature, $key, OPENSSL_ALGO_SHA256));

        return new ReleaseManifest(
            $manifest->version, $manifest->releasedAt, $manifest->channel, $manifest->artifact,
            $manifest->sha256, $manifest->sizeBytes, $manifest->phpMin, $manifest->phpMax,
            $manifest->extensions, $manifest->fromVersions, $manifest->migrations, base64_encode($signature),
        );
    }
}
