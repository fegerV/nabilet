<?php

declare(strict_types=1);

namespace Nabilet\Modules\Embed\Services;

use Carbon\CarbonImmutable;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Support\Sha256Hex;
use Nabilet\Modules\Embed\Domain\EmbedScope;
use Nabilet\Modules\Security\Domain\ApiKey as DomainApiKey;
use Nabilet\Modules\Security\Domain\ApiKeyDecision;
use Nabilet\Modules\Security\Domain\ApiKeyPolicy;
use Nabilet\Modules\System\Models\ApiKey;

/**
 * Единственная точка выпуска embed-токенов.
 *
 * ЗАЧЕМ ОН ПОЯВИЛСЯ. `EmbedAccessService` проверял токен с самого начала, но
 * выпускать его было нечем: `api_keys` со скоупом `embed` не писал НИ ОДИН код
 * в приложении. Колонки `key_hash`, `key_prefix`, `scopes_json` описывали
 * возможность, которой не было, — ровно тот же дефект, что был у
 * `checkin_devices.device_token_hash` до `DeviceTokenIssuer`. Практическое
 * следствие: весь `/api/v1/embed/*` отвечал 403 `EMBED_TOKEN_REQUIRED` на любой
 * запрос, потому что предъявить было нечего, и включить встраивание было
 * невозможно никакой настройкой.
 *
 * ПОЧЕМУ ТОКЕН ПОКАЗЫВАЕТСЯ ОДИН РАЗ. В базе лежит только
 * `Sha256Hex::of($token)`, и поиск идёт по нему же. Плейнтекста нет ни в одной
 * колонке, поэтому «показать токен ещё раз» не реализуемо в принципе: потеря —
 * это отзыв ключа и выпуск нового.
 *
 * ПОЧЕМУ ПРАВИЛА ВЫПУСКА НЕ ЗДЕСЬ. «Ключ обязан принадлежать организации, иметь
 * срок и хотя бы один скоуп» — это `ApiKeyPolicy::issueDecision()`, и она уже
 * написана и покрыта тестами. Проверять то же самое второй раз здесь значило бы
 * завести второй ответ на вопрос «можно ли выпускать ключ», и первая же правка
 * политики разошлась бы с ним молча.
 *
 * ПОЧЕМУ `bin2hex(random_bytes())`, А НЕ `Str::random()`. Так уже выпускают
 * остальные секреты в чистых местах (`QrSigner`, `SessionToken`, `OAuthState`).
 * 32 байта дают 64 hex-символа, что влезает в `key_prefix VARCHAR(20)` по
 * префиксной части и не ограничено ничем в части секретной.
 */
final class EmbedTokenIssuer
{
    /** Видно в логах, в grep'е и в списке ключей: `nbem_` — embed. */
    public const TOKEN_PREFIX = 'nbem_';

    /** 32 байта → 64 hex-символа секретной части. */
    private const TOKEN_BYTES = 32;

    /**
     * Сколько символов секрета показывать в `key_prefix`.
     *
     * `api_keys.key_prefix` — `VARCHAR(20)`. Пять символов префикса плюс восемь
     * символов секрета дают 13: ключ узнаваем в списке, а показанного куска
     * недостаточно, чтобы его подобрать.
     */
    private const DISPLAY_BODY_LENGTH = 8;

    public function __construct(
        private readonly ApiKeyPolicy $keys,
    ) {}

    /**
     * Выпустить embed-ключ и вернуть его открытый токен — единственный раз.
     *
     * @return array{key: ApiKey, token: string}
     *
     * @throws ValidationError когда политика отказывает в выпуске
     */
    public function issue(int $organizationId, string $name, \DateTimeInterface $expiresAt): array
    {
        $expires = CarbonImmutable::instance($expiresAt);

        $this->assertIssuable($organizationId, $expires);

        $token = self::generateToken();

        $key = ApiKey::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'key_prefix' => self::displayPrefix($token),
            'key_hash' => Sha256Hex::of($token),
            // Не `['embed']` литералом: словарь скоупа объявлен один раз в
            // EmbedScope, и выпуск обязан брать его оттуда — иначе выданный
            // ключ и проверка разойдутся по опечатке.
            'scopes_json' => EmbedScope::all(),
            'expires_at' => $expires,
        ]);

        return ['key' => $key, 'token' => $token];
    }

    /**
     * Отозвать ключ. Необратимо — по решению `ApiKeyPolicy::revokeDecision()`.
     *
     * Строка не удаляется: `revoked_at` — единственный след того, что ключ
     * когда-то работал, а истории в таблице нет. Повторный отзыв политика
     * запрещает (`noChange`), потому что перезапись даты выглядела бы новым
     * событием в таблице, которая их не различает.
     */
    public function revoke(ApiKey $key): ApiKey
    {
        $decision = $this->keys->revokeDecision($key->toDomain());

        if ($decision->isAllowed()) {
            $key->update(['revoked_at' => CarbonImmutable::now()]);
        }

        return $key->fresh() ?? $key;
    }

    /** Секрет вида `nbem_<64 hex>`. */
    public static function generateToken(): string
    {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /** Отображаемая часть токена для колонки `key_prefix`. */
    public static function displayPrefix(string $token): string
    {
        return substr($token, 0, strlen(self::TOKEN_PREFIX) + self::DISPLAY_BODY_LENGTH);
    }

    /**
     * Спросить политику до записи, а не после.
     *
     * Порядок важен: если бы ключ сначала создавался, а потом проверялся,
     * отказ политики оставлял бы в таблице строку, которую нельзя использовать
     * и нельзя отличить от рабочей.
     */
    private function assertIssuable(int $organizationId, CarbonImmutable $expiresAt): void
    {
        $candidate = new DomainApiKey(
            id: 0,
            publicId: '',
            organizationId: $organizationId > 0 ? $organizationId : null,
            name: 'candidate',
            keyPrefix: self::TOKEN_PREFIX,
            keyHash: '',
            createdAt: CarbonImmutable::now(),
            scopes: EmbedScope::all(),
            expiresAt: $expiresAt,
        );

        $decision = $this->keys->issueDecision($candidate, CarbonImmutable::now());

        if ($decision->isAllowed()) {
            return;
        }

        throw new ValidationError(
            [self::fieldFor($decision->verdict) => [(string) $decision->reason]],
            'The embed key cannot be issued.',
            ['verdict' => $decision->verdict],
            'EMBED_KEY_NOT_ISSUABLE',
        );
    }

    /**
     * К какому полю ввода относится отказ политики.
     *
     * Клиент ветвится на `code`, но подсветить поле он может только если ошибка
     * к нему привязана; общее сообщение «нельзя выпустить» заставило бы
     * администратора угадывать, что именно он ввёл не так.
     */
    private static function fieldFor(string $verdict): string
    {
        return match ($verdict) {
            ApiKeyDecision::NO_ORGANIZATION => 'organization_id',
            ApiKeyDecision::NO_EXPIRY, ApiKeyDecision::EXPIRED => 'expires_at',
            default => 'scopes',
        };
    }
}
