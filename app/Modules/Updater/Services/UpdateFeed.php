<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nabilet\Core\Errors\AppError;
use Nabilet\Modules\Updater\Domain\ReleaseManifest;

/**
 * Лента обновлений. Один разрешённый источник — HTTPS-адрес из конфига.
 *
 * Сеть не вызывается на каждый запрос админки: результат кладётся в кэш на
 * 15 минут (NABILET_UPDATE_CHECK_CACHE_TTL). Отказ ленты НЕ ломает страницу —
 * в ответе честно пишется причина, а ручная загрузка остаётся доступной.
 */
final class UpdateFeed
{
    public function __construct(private readonly ManifestVerifier $verifier)
    {
    }

    /** @return array<string, mixed> */
    public function check(): array
    {
        $ttl = (int) config('updates.cache_ttl_seconds', 900);
        $current = (string) config('nabilet.version', '0.0.0');

        if ($ttl <= 0) {
            return $this->buildCheck($current);
        }

        $key = (string) config('updates.check_cache_key', 'updates:feed:check');
        /** @var array<string, mixed> $result */
        $result = Cache::remember($key, $ttl, fn (): array => $this->buildCheck($current));

        return $result;
    }

    public function fetch(): ReleaseManifest
    {
        $url = (string) config('updates.feed_url', '');
        if ($url === '' || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new AppError(
                'Лента обновлений не настроена или использует небезопасный протокол.',
                'UPDATE_FEED_NOT_CONFIGURED',
                503,
            );
        }

        try {
            $response = Http::timeout((int) config('updates.timeout_seconds', 15))
                ->acceptJson()
                ->get($url);
        } catch (\Throwable $e) {
            report($e);

            throw new AppError('Сервер обновлений недоступен.', 'UPDATE_FEED_UNAVAILABLE', 503);
        }

        if (! $response->successful()) {
            throw new AppError('Сервер обновлений вернул ошибку.', 'UPDATE_FEED_UNAVAILABLE', 503);
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new AppError('Сервер обновлений вернул некорректный манифест.', 'UPDATE_MANIFEST_INVALID', 502);
        }

        try {
            return ReleaseManifest::fromArray($data);
        } catch (\InvalidArgumentException $e) {
            report($e);

            throw new AppError('Манифест обновления не прошёл проверку формата.', 'UPDATE_MANIFEST_INVALID', 502);
        }
    }

    /** @return array<string, mixed> */
    private function buildCheck(string $current): array
    {
        try {
            $manifest = $this->fetch();
        } catch (AppError $e) {
            return [
                'current_version' => $current,
                'available' => null,
                'reason' => $e->getMessage(),
                'feed_configured' => (string) config('updates.feed_url', '') !== '',
            ];
        }

        if (! $manifest->appliesTo($current)) {
            return [
                'current_version' => $current,
                'available' => null,
                'reason' => 'Доступных обновлений нет.',
                'feed_configured' => true,
                'latest_version' => $manifest->version,
            ];
        }

        // Подпись проверяем здесь же, чтобы кнопка не появлялась для релиза,
        // который всё равно будет отвергнут при скачивании.
        try {
            $this->verifier->verify($manifest);
        } catch (AppError $e) {
            return [
                'current_version' => $current,
                'available' => null,
                'reason' => $e->getMessage(),
                'feed_configured' => true,
                'latest_version' => $manifest->version,
            ];
        }

        return [
            'current_version' => $current,
            'available' => [
                'version' => $manifest->version,
                'released_at' => $manifest->releasedAt,
                'channel' => $manifest->channel,
                'changelog' => '',
                'from_versions' => $manifest->fromVersions,
                'size_bytes' => $manifest->sizeBytes,
            ],
            'feed_configured' => true,
        ];
    }
}
