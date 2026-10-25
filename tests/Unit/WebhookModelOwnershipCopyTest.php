<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * У таблицы `webhooks` один владелец, и правило `events_json` живёт в нём.
 *
 * ЧТО БЫЛО. В `app/Modules/System/Models/` лежали три модели-фантома:
 * `Webhook`, `WebhookDelivery`, `WebhookEvent`. Они объявляли колонки, которых
 * нет ни в одной таблице (`name`, `secret`, `events`, `is_active`;
 * `webhook_event_id`, `status`, `attempt_count`, `response_code`;
 * `event_type`, `payload`) — и это не были «просто мёртвые файлы»:
 * `Organization::webhookEndpoints()` указывала на `System\Models\Webhook`, то
 * есть живая связь выбирала несуществующие колонки, а `->create()` по ней упал
 * бы на `42S22`.
 *
 * Расхождение при этом было ИЗВЕСТНО: `tools/verify-models-schema.php` держал
 * для всех трёх классов исключения с пояснением. Исключение превращает находку
 * в тишину — модель осталась, связь осталась, а гейт зелен. Ровно этот же
 * дефект уже был закрыт для `api_keys`; `webhooks` остался хвостом того прохода.
 *
 * ВТОРОЕ, ЧТО СТАЛО ВИДНО ПОСЛЕ УДАЛЕНИЯ ФАНТОМА. У живого владельца не было
 * cast'а на `events_json`, поэтому правило «эта колонка — массив» повторялось в
 * пяти местах: два `json_encode` при записи, два ручных `is_string` +
 * `json_decode` при чтении и один `json_encode` в фикстуре теста. Каждое место
 * формулировало правило само.
 *
 * Поэтому сторож проверяет не «модель существует», а две вещи: у таблицы РОВНО
 * один владелец — и никто, кроме него, не сериализует `events_json`.
 */
final class WebhookModelOwnershipCopyTest extends TestCase
{
    private const OWNER = 'app/Modules/Webhooks/Models/Webhook.php';

    /**
     * Таблица => единственный файл модели, который имеет право её объявлять.
     *
     * `webhook_events` в списке нет намеренно: модели под неё НЕТ, и это
     * решение, а не пропуск. `PaymentService::processWebhook()` заявляет событие
     * через `DB::table('webhook_events')->insertOrIgnore()` — вставка обязана
     * быть атомарной (UNIQUE `(provider, provider_event_id)`), а Eloquent
     * добавил бы к ней `created_at`/`updated_at` и `SELECT` перед вставкой.
     */
    private const TABLES = [
        'webhooks' => self::OWNER,
        'webhook_deliveries' => 'app/Modules/Webhooks/Models/WebhookDelivery.php',
    ];

    public function testEachWebhookTableHasExactlyOneModel(): void
    {
        foreach (self::TABLES as $table => $owner) {
            $this->assertSame(
                [$owner],
                $this->filesMatching('~\$table\s*=\s*[\'"]' . $table . '[\'"]~'),
                sprintf(
                    'у таблицы %s должен быть один владелец: %s (вторая модель той же таблицы '
                    . 'объявляет свои колонки и рано или поздно разойдётся со схемой)',
                    $table,
                    $owner,
                ),
            );
        }
    }

    /**
     * Связь организации ведёт на владельца, а не на фантом.
     *
     * Проверяется по коду, потому что `Organization` — Eloquent-модель и в
     * dependency-free раннере её не создать. Это тот самый разрыв, который
     * существовал: связь была, класс был, колонок не было.
     */
    public function testTheOrganizationRelationPointsAtTheOwner(): void
    {
        $source = $this->code((string) file_get_contents(
            $this->root() . '/app/Modules/Core/Organizations/Models/Organization.php'
        ));

        $this->assertTrue(
            preg_match('~function\s+webhookEndpoints\s*\([^)]*\)[^{]*\{[^}]*Webhooks\\\\Models\\\\Webhook~s', $source) === 1,
            'Organization::webhookEndpoints() должна ссылаться на Webhooks\Models\Webhook',
        );

        $this->assertFalse(
            str_contains($source, 'System\\\\Models\\\\Webhook'),
            'Organization::webhookEndpoints() снова указывает на удалённую модель System\Models\Webhook',
        );
    }

    /**
     * Правило «events_json — массив» объявлено в модели и больше нигде.
     *
     * Формулировка нарочно грубая: любой файл, который вообще упоминает
     * `events_json`, не имеет права вызывать `json_encode`/`json_decode`. Так
     * ловится и запись, и чтение, и новая форма, которую я не предусмотрел.
     */
    public function testNobodySerialisesEventsJsonByHand(): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            $code = $this->code((string) file_get_contents($path));

            if (! str_contains($code, 'events_json')) {
                continue;
            }

            if (preg_match('~json_(?:encode|decode)\s*\(~', $code) === 1) {
                $offenders[] = $this->relative($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'events_json сериализуется руками: сериализацию делает cast модели Webhook',
        );
    }

    public function testTheOwnerStillDeclaresTheArrayCast(): void
    {
        $source = $this->code((string) file_get_contents($this->root() . '/' . self::OWNER));

        $this->assertTrue(
            preg_match('~[\'"]events_json[\'"]\s*=>\s*[\'"]array[\'"]~', $source) === 1,
            'cast на events_json пропал — вернутся ручные json_encode/json_decode по всему модулю',
        );

        // `active` и `retry_limit` без cast'ов приходят строками и сравниваются
        // с числами в сервисе доставки.
        $this->assertTrue(
            preg_match('~[\'"]active[\'"]\s*=>\s*[\'"]boolean[\'"]~', $source) === 1,
            'cast на active пропал',
        );
        $this->assertTrue(
            preg_match('~[\'"]retry_limit[\'"]\s*=>\s*[\'"]integer[\'"]~', $source) === 1,
            'cast на retry_limit пропал',
        );
    }

    /**
     * Исключения гейта не должны снова прикрывать вебхуки.
     *
     * Проверяется именно отсутствие записей: гейт теперь умеет ловить
     * устаревшие (`stale invented-field entry`), но упасть он может только
     * тогда, когда запущен, — а этот сторож дешевле и стоит в общем прогоне.
     */
    public function testTheGateDoesNotSuppressWebhookDriftAgain(): void
    {
        $source = $this->code((string) file_get_contents($this->root() . '/tools/verify-models-schema.php'));

        foreach (['Webhook', 'WebhookDelivery', 'WebhookEvent'] as $class) {
            $this->assertFalse(
                preg_match('~^\s*[\'"]' . $class . '[\'"]\s*=>\s*\[~m', $source) === 1,
                sprintf(
                    'в INVENTED_FIELDS снова появилась запись %s: значит модель снова разошлась со схемой',
                    $class,
                ),
            );
        }
    }

    /** @return list<string> */
    private function filesMatching(string $pattern): array
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            if (preg_match($pattern, $this->code((string) file_get_contents($path))) === 1) {
                $offenders[] = $this->relative($path);
            }
        }

        sort($offenders);

        return $offenders;
    }

    /** @return list<string> */
    private function appFiles(): array
    {
        $root = $this->root() . '/app';
        $paths = [];

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($rii as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($paths);

        return $paths;
    }

    /** Только код: блочные и строчные комментарии вырезаны. */
    private function code(string $source): string
    {
        return (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $source);
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen($this->root()) + 1));
    }
}
