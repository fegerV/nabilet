<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Payments\Domain\TransactionType;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Tests\Support\TestCase;

/**
 * Журнал `payment_transactions`: словарь типа и пара «тип — статус».
 *
 * ЧТО БЫЛО. Четыре значения (`authorization`, `capture`, `refund`, `failure`)
 * были объявлены РОВНО ОДИН РАЗ — в докблоке модели `PaymentTransaction`. Писали
 * их голыми строками в шести местах: пять раз в `PaymentService` (`createPayment`,
 * `handleSucceededEvent`, `recordReceiptAndRefund`, `handleRefundEvent`,
 * `handlePaymentFailed`) и один раз в `RefundService`. Докблок ничего не
 * запрещает — седьмой писатель мог написать `'void'`, и колонка
 * `type VARCHAR(64) NOT NULL`, объявленная без CHECK, приняла бы это молча.
 *
 * Спека при этом объясняла отсутствие CHECK тем, что это «словарь провайдера».
 * Объяснение неверное и было неверным всегда: ни один писатель не берёт `type`
 * или `status` из payload — значения пишем мы, а сырой ответ провайдера уходит в
 * `payload_json`. Ложная проза дороже отсутствующей: ей верят, поэтому вопрос
 * «а где владелец словаря» не возникал четыре ревизии подряд.
 *
 * ВТОРОЕ ПРАВИЛО, КОТОРОЕ НИГДЕ НЕ БЫЛО ЗАПИСАНО. Тип записи ограничивает
 * статус: авторизация не может быть `succeeded` (деньги не взяты), фиксация не
 * может быть `pending` (деньги взяты), неудача не бывает `succeeded`. У всех
 * шести писателей это совпадало — то есть держалось на внимательности, а не на
 * правиле. Ошибка не косметическая: журнал — то, чем сверяются с выпиской
 * провайдера, и запись «списание, которое ещё не прошло» делает сверку неверной
 * в сторону «денег нет».
 *
 * ЧТО ПРОВЕРЯЕТСЯ. Не «метод вернул строку», а две вещи: словарь нельзя
 * выписать мимо владельца (сторож по форме записи) и правило пары существует в
 * одном месте и вызывается единственным писателем (сторож по вызову). Второй
 * сторож без первого был бы бесполезен: `assertAllowed()` в репозитории
 * защищает только тех, кто в репозиторий ходит, а `->transactions()->create()`
 * мимо него закрыт отдельной проверкой ниже.
 */
final class TransactionTypeCopyTest extends TestCase
{
    private const OWNER = '/Payments/Domain/TransactionType.php';

    private const REPOSITORY = 'app/Modules/Payments/Repositories/PaymentRepository.php';

    public function testTheTypeIsWrittenOnlyByItsOwner(): void
    {
        $this->assertSame(
            [],
            $this->filesMatching('~[\'"]type[\'"]\s*=>\s*[\'"](?:authorization|capture|refund|failure)[\'"]~'),
            'тип транзакции выписан литералом: берите TransactionType::AUTHORIZATION / CAPTURE / REFUND / FAILURE',
        );
    }

    public function testTheTypeIsNeverComparedToALiteral(): void
    {
        $this->assertSame(
            [],
            $this->filesMatching('~(?:===|!==)\s*[\'"](?:authorization|capture|refund|failure)[\'"]~'),
            'тип транзакции сравнивается с литералом: берите константу TransactionType',
        );
    }

    /**
     * Единственный писатель обязан спрашивать владельца.
     *
     * Проверяется ВЫЗОВ, а не наличие метода: `assertAllowed()` может
     * существовать и не вызываться — тогда правило есть, а отказа нет, и это
     * ровно тот вид «зелёной» реализации, который уже случался в этом проекте.
     */
    public function testTheOnlyWriterAsksTheOwner(): void
    {
        $source = $this->code((string) file_get_contents($this->root() . '/' . self::REPOSITORY));

        $this->assertTrue(
            preg_match('~TransactionType::assertAllowed\s*\(~', $source) === 1,
            'PaymentRepository::addTransaction() перестал спрашивать TransactionType::assertAllowed()',
        );
    }

    /**
     * Обходной путь закрыт: строку журнала нельзя создать мимо репозитория.
     *
     * `->transactions()->create()` пишет в таблицу напрямую, без проверки пары.
     * Пока такое место одно (в репозитории) — правило держится; второе место
     * сделало бы `assertAllowed()` необязательным, а тест выше — зелёным при
     * мёртвом правиле.
     */
    public function testNothingWritesTheJournalAroundTheRepository(): void
    {
        $this->assertSame(
            [self::REPOSITORY],
            $this->filesMatching('~->transactions\(\)->create\(~'),
            'запись в payment_transactions завелась мимо PaymentRepository::addTransaction()',
        );
    }

    /**
     * Словарь владельца обязан совпадать с тем, что реально пишется в коде.
     *
     * Это единственная связь между объявлением и шестью писателями. Если завтра
     * появится пятый тип, он либо попадёт в `all()` (и тогда здесь всё сойдётся),
     * либо не попадёт — и тест скажет, что константа используется, но словарём
     * не объявлена, то есть `all()` перестал быть полным списком.
     */
    public function testTheOwnerCoversEveryTypeActuallyWrittenInCode(): void
    {
        $declared = (new \ReflectionClass(TransactionType::class))->getConstants();
        $used = [];

        foreach ($this->appFiles() as $path) {
            if (str_ends_with($path, self::OWNER)) {
                continue;
            }

            preg_match_all(
                '~TransactionType::([A-Z_]+)~',
                $this->code((string) file_get_contents($path)),
                $matches,
            );

            foreach ($matches[1] as $constant) {
                $this->assertTrue(
                    isset($declared[$constant]),
                    sprintf('TransactionType::%s используется в %s, но не объявлен', $constant, $this->relative($path)),
                );

                $used[$declared[$constant]] = true;
            }
        }

        $used = array_keys($used);
        sort($used);

        $all = TransactionType::all();
        sort($all);

        $this->assertSame($all, $used, 'набор типов, которые пишет код, разошёлся с TransactionType::all()');
    }

    /**
     * Статусы пары обязаны быть статусами платежа, а не своими строками.
     *
     * Журнал описывает состояние ПЛАТЕЖА. Второй словарь тех же состояний
     * разошёлся бы с `PaymentStateMachine` при первом же новом статусе — и
     * разошёлся бы молча, потому что оба пишутся в разные колонки.
     */
    public function testThePairingUsesThePaymentVocabulary(): void
    {
        $states = (new \ReflectionClass(PaymentStateMachine::class))->getConstants();

        foreach (TransactionType::all() as $type) {
            $statuses = TransactionType::statusesFor($type);

            $this->assertSame(
                range(0, count($statuses) - 1),
                array_keys($statuses),
                sprintf('statusesFor(%s) вернул не список', $type),
            );

            $this->assertTrue($statuses !== [], sprintf('у типа %s не осталось ни одного статуса', $type));

            foreach ($statuses as $status) {
                $this->assertTrue(
                    in_array($status, $states, true),
                    sprintf('статус "%s" для типа %s не объявлен в PaymentStateMachine', $status, $type),
                );
            }
        }
    }

    /**
     * Само правило — закреплённый факт, а не следствие реализации.
     *
     * Негативы здесь важнее позитивов: именно они превращают «список типов» в
     * «пару». Если однажды кто-то решит, что `capture` может быть `pending`,
     * тест обязан покраснеть и заставить это обсудить, а не согласиться молча.
     */
    public function testThePairingIsStillTheRuleItWas(): void
    {
        $this->assertSame(
            ['authorization', 'capture', 'refund', 'failure'],
            TransactionType::all(),
            'словарь типа изменился — проверьте, что новый тип действительно нужен журналу',
        );

        $this->assertSame([PaymentStateMachine::PENDING], TransactionType::statusesFor(TransactionType::AUTHORIZATION));
        $this->assertSame([PaymentStateMachine::SUCCEEDED], TransactionType::statusesFor(TransactionType::CAPTURE));
        $this->assertSame([PaymentStateMachine::FAILED], TransactionType::statusesFor(TransactionType::FAILURE));
        $this->assertSame(
            [PaymentStateMachine::PENDING, PaymentStateMachine::SUCCEEDED, PaymentStateMachine::FAILED],
            TransactionType::statusesFor(TransactionType::REFUND),
            'возврат пишется дважды: при инициации и по исходу',
        );

        // Позитивы — все шесть пар, которые код действительно пишет.
        $this->assertTrue(TransactionType::allows(TransactionType::AUTHORIZATION, PaymentStateMachine::PENDING));
        $this->assertTrue(TransactionType::allows(TransactionType::CAPTURE, PaymentStateMachine::SUCCEEDED));
        $this->assertTrue(TransactionType::allows(TransactionType::REFUND, PaymentStateMachine::PENDING));
        $this->assertTrue(TransactionType::allows(TransactionType::REFUND, PaymentStateMachine::SUCCEEDED));
        $this->assertTrue(TransactionType::allows(TransactionType::REFUND, PaymentStateMachine::FAILED));
        $this->assertTrue(TransactionType::allows(TransactionType::FAILURE, PaymentStateMachine::FAILED));

        // Негативы — то, что нельзя записать ни при каком раскладе.
        $this->assertFalse(
            TransactionType::allows(TransactionType::CAPTURE, PaymentStateMachine::PENDING),
            'списание, которое ещё не прошло, делает сверку неверной',
        );
        $this->assertFalse(
            TransactionType::allows(TransactionType::AUTHORIZATION, PaymentStateMachine::SUCCEEDED),
            'авторизация не берёт денег — succeeded у неё означал бы поступление, которого не было',
        );
        $this->assertFalse(
            TransactionType::allows(TransactionType::FAILURE, PaymentStateMachine::SUCCEEDED),
            'неудача и успех — разные исходы одной попытки',
        );
        $this->assertFalse(
            TransactionType::allows('void', PaymentStateMachine::SUCCEEDED),
            'тип вне словаря не проходит, даже с правдоподобным статусом',
        );
    }

    /**
     * NULL-статус легален, но не открывает дверь неизвестному типу.
     *
     * Колонка `status` объявлена NULL-able, поэтому «событие зафиксировано, но
     * состояние не утверждается» — законная запись. Обратное тоже должно
     * держаться: если бы `allows()` начинался с проверки статуса, `void` с
     * `null` прошёл бы насквозь.
     */
    public function testANullStatusIsLegalButNotAPassport(): void
    {
        $this->assertTrue(TransactionType::allows(TransactionType::CAPTURE, null));
        $this->assertFalse(TransactionType::allows('void', null));
    }

    public function testAssertAllowedRefusesAPairNoWriterCanProduce(): void
    {
        // Позитивный контроль: законная пара не бросает. Без него тест зелен и
        // тогда, когда метод бросает ВСЕГДА.
        TransactionType::assertAllowed(TransactionType::CAPTURE, PaymentStateMachine::SUCCEEDED);

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => TransactionType::assertAllowed(TransactionType::CAPTURE, PaymentStateMachine::PENDING),
        );

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => TransactionType::assertAllowed('void', PaymentStateMachine::SUCCEEDED),
        );
    }

    /**
     * Файлы `app/`, в коде которых встречается шаблон, кроме владельца.
     *
     * @return list<string>
     */
    private function filesMatching(string $pattern): array
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            if (str_ends_with($path, self::OWNER)) {
                continue;
            }

            if (preg_match($pattern, $this->code((string) file_get_contents($path))) === 1) {
                $offenders[] = $this->relative($path);
            }
        }

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
