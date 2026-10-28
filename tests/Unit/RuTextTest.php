<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\RuText;
use Nabilet\Tests\Support\TestCase;

/**
 * Как сервер печатает дату — один владелец на все десять потребителей.
 *
 * ЧТО ЗДЕСЬ ОХРАНЯЕТСЯ. Человекочитаемая дата на сервере существовала в двух
 * видах, и оба были выписаны руками:
 *
 *   - «25 декабря, 19:00» — `RuText::date()`/`dateTime()`, для посадочных
 *     страниц (`LandingPageRenderer`);
 *   - «20.11.2026 19:30» — литерал `format('d.m.Y H:i')` в СЕМИ местах: два
 *     свипера напоминаний, `FollowNotificationData` (дважды),
 *     `OrderNotificationData`, `TicketGeneratorService`, `RaffleService`.
 *
 * Плюс три написания той же метки: `d.m.Y` (страница события), `d.m.Y, H:i`
 * (там же, с запятой — единственный такой случай) и `H:i` (время билета и
 * время истечения корзины).
 *
 * ПОЧЕМУ ЭТО НЕ КОСМЕТИКА. `FollowNotificationData` утверждал в комментарии,
 * что отдаёт «ту же переменную и то же значение, что в заказных письмах», и
 * `event_date` действительно печатался тремя независимыми литералами. Правда
 * эта держалась до первой правки одного из трёх. А формат `event_date`
 * закреплён тестом (`InventoryRepositoryContractTest`: «20.11.2026 19:30»),
 * то есть правка в одном месте без остальных дала бы два разных значения одной
 * переменной в письмах разного типа — и заметить это можно было только
 * сравнением двух писем.
 *
 * ВТОРОЙ ФОРМАТ ОСТАВЛЕН НАМЕРЕННО. «25 декабря» и «20.11.2026» — не дубли:
 * первое читается в списке, второе однозначно (с годом) там, где дату
 * пересылают в поддержку или печатают на билете. Тест фиксирует ОБА, чтобы
 * «унификация» не превратилась в тихую смену текста писем.
 *
 * ТЕСТ ИДЁТ БЕЗ ФРЕЙМВОРОКА: `app/Core/Support` обязан грузиться без `vendor/`
 * (`tools/verify-purity.php`), поэтому и здесь нет ни Laravel, ни Carbon.
 */
final class RuTextTest extends TestCase
{
    public function testStampPrintsTheNumericMoment(): void
    {
        // Метка из строки (так её читают свиперы: сырой `DB::table()`), из
        // `\DateTimeImmutable` (так её читают модели) и из объекта с явным
        // поясом — обязаны совпасть: значение не должно зависеть от того,
        // каким способом дату достали.
        $this->assertSame('20.11.2026 19:30', RuText::stamp('2026-11-20 19:30:00'));
        $this->assertSame('20.11.2026 19:30', RuText::stamp(new \DateTimeImmutable('2026-11-20 19:30:00')));
        $this->assertSame(
            '20.11.2026 19:30',
            RuText::stamp(new \DateTimeImmutable('2026-11-20 19:30:00', new \DateTimeZone('Europe/Moscow'))),
        );
    }

    public function testStampKeepsTheWallClockOfTheSource(): void
    {
        // Часовой пояс источника сохраняется, а НЕ приводится к поясу сервера:
        // иначе дата сеанса съехала бы на сутки у любого события восточнее
        // Москвы. Ровно та же ошибка, что была в графике аналитики на фронте
        // (бакетирование по UTC), только здесь она выглядела бы как «письмо
        // пришло с вчерашней датой».
        $utc = new \DateTimeImmutable('2026-11-20 23:30:00', new \DateTimeZone('UTC'));

        $this->assertSame('20.11.2026 23:30', RuText::stamp($utc));
        $this->assertSame('20.11.2026', RuText::stampDate($utc));
        $this->assertSame('23:30', RuText::clock($utc));
    }

    public function testStampDateAndClockSplitTheSameMoment(): void
    {
        $moment = '2026-11-20 19:30:00';

        $this->assertSame('20.11.2026', RuText::stampDate($moment));
        $this->assertSame('19:30', RuText::clock($moment));

        // Полночь и последняя минута суток — границы, на которых ломается
        // «дата без времени», если её собирать вычитанием часов.
        $this->assertSame('00:00', RuText::clock('2026-11-20 00:00:00'));
        $this->assertSame('23:59', RuText::clock('2026-11-20 23:59:59'));
    }

    public function testMissingMomentGivesNullNotAnInventedDate(): void
    {
        // У заказа может не быть сеанса, у розыгрыша — даты тиража. «01.01.1970»
        // здесь был бы выдумкой, а пустая строка — молчаливой подменой: решение,
        // что показать вместо даты, принимает вызывающий (`?? ''`,
        // `?? 'дата не записана'`).
        foreach ([null, '', '   ', 'не дата'] as $input) {
            $this->assertNull(RuText::stamp($input));
            $this->assertNull(RuText::stampDate($input));
            $this->assertNull(RuText::clock($input));
        }
    }

    public function testRussianTextFormatIsUnchanged(): void
    {
        // Второй формат остаётся вторым: посадочные страницы печатают «25
        // декабря, 19:00», и переход потребителей на `stamp()` не должен был
        // задеть их. Здесь же фиксируется родительный падеж месяца — «20
        // ноябрь» выдаёт подстановку числа в именительный.
        $this->assertSame('20 ноября', RuText::date('2026-11-20 19:30:00'));
        $this->assertSame('20 ноября, 19:30', RuText::dateTime('2026-11-20 19:30:00'));
        $this->assertSame('1 мая, 09:00', RuText::dateTime('2026-05-01 09:00:00'));
    }

    public function testPluralKeepsTheRussianRule(): void
    {
        // Склонение живёт здесь же, и на него опираются посадочные страницы.
        // 11–14 — исключение: оканчиваются на 1–4, а склоняются как «11 билетов»,
        // поэтому проверяется `% 100`, а не `% 10`.
        $this->assertSame('билет', RuText::plural(1, 'билет', 'билета', 'билетов'));
        $this->assertSame('билета', RuText::plural(2, 'билет', 'билета', 'билетов'));
        $this->assertSame('билетов', RuText::plural(5, 'билет', 'билета', 'билетов'));
        $this->assertSame('билетов', RuText::plural(11, 'билет', 'билета', 'билетов'));
        $this->assertSame('билетов', RuText::plural(14, 'билет', 'билета', 'билетов'));
        $this->assertSame('билет', RuText::plural(21, 'билет', 'билета', 'билетов'));
        $this->assertSame('билета', RuText::plural(22, 'билет', 'билета', 'билетов'));
        $this->assertSame('билетов', RuText::plural(111, 'билет', 'билета', 'билетов'));
    }

    public function testNoOtherFileFormatsTheStampItself(): void
    {
        // Статический сторож: формат метки не имеет права появляться в `app/`
        // второй раз. Это не гейт (формат даты — не статус-словарь), поэтому
        // копию сторожит тест — как у `CssColor` и `IdempotencyHeader`.
        //
        // Комментарии выбрасываются: в докблоках эти форматы цитируются как
        // история, и сторож не должен матчить собственное объяснение.
        $root = dirname(__DIR__, 2);
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if (str_ends_with($path, '/RuText.php')) {
                continue;
            }

            $code = (string) preg_replace(
                ['~/\*.*?\*/~s', '~//[^\n]*~'],
                '',
                (string) file_get_contents($file->getPathname()),
            );

            if (preg_match('~format\(\s*[\'"]\s*(d\.m\.Y|H:i)~', $code) === 1) {
                $offenders[] = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
            }
        }

        $this->assertSame([], $offenders, 'формат метки даты выписан второй раз');
    }
}
