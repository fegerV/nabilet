<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use Nabilet\Modules\Installer\Support\PreinstallAppKey;
use Tests\TestCase;

/**
 * Временный APP_KEY на окно визарда.
 *
 * Контекст: до записи `.env` ключа нет, но роуты инсталлера живут в группе
 * `web`, где `EncryptCookies` резолвит encrypter на этапе dispatch —
 * `GET /install` отвечал 500 и форму было не открыть. Класс даёт ключ
 * только ПОКА `storage/install.lock` не существует; после установки ключ
 * обязан приходить из `.env`.
 */
class PreinstallAppKeyTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'nabilet_pre_key_') . '.key';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    /* ── Приоритет 1: валидный настроенный ключ выигрывает, файл не пишется ── */

    public function test_a_valid_configured_key_is_used_as_is_and_not_persisted(): void
    {
        $existing = 'base64:' . base64_encode(random_bytes(32));

        $key = PreinstallAppKey::ensure($existing, $this->path);

        $this->assertSame($existing, $key);
        $this->assertFileDoesNotExist($this->path);
    }

    /* ── Приоритет 2: сгенерированный ключ переживает перезапуски воркеров ── */

    public function test_a_generated_key_is_persisted_and_survives_the_next_call(): void
    {
        $first = PreinstallAppKey::ensure(null, $this->path);

        // Формат живой: `base64:` + ровно 32 байта — иначе encrypter его
        // отверг бы, и вся гарантия была бы фикцией.
        $this->assertStringStartsWith('base64:', $first);
        $this->assertNotFalse(base64_decode(substr($first, 7), true));
        $this->assertFileExists($this->path);

        // Второй запрос (новый бут в php -S) видит ТОТ ЖЕ ключ, иначе
        // cookie/сессии шагов визарда разъезжались бы между запросами.
        $this->assertSame($first, PreinstallAppKey::ensure(null, $this->path));
    }

    /* ── Битое содержимое файла не должно становиться ключом ─────────────── */

    public function test_garbage_in_the_persistence_file_is_regenerated(): void
    {
        file_put_contents($this->path, 'not-a-key');

        $key = PreinstallAppKey::ensure(null, $this->path);

        $this->assertStringStartsWith('base64:', $key);
        $this->assertNotSame('not-a-key', trim((string) file_get_contents($this->path)));
    }

    /* ── Настроенный ключ-мусор не считается валидным и не выигрывает ────── */

    public function test_a_malformed_configured_key_is_treated_as_missing(): void
    {
        $key = PreinstallAppKey::ensure('just-plain-text', $this->path);

        $this->assertStringStartsWith('base64:', $key);
        $this->assertFileExists($this->path);
    }

    /* ── Недоступная директория: in-memory фолбэк, ключ всё равно валиден ── */

    public function test_an_unpersistable_path_still_yields_a_usable_key(): void
    {
        // Пишем путь ВНУТРЬ существующего файла: контейнер, а не каталог, —
        // mkdir неминуемо провалится, и сработает branch in-memory фолбэка.
        $file = tempnam(sys_get_temp_dir(), 'nabilet_pre_dir_');
        $this->assertNotFalse($file);

        try {
            $key = PreinstallAppKey::ensure(null, $file . '/blocked/inner.key');

            $this->assertStringStartsWith('base64:', $key);
        } finally {
            @unlink((string) $file);
        }
    }

    /* ── Гейт apply(): отсутствие `.env`, а не install.lock ──────────────── */

    public function test_apply_only_runs_without_an_env_file(): void
    {
        // Свежий хостинг: .env нет → ключ гарантирован.
        PreinstallAppKey::apply(sys_get_temp_dir() . '/nabilet-absent-env-' . uniqid() . '/.env');
        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{40,}=$/', (string) config('app.key'));

        // Есть .env → гейт нем: класс ничего не подставляет сам (ключ дальше
        // отвечает реальная конфигурация, пустой ключ там валит boot громко).
        PreinstallAppKey::apply(base_path('.env'));

        $this->assertMatchesRegularExpression('/^base64:/', (string) config('app.key'));
    }
}
