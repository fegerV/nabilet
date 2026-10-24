<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Core\Settings\SettingsStore;
use Tests\TestCase;

/**
 * Единственный владелец чтения/записи таблицы `settings`.
 *
 * ЗАЧЕМ ТЕСТ. `values()` / `writeValue()` / `forget()` / `decrypt()` были
 * побайтово одинаковыми в `PaymentSettings`, `MailSettings`, `MatrixSettings` и
 * (без шифрования) в `LoyaltySettingsService`. Правило здесь одно и неочевидное,
 * и оно как раз то, что копии теряют первыми:
 *
 *   1. У таблицы `settings` НЕТ `created_at` — только `updated_at`, NOT NULL
 *      без дефолта. `updateOrCreate()` на INSERT терял метку и падал.
 *   2. Секрет хранится ШИФРОТЕКСТОМ с флагом `encrypted = 1`, а `values()`
 *      отдаёт его всё ещё зашифрованным: расшифровка — решение вызывающего.
 *   3. Нечитаемый шифротекст (сменился APP_KEY) — это «не задано», а не ошибка
 *      запроса.
 *   4. Кэш включается только тому, кто читает в цикле (`MatrixSettings`), и
 *      обязан сбрасываться после записи.
 */
final class SettingsStoreTest extends TestCase
{
    use RefreshDatabase;

    public function testWriteCreatesARowWithAnExplicitUpdatedAt(): void
    {
        $store = new SettingsStore();
        $store->write('test_scope', 'plain', 'hello');

        $row = DB::table('settings')
            ->where('scope', 'test_scope')
            ->where('setting_key', 'plain')
            ->first();

        $this->assertNotNull($row, 'строка не создана');
        // Метка выставлена: у таблицы нет created_at, а updated_at NOT NULL без
        // дефолта — если бы его не ставили явно, INSERT упал бы.
        $this->assertNotNull($row->updated_at, 'updated_at не выставлен');
        $this->assertSame(0, (int) $row->encrypted, 'несекретное значение помечено как шифротекст');
        $this->assertSame('hello', json_decode((string) $row->value_json, true));
    }

    public function testWriteUpdatesAnExistingRowInsteadOfDuplicating(): void
    {
        $store = new SettingsStore();
        $store->write('test_scope', 'plain', 'first');
        $store->write('test_scope', 'plain', 'second');

        $count = DB::table('settings')
            ->where('scope', 'test_scope')
            ->where('setting_key', 'plain')
            ->count();

        $this->assertSame(1, $count, 'второй строки быть не должно');
        $this->assertSame('second', $store->values('test_scope')['plain']);
    }

    public function testEncryptedValuesAreStoredAsCiphertextAndDecryptBack(): void
    {
        $store = new SettingsStore();
        $store->write('test_scope', 'secret', 's3cr3t', encrypted: true);

        $row = DB::table('settings')
            ->where('scope', 'test_scope')
            ->where('setting_key', 'secret')
            ->first();

        $this->assertSame(1, (int) $row->encrypted, 'флаг encrypted не выставлен');

        $stored = json_decode((string) $row->value_json, true);
        $this->assertNotSame('s3cr3t', $stored, 'секрет лежит открытым текстом');

        // values() отдаёт ШИФРОТЕКСТ: расшифровка — решение вызывающего.
        $this->assertSame($stored, $store->values('test_scope')['secret']);
        $this->assertSame('s3cr3t', SettingsStore::decrypt((string) $stored));
    }

    public function testValuesDecodesJsonButKeepsTheRawStringOtherwise(): void
    {
        $store = new SettingsStore();
        $store->write('test_scope', 'number', 42);
        $store->write('test_scope', 'flag', true);
        $store->write('test_scope', 'list', ['a', 'b']);
        $store->write('test_scope', 'text', 'plain');

        $values = $store->values('test_scope');

        $this->assertSame(42, $values['number']);
        $this->assertSame(true, $values['flag']);
        $this->assertSame(['a', 'b'], $values['list']);
        $this->assertSame('plain', $values['text']);
    }

    public function testForgetRemovesOnlyTheNamedKey(): void
    {
        $store = new SettingsStore();
        $store->write('test_scope', 'keep', 'x');
        $store->write('test_scope', 'drop', 'y');

        $store->forget('test_scope', 'drop');

        $values = $store->values('test_scope');
        $this->assertSame('x', $values['keep']);
        $this->assertFalse(array_key_exists('drop', $values), 'ключ не удалён');
    }

    public function testUnreadableCiphertextMeansNotSetRatherThanAnError(): void
    {
        // Сменился APP_KEY: расшифровать нельзя, но валить страницу настроек или
        // приём платежа из-за этого нельзя — «секрет не задан».
        $this->assertNull(SettingsStore::decrypt('not-a-laravel-payload'));
        $this->assertNull(SettingsStore::decrypt(''));
    }

    public function testUncachedStoreSeesChangesMadeBehindItsBack(): void
    {
        $store = new SettingsStore();
        $store->write('test_scope', 'k', 'v1');
        $this->assertSame('v1', $store->values('test_scope')['k']);

        $this->overwriteBehindTheStoresBack('test_scope', 'k', 'v2');

        $this->assertSame('v2', $store->values('test_scope')['k'], 'без кэша значение обязано быть свежим');
    }

    public function testCachedStoreServesTheCacheUntilInvalidated(): void
    {
        $store = new SettingsStore(cached: true);
        $store->write('test_scope', 'k', 'v1');

        // Первое чтение заполняет кэш.
        $this->assertSame('v1', $store->values('test_scope')['k']);

        $this->overwriteBehindTheStoresBack('test_scope', 'k', 'v2');

        // Кэш на то и кэш: пока его не сбросили, значение старое.
        $this->assertSame('v1', $store->values('test_scope')['k']);

        $store->invalidate();

        $this->assertSame('v2', $store->values('test_scope')['k'], 'после invalidate() значение обязано быть свежим');
    }

    public function testValuesIsScopedAndDoesNotLeakBetweenScopes(): void
    {
        $store = new SettingsStore();
        $store->write('scope_a', 'k', 'a');
        $store->write('scope_b', 'k', 'b');

        $this->assertSame(['k' => 'a'], $store->values('scope_a'));
        $this->assertSame(['k' => 'b'], $store->values('scope_b'));
    }

    /** Записать значение в обход стора — так выглядит правка из другого процесса. */
    private function overwriteBehindTheStoresBack(string $scope, string $key, mixed $value): void
    {
        DB::table('settings')
            ->where('scope', $scope)
            ->where('setting_key', $key)
            ->update([
                'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }
}
