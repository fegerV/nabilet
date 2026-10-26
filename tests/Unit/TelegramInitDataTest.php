<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Auth\Domain\SocialIdentity;
use Nabilet\Modules\Auth\Domain\SocialProvider;
use Nabilet\Modules\Auth\Domain\TelegramInitData;
use Nabilet\Tests\Support\TestCase;

/**
 * Подпись Telegram `initData` проверяется ровно одним классом — и проверяется
 * по документации, а не «как получилось».
 *
 * ПОЧЕМУ КАЖДЫЙ ТЕСТ ЗДЕСЬ ЕСТЬ
 *
 *   Спека (§17) требует: `initData` валидируется server-side ДО использования
 *   данных пользователя, `initDataUnsafe` доверенным источником не является.
 *   Проверка подписи — единственное, что отделяет «Telegram сказал, что это
 *   пользователь 42» от «кто-то прислал JSON с числом 42». Поэтому тесты
 *   проверяют не «функция вернула массив», а ОТКАЗ в каждом случае, когда
 *   доверять нельзя:
 *
 *   1. подпись не сходится (правка любого поля после подписи);
 *   2. подпись посчитана ДРУГИМ ботом (чужой bot token);
 *   3. поля `hash` нет вовсе;
 *   4. `auth_date` протух — иначе перехваченный `initData` работал бы вечно;
 *   5. `auth_date` отсутствует — «нет срока» не значит «бессрочно»;
 *   6. пустой `initData` или пустой токен.
 *
 *   Отдельно фиксируется форма `user`: Telegram присылает его JSON-СТРОКОЙ
 *   внутри form-urlencoded. Если развернуть её в `user[id]=…`, подпись может
 *   сойтись, а полезная нагрузка развалится — поэтому `sign()` нормализует поля
 *   в строки до сборки, и тест требует, чтобы `verify()` вернул МАССИВ.
 *
 *   `sign()` существует только для тестов: он строит вход по документации и
 *   подаёт его `verify()`. Если бы тест собирал подпись руками, он повторил бы
 *   ошибку реализации и не поймал бы ничего.
 */
final class TelegramInitDataTest extends TestCase
{
    private const BOT_TOKEN = '123456789:AAHtest-token-for-unit-tests';

    private const NOW = 1_760_000_000;

    /** @param array<string, mixed> $extra */
    private function initData(array $extra = [], int $authDate = self::NOW): string
    {
        return TelegramInitData::sign(
            $extra + [
                'auth_date' => $authDate,
                'query_id' => 'AAHtest',
                'user' => ['id' => 42, 'first_name' => 'Иван', 'last_name' => 'Петров', 'username' => 'ivan'],
            ],
            self::BOT_TOKEN,
        );
    }

    public function test_a_correctly_signed_payload_is_accepted_and_user_is_decoded(): void
    {
        $payload = TelegramInitData::verify($this->initData(), self::BOT_TOKEN, self::NOW);

        static::assertNotNull($payload, 'Подпись, собранная по документации, обязана сходиться.');
        static::assertSame('AAHtest', $payload['query_id'] ?? null);
        static::assertTrue(is_array($payload['user'] ?? null), '`user` обязан вернуться массивом, а не строкой.');
        static::assertSame(42, $payload['user']['id'] ?? null);
        static::assertSame('Иван', $payload['user']['first_name'] ?? null);
    }

    /**
     * Правка поля после подписи — отказ.
     *
     * Это и есть атака, от которой защищает подпись: подменить `user.id`, не
     * зная bot token, невозможно. Если бы `verify()` сравнивал что-то кроме
     * HMAC по всем полям, тест бы этого не заметил.
     */
    public function test_any_tampered_field_is_rejected(): void
    {
        $initData = $this->initData();

        // Меняем ЗНАЧЕНИЕ существующего поля, оставляя старый `hash`.
        $tampered = str_replace('AAHtest', 'AAHother', $initData);

        static::assertNotSame($initData, $tampered, 'предусловие: подмена действительно произошла');
        static::assertNull(TelegramInitData::verify($tampered, self::BOT_TOKEN, self::NOW));
    }

    /** Подпись чужим ботом не подходит: ключ HMAC выводится из bot token. */
    public function test_a_signature_from_another_bot_is_rejected(): void
    {
        $initData = $this->initData();

        static::assertNull(TelegramInitData::verify($initData, '999999:another-bot-token', self::NOW));
    }

    public function test_a_payload_without_hash_is_rejected(): void
    {
        $initData = 'auth_date=' . self::NOW . '&user=' . rawurlencode('{"id":42}');

        static::assertNull(TelegramInitData::verify($initData, self::BOT_TOKEN, self::NOW));
    }

    /**
     * Протухший `auth_date` — отказ.
     *
     * Срок — не украшение: без него `initData`, попавший в историю браузера или
     * в лог, оставался бы действующим ключом доступа навсегда.
     */
    public function test_a_stale_auth_date_is_rejected(): void
    {
        $initData = $this->initData([], self::NOW - TelegramInitData::DEFAULT_MAX_AGE_SECONDS - 1);

        static::assertNull(TelegramInitData::verify($initData, self::BOT_TOKEN, self::NOW));
    }

    /** Свежий `auth_date` принимается — иначе тест выше проходил бы «за счёт всего». */
    public function test_a_fresh_auth_date_at_the_boundary_is_accepted(): void
    {
        $initData = $this->initData([], self::NOW - TelegramInitData::DEFAULT_MAX_AGE_SECONDS);

        static::assertNotNull(
            TelegramInitData::verify($initData, self::BOT_TOKEN, self::NOW),
            'Ровно на границе срока подпись ещё действительна.',
        );
    }

    /**
     * Отсутствие `auth_date` — отказ, а не «бессрочно».
     *
     * `(int) null === 0`, поэтому наивная реализация пропустила бы такую
     * нагрузку: `$now - 0` больше любого срока только если считать всерьёз —
     * но именно поэтому проверка на `<= 0` здесь отдельная.
     */
    public function test_a_payload_without_auth_date_is_rejected(): void
    {
        $initData = TelegramInitData::sign(['user' => ['id' => 42]], self::BOT_TOKEN);

        static::assertNull(TelegramInitData::verify($initData, self::BOT_TOKEN, self::NOW));
    }

    public function test_empty_input_or_empty_token_is_rejected(): void
    {
        static::assertNull(TelegramInitData::verify('', self::BOT_TOKEN, self::NOW));
        static::assertNull(TelegramInitData::verify($this->initData(), '', self::NOW));
    }

    /**
     * Идентичность из проверенной нагрузки; `null`, если подтверждать нечего.
     *
     * Telegram не отдаёт e-mail — пользователь создаётся без адреса. Тест
     * фиксирует и это (`email === null`), и отказ при отсутствии `user.id`:
     * иначе вход создал бы пользователя, о котором не известно ничего.
     */
    public function test_identity_from_telegram_carries_no_email_and_requires_an_id(): void
    {
        $payload = TelegramInitData::verify($this->initData(), self::BOT_TOKEN, self::NOW);
        static::assertNotNull($payload);

        $identity = SocialIdentity::fromTelegram($payload);
        static::assertNotNull($identity);

        static::assertSame(SocialProvider::TELEGRAM, $identity->provider);
        static::assertSame('42', $identity->providerUserId);
        static::assertNull($identity->email, 'Telegram не отдаёт почту — аккаунт заводится без адреса.');
        static::assertSame('Иван Петров', $identity->fullName());

        static::assertNull(SocialIdentity::fromTelegram([]), 'Без `user` подтверждать нечего.');
        static::assertNull(SocialIdentity::fromTelegram(['user' => ['id' => '']]), 'Пустой id — не идентичность.');
        static::assertNull(SocialIdentity::fromTelegram(['user' => 'строка']), '`user` не массив — не идентичность.');
    }

    /** Пустой внешний id — исключение в конструкторе, а не «пользователь без id». */
    public function test_an_identity_without_a_provider_user_id_cannot_be_constructed(): void
    {
        static::assertThrows(
            \InvalidArgumentException::class,
            static fn (): SocialIdentity => new SocialIdentity(provider: SocialProvider::VK, providerUserId: ''),
        );
    }

    /**
     * Словарь провайдеров — один и в одном месте.
     *
     * `whereIn('provider', [...])` в маршруте, `SocialProvider::all()` в реестре
     * и строки в контроллере обязаны значить одно и то же. Тест фиксирует состав
     * словаря: добавление провайдера без регистрации клиента станет видно здесь,
     * а не как 404 на живом callback.
     */
    public function test_the_provider_vocabulary_is_exactly_the_three_supported_ones(): void
    {
        static::assertSame(['yandex', 'vk', 'telegram'], SocialProvider::all());

        foreach (SocialProvider::all() as $provider) {
            static::assertTrue(SocialProvider::isSupported($provider));
        }

        static::assertFalse(SocialProvider::isSupported('google'));
        static::assertFalse(SocialProvider::isSupported(''));
    }
}
