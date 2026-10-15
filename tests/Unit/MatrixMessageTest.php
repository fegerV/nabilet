<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Matrix\Domain\MatrixMessage;
use Nabilet\Modules\Matrix\Domain\MatrixNotice;
use Nabilet\Tests\Support\TestCase;

/**
 * Тело сообщения Matrix и тексты административных уведомлений.
 *
 * ЧТО ИМЕННО ЗАЩИЩАЕТСЯ
 *   1. БЕЗОПАСНОСТЬ РАЗМЕТКИ. Сообщение уходит как `m.text`, а не как HTML:
 *      часть содержимого приходит из пользовательского ввода (название
 *      мероприятия), и превращать его в разметку чужого клиента нельзя.
 *   2. ЧИСТОТА ТЕКСТА. Управляющие символы вырезаются, переводы строк
 *      приводятся к `\n`, пустое тело не отправляется как пустота.
 *   3. ГРАНИЦЫ ДЛИНЫ. Длинное тело усекается ПО СИМВОЛАМ UTF-8 — иначе русский
 *      текст рвался бы посередине символа и Synapse получил бы невалидную UTF-8.
 *   4. СУММА. Форматируется `Money` — тем же форматтером, что чеки и письма;
 *      своё `number_format` разошлось бы с ними.
 *
 * Класс исполняется `php tests/run.php` без `vendor/` и без MySQL.
 */
final class MatrixMessageTest extends TestCase
{
    // ── тело сообщения ───────────────────────────────────────────────────────

    public function test_payload_is_a_plain_text_event(): void
    {
        $this->assertSame(
            ['msgtype' => 'm.text', 'body' => 'Привет'],
            MatrixMessage::payload('Привет'),
        );
    }

    public function test_control_characters_are_stripped(): void
    {
        // NUL и BEL Element X отрисует как «квадратики».
        $this->assertSame('ab', MatrixMessage::sanitize("a\x00b\x07"));
    }

    public function test_carriage_returns_are_normalised_to_line_feeds(): void
    {
        $this->assertSame("a\nb\nc", MatrixMessage::sanitize("a\r\nb\rc"));
    }

    public function test_whitespace_only_body_becomes_a_placeholder(): void
    {
        // Пустое сообщение в комнате выглядело бы как сбой канала.
        $this->assertSame(MatrixMessage::EMPTY_BODY, MatrixMessage::sanitize("  \n\t "));
    }

    public function test_a_body_within_the_limit_is_untouched(): void
    {
        $this->assertSame('коротко', MatrixMessage::sanitize('коротко'));
    }

    public function test_a_long_body_is_truncated_by_characters(): void
    {
        $body = str_repeat('я', MatrixMessage::MAX_BODY_LENGTH + 100);

        $clean = MatrixMessage::sanitize($body);

        $this->assertSame(MatrixMessage::MAX_BODY_LENGTH, mb_strlen($clean, 'UTF-8'));
        $this->assertStringContainsString('…', $clean);
    }

    // ── уведомление об оплате ────────────────────────────────────────────────

    public function test_order_notice_names_the_order_the_sum_and_the_event(): void
    {
        $text = MatrixNotice::orderPaid([
            'order_number' => 'NB-20260101-ABCDEFGH',
            'total_minor' => 150_000,
            'currency' => 'RUB',
            'event_title' => 'Концерт',
            'customer' => 'Иван',
        ]);

        $this->assertStringContainsString('NB-20260101-ABCDEFGH', $text);
        // 150 000 минорных единиц = 1 500,00 ₽.
        $this->assertStringContainsString('1 500,00', $text);
        $this->assertStringContainsString('Концерт', $text);
        $this->assertStringContainsString('Иван', $text);
    }

    public function test_order_notice_omits_empty_optional_lines(): void
    {
        $text = MatrixNotice::orderPaid([
            'order_number' => 'NB-1',
            'total_minor' => 0,
            'currency' => 'RUB',
            'event_title' => null,
            'customer' => '',
        ]);

        $this->assertFalse(str_contains($text, 'Мероприятие'));
        $this->assertFalse(str_contains($text, 'Покупатель'));
        $this->assertStringContainsString('NB-1', $text);
    }

    public function test_order_notice_falls_back_to_rub_for_a_blank_currency(): void
    {
        // Колонка `currency` NOT NULL, но пустая строка возможна у старых строк;
        // `Money` отверг бы её исключением, и уведомление не ушло бы вообще.
        $text = MatrixNotice::orderPaid([
            'order_number' => 'NB-2',
            'total_minor' => 100,
            'currency' => '',
        ]);

        $this->assertStringContainsString('₽', $text);
    }

    public function test_the_test_notice_is_marked_as_a_test(): void
    {
        // Администратор должен отличать проверку от настоящей продажи.
        $text = MatrixNotice::test();

        $this->assertStringContainsString('NABILET', $text);
        $this->assertStringContainsString('Тестовое', $text);
    }
}
