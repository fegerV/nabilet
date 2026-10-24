<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Nabilet\Modules\Loyalty\Models\FollowNotification;
use Nabilet\Modules\Notifications\Domain\NotificationStatus;
use Nabilet\Modules\Notifications\Models\NotificationTemplate;
use Nabilet\Modules\Notifications\Services\TransactionalMailService;
use Tests\TestCase;

/**
 * Словарь статуса доставки: один владелец вместо трёх копий.
 *
 * Повод. `notifications.status` — колонка журнала отправки. Её словарь
 * (`queued`, `sent`, `failed`) был выписан ТРИЖДЫ, и каждый раз заново:
 *
 *  1. `Notifications\Services\AccountMailService` — три константы `STATUS_*`;
 *  2. `Notifications\Services\TransactionalMailService` — те же три, байт в байт;
 *  3. `Loyalty\Models\FollowNotification` — снова те же три, для таблицы
 *     `follow_notifications`, у которой словарь совпадает
 *     (`ck_follow_notifications_status`).
 *
 * Гейт `tools/verify-status-literals.php` эту форму дефекта НЕ ловит: он ищет
 * литерал рядом с токеном `status`, а здесь правило скопировано на уровне
 * ОБЪЯВЛЕНИЙ — три класса, ни один о другом не знает, и модель `Notification`
 * словаря не объявляет вовсе. Поэтому пришпиливается здесь.
 *
 * ПОЧЕМУ ЭТО НЕ КОСМЕТИКА. Три копии — это три места, которые надо поправить,
 * когда правило меняется. Расходятся они молча: `FollowNotification` пишет в
 * СВОЮ таблицу, и опечатка в его копии не сломает ни один существующий тест
 * — просто в `follow_notifications` уедет значение, которого колонка не
 * допускает, и повтор джобы перестанет её находить.
 *
 * Согласовано с `ck_notifications_status` и `ck_follow_notifications_status`
 * (spec, сплит 017). Оба ограничения допускают ровно эти три значения.
 */
final class NotificationStatusVocabularyTest extends TestCase
{
    use RefreshDatabase;

    /** Словарь `ck_notifications_status` и `ck_follow_notifications_status`. */
    private const SCHEMA_VOCABULARY = ['queued', 'sent', 'failed'];

    // ── 1. Словарь пришпилен к схеме ────────────────────────────────────────

    public function test_the_vocabulary_is_exactly_what_both_check_constraints_allow(): void
    {
        $this->assertSame(
            self::SCHEMA_VOCABULARY,
            NotificationStatus::all(),
            'Словарь разошёлся с ck_notifications_status / ck_follow_notifications_status.'
            . ' Оба ограничения допускают ровно queued, sent, failed — четвёртое значение'
            . ' даст отказ MySQL (500), а не доменную ошибку.'
        );
    }

    public function test_every_value_is_valid_and_anything_else_is_not(): void
    {
        foreach (self::SCHEMA_VOCABULARY as $status) {
            $this->assertTrue(
                NotificationStatus::isValid($status),
                "\"{$status}\" есть в схеме, значит обязан быть валидным."
            );
        }

        foreach (['opt_in', 'pending', 'delivered', 'skipped', ''] as $status) {
            $this->assertFalse(
                NotificationStatus::isValid($status),
                "\"{$status}\" в схеме нет — isValid() обязан отвечать false."
            );
        }
    }

    /**
     * Fail-closed: незнакомый статус — «не дошло».
     *
     * Направление важно. Обратное («неизвестное считаем доставленным») сделало
     * бы опечатку или новое состояние успешной доставкой в отчётах.
     */
    public function test_an_unknown_status_is_not_delivered(): void
    {
        $this->assertTrue(NotificationStatus::isDelivered(NotificationStatus::SENT));
        $this->assertFalse(NotificationStatus::isDelivered(NotificationStatus::QUEUED));
        $this->assertFalse(NotificationStatus::isDelivered(NotificationStatus::FAILED));
        $this->assertFalse(NotificationStatus::isDelivered('opt_in'));
    }

    public function test_only_queued_is_not_terminal(): void
    {
        $this->assertFalse(NotificationStatus::isTerminal(NotificationStatus::QUEUED));
        $this->assertTrue(NotificationStatus::isTerminal(NotificationStatus::SENT));
        $this->assertTrue(NotificationStatus::isTerminal(NotificationStatus::FAILED));
    }

    // ── 2. Владелец ровно один ──────────────────────────────────────────────

    /**
     * ГЛАВНЫЙ ТЕСТ ФАЙЛА: ни один класс, кроме владельца, не объявляет
     * константу со значением из словаря.
     *
     * Именно эту форму дефекта не ловит гейт: правило было скопировано на
     * уровне объявлений в трёх классах, и все три объявления — валидный PHP.
     * Если кто-то добавит четвёртую копию (`public const STATUS_SENT = 'sent'`
     * в новом сервисе), тест упадёт здесь, а не в production.
     */
    public function test_no_other_class_declares_a_notification_status_value(): void
    {
        $ownerFile = $this->ownerFilePath();

        $declarations = [];

        foreach ($this->phpFilesUnderApp() as $relative => $path) {
            if ($relative === $ownerFile) {
                continue;
            }

            $tokens = token_get_all((string) file_get_contents($path));

            foreach ($tokens as $i => $token) {
                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                $value = trim($token[1], "'\"");

                if (! in_array($value, self::SCHEMA_VOCABULARY, true)) {
                    continue;
                }

                // `failed` — единственное значение словаря, которое схема делит
                // с другой колонкой: `payments.status` и `refunds.status` его
                // тоже допускают. Их владельцы объявляют его для СВОЕЙ колонки,
                // и это не копия словаря уведомлений.
                if ($value === 'failed') {
                    continue;
                }

                // Объявление константы: `const X = 'sent'` или `private const X = 'sent'`.
                for ($j = max(0, $i - 6); $j < $i; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONST) {
                        $declarations[] = sprintf('%s:%d declares "%s"', $relative, $token[2], $value);
                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $declarations,
            'Словарь уведомлений объявлен более чем в одном месте. Значения живут'
            . ' только в NotificationStatus; в остальных классах должны быть'
            . ' псевдонимы на него (как FollowNotification::STATUS_*), а не значения.'
        );
    }

    /**
     * Псевдонимы `FollowNotification` ссылаются на владельца, а не держат
     * своё значение: иначе три копии разъедутся снова.
     */
    public function test_follow_notification_aliases_delegate_to_the_owner(): void
    {
        $this->assertSame(NotificationStatus::QUEUED, FollowNotification::STATUS_QUEUED);
        $this->assertSame(NotificationStatus::SENT, FollowNotification::STATUS_SENT);
        $this->assertSame(NotificationStatus::FAILED, FollowNotification::STATUS_FAILED);
    }

    // ── 3. Значения доходят до базы ─────────────────────────────────────────

    /**
     * Живой путь, а не только словарь: транспортный сервис пишет ровно те
     * значения, которые объявлены владельцем, и CHECK их принимает.
     */
    public function test_the_transport_service_writes_owner_values_into_the_database(): void
    {
        Mail::fake();

        NotificationTemplate::query()->create([
            'code' => 'order.paid',
            'channel' => 'email',
            'locale' => 'ru',
            'subject' => 'Заказ {{order_number}} оплачен',
            'body_html' => '<p>{{order_number}}</p>',
            'body_text' => 'Заказ {{order_number}} оплачен',
            'active' => true,
        ]);

        $sent = app(TransactionalMailService::class)->send(
            code: 'order.paid',
            recipient: 'buyer@example.test',
            variables: ['order_number' => 'NB-20261024-0001'],
        );

        $this->assertNotNull($sent, 'Транспортный сервис не вернул запись.');

        $this->assertSame(
            NotificationStatus::SENT,
            $sent->status,
            'Статус в notifications разошёлся с владельцем словаря.'
        );

        $this->assertSame(
            NotificationStatus::SENT,
            DB::table('notifications')->where('recipient', 'buyer@example.test')->value('status')
        );
    }

    /**
     * Неудачная отправка тоже пишет значение владельца — и это `failed`,
     * а не что-нибудь придуманное на месте.
     */
    public function test_a_failed_send_writes_the_owner_failed_value(): void
    {
        Mail::fake();

        NotificationTemplate::query()->create([
            'code' => 'order.paid',
            'channel' => 'email',
            'locale' => 'ru',
            'subject' => 'Заказ {{order_number}} оплачен',
            'body_html' => '<p>{{order_number}}</p>',
            'body_text' => 'Заказ {{order_number}} оплачен',
            'active' => true,
        ]);

        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP down'));

        try {
            app(TransactionalMailService::class)->send(
                code: 'order.paid',
                recipient: 'buyer@example.test',
                variables: ['order_number' => 'NB-20261024-0002'],
            );
            $this->fail('Сбой транспорта должен пробрасываться вызывающему.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SMTP down', $e->getMessage());
        }

        $this->assertSame(
            NotificationStatus::FAILED,
            DB::table('notifications')->where('recipient', 'buyer@example.test')->value('status'),
            'Сбой транспорта обязан писаться как NotificationStatus::FAILED.'
        );
    }

    // ── вспомогательное ────────────────────────────────────────────────────

    /** @return array<string, string> относительный путь => абсолютный */
    private function phpFilesUnderApp(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $files[ltrim(substr($path, strlen(str_replace('\\', '/', $root)) + 1), '/')] = $path;
        }

        return $files;
    }

    private function ownerFilePath(): string
    {
        $path = str_replace('\\', '/', (new \ReflectionClass(NotificationStatus::class))->getFileName());
        $root = str_replace('\\', '/', dirname(__DIR__, 3));

        return ltrim(substr($path, strlen($root) + 1), '/');
    }
}
