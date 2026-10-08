<?php

declare(strict_types=1);

namespace Tests\Feature\Logging;

use Nabilet\Core\Logging\RedactSensitiveData;
use Tests\TestCase;

/**
 * Правила маскирования: что именно вырезается из строки и что остаётся (152-ФЗ).
 *
 * ПОЧЕМУ ЭТО НЕ UNIT-ТЕСТ
 *   Раньше эти проверки жили в `tests/Unit/RedactSensitiveDataTest.php` и
 *   запускались зависимостно-свободным раннером `tests/run.php`. После того как
 *   класс стал реализовывать `Monolog\Processor\ProcessorInterface` (без этого
 *   Laravel отказывается принимать его как процессор — см.
 *   `LogManager::createMonologDriver()`), он перестал загружаться без
 *   `vendor/`: реализация интерфейса требует, чтобы интерфейс существовал на
 *   момент объявления класса. Все 16 проверок немедленно упали с
 *   «Interface Monolog\Processor\ProcessorInterface not found».
 *
 *   Это не повод ослаблять проверки — это признак того, что класс переехал из
 *   «чистого ядра» в «адаптер инфраструктуры», и его тесты должны ехать за ним.
 *   Ровно поэтому `app/Core/Logging` и не входит в список каталогов, которые
 *   охраняет `tools/verify-purity.php` (там перечислены Errors, Hooks,
 *   Idempotency, Modules, StateMachine, Support, Tenancy).
 *
 * ПОЛОВИНА ЭТИХ ТЕСТОВ — ПРО ТО, ЧТО ФИЛЬТР НЕ ДОЛЖЕН ДЕЛАТЬ
 *   Фильтр, который прячет половину лога, отключают, и тогда он не защищает
 *   ничего. Поэтому проверки «не маскируется» здесь не менее важны, чем
 *   «маскируется»: они фиксируют границы, за которыми фильтр начал бы съедать
 *   Unix-время и идентификаторы заказов.
 */
final class RedactionRulesTest extends TestCase
{
    private RedactSensitiveData $redactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redactor = new RedactSensitiveData();
    }

    // ── маскируется ──────────────────────────────────────────────────────────

    public function test_email_keeps_domain_but_loses_local_part(): void
    {
        $out = $this->redactor->scrubText('Письмо отправлено на ivan.petrov@example.com');

        $this->assertStringContainsString('i***@example.com', $out);
        $this->assertFalse(
            str_contains($out, 'ivan.petrov'),
            'Локальная часть адреса не должна оставаться в логе'
        );
    }

    public function test_plus_seven_phone_is_masked(): void
    {
        foreach (['+7 999 123-45-67', '+79991234567', '+7 (999) 123 45 67'] as $phone) {
            $out = $this->redactor->scrubText("Телефон покупателя: {$phone}");
            $this->assertStringContainsString(RedactSensitiveData::MASK, $out, "Не замаскирован: {$phone}");
        }
    }

    public function test_eight_prefixed_phone_with_separators_is_masked(): void
    {
        $out = $this->redactor->scrubText('звонить 8 (999) 123-45-67');

        $this->assertStringContainsString(RedactSensitiveData::MASK, $out);
    }

    public function test_snils_in_canonical_form_is_masked(): void
    {
        $out = $this->redactor->scrubText('СНИЛС 123-456-789 00');

        $this->assertStringContainsString(RedactSensitiveData::MASK, $out);
    }

    public function test_luhn_valid_card_is_masked(): void
    {
        // 4111 1111 1111 1111 — тестовый номер, проходит Луна.
        $out = $this->redactor->scrubText('card=4111 1111 1111 1111');

        $this->assertStringContainsString(RedactSensitiveData::MASK, $out);
        $this->assertFalse(str_contains($out, '4111'));
    }

    // ── НЕ маскируется (границы фильтра) ─────────────────────────────────────

    public function test_unix_timestamp_survives(): void
    {
        // 10 цифр. Unix-время есть в каждом втором сообщении; если его
        // маскировать, логи перестают читаться и фильтр отключат.
        $out = $this->redactor->scrubText('заказ создан в 1760000000');

        $this->assertStringContainsString('1760000000', $out);
    }

    public function test_order_and_request_identifiers_survive(): void
    {
        $out = $this->redactor->scrubText('order_id=1042 request_id=7f3a1b9c4d5e');

        $this->assertStringContainsString('1042', $out);
        $this->assertStringContainsString('7f3a1b9c4d5e', $out);
    }

    public function test_eleven_digit_number_starting_with_eight_is_not_treated_as_phone(): void
    {
        // Осознанное ограничение: `8XXXXXXXXXX` без разделителей неотличимо от
        // 11-значного идентификатора, поэтому не маскируется.
        $out = $this->redactor->scrubText('внешний id 89991234567');

        $this->assertStringContainsString('89991234567', $out);
    }

    public function test_sixteen_digits_failing_luhn_are_not_masked(): void
    {
        // 1234567890123456 не проходит проверку Луна — это идентификатор, а не карта.
        $out = $this->redactor->scrubText('hash 1234567890123456');

        $this->assertStringContainsString('1234567890123456', $out);
    }

    public function test_inn_without_separators_is_not_masked_by_value(): void
    {
        // 10 цифр ИНН неотличимы от идентификатора; маскируется только по ключу.
        $out = $this->redactor->scrubText('ИНН 7707083893');

        $this->assertStringContainsString('7707083893', $out);
    }

    public function test_ordinary_text_is_untouched(): void
    {
        $message = 'Оплата подтверждена, выпускаем билет';

        $this->assertSame($message, $this->redactor->scrubText($message));
    }

    public function test_empty_string_is_returned_as_is(): void
    {
        $this->assertSame('', $this->redactor->scrubText(''));
    }

    // ── трассировка исключения ───────────────────────────────────────────────

    public function test_trace_frame_arguments_are_stripped(): void
    {
        // Именно эта строка утекала в лог: `Exception::__toString()` печатает
        // аргументы вызовов, и фильтрация по значениям её не спасала —
        // `'hunter2'` ни на что не похож.
        $trace = "#0 /app/Auth/LoginService.php(42): login('user@example.com', 'hunter2')\n#1 {main}";

        $out = $this->redactor->scrubText($trace);

        $this->assertFalse(str_contains($out, 'hunter2'), 'Аргумент кадра трассы остался в логе');
        $this->assertStringContainsString('LoginService.php(42)', $out, 'Кадр трассы должен остаться опознаваемым');
        $this->assertStringContainsString('#1 {main}', $out);
    }

    public function test_nested_object_arguments_in_trace_are_stripped(): void
    {
        $trace = "#0 /app/x.php(9): handle(Object(App\\Models\\User), 'secret-value')";

        $out = $this->redactor->scrubText($trace);

        $this->assertFalse(str_contains($out, 'secret-value'));
    }

    public function test_message_without_hash_is_not_touched_by_trace_stripper(): void
    {
        // Регрессия: стриппер не должен срабатывать на обычных строках,
        // которые просто начинаются с решётки.
        $out = $this->redactor->scrubText('#12 заказ не найден');

        $this->assertStringContainsString('#12 заказ не найден', $out);
    }

    public function test_email_inside_trace_argument_is_also_masked(): void
    {
        $trace = "#0 /app/x.php(3): notify('client@shop.ru')";

        $out = $this->redactor->scrubText($trace);

        $this->assertFalse(str_contains($out, 'client@shop.ru'));
    }
}
