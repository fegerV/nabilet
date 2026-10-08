<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Console;

use Illuminate\Console\Command;
use Nabilet\Modules\Orders\Services\OrderReminderSweeper;

/**
 * Напоминание покупателям, чьё мероприятие состоится завтра.
 *
 * НАЗНАЧЕНИЕ
 * ----------
 * Человек купил билет месяц назад и давно закрыл письмо. За сутки до концерта
 * ему уходит отдельное письмо с теми же билетами — чтобы они были под рукой и
 * концерт не пропустили.
 *
 * ПОЧЕМУ КОМАНДА, А НЕ ОБСЕРВЕР. Напоминание привязано к ВРЕМЕНИ, а не к смене
 * статуса заказа: в момент рассылки заказ никто не трогает, и `OrderObserver`
 * (он реагирует только на `wasChanged('status')`) не сработал бы никогда.
 * Единственный способ поймать наступление срока — периодический проход по
 * расписанию. Здесь это часовая команда: `routes/console.php` вызывает
 * `orders:send-reminders` через `Schedule::command(...)->hourly()`.
 *
 * ПОЧЕМУ РАЗ В ЧАС, А НЕ РАЗ В МИНУТУ. Свип берёт сеансы, начинающиеся в окне
 * `[now + 24ч, now + 25ч)`. Окно шириной в час и шаг в час совпадают намеренно:
 * каждый сеанс попадает ровно в один тик. Минутный шаг давал бы 60 промахов по
 * выборке за каждый заказ — нагрузка без пользы, потому что результат тот же.
 *
 * ИДЕМПОТЕНТНОСТЬ обеспечена в OrderReminderSweeper (`order_reminders.order_id`
 * UNIQUE + запись в одной транзакции с постановкой письма). Повторный запуск
 * этой команды, в том числе вручную, безопасен: письмо уже предупреждённым не
 * уйдёт второй раз.
 *
 * ШАРЕД-ХОСТИНГ. Постоянных воркеров нет, поэтому письма кладутся в очередь
 * `database`, а разбирает её `queue:work --stop-when-empty` там же, по крону.
 * Эта команда только ставит задания — сетевая отправка идёт отдельно.
 */
class SendEventRemindersCommand extends Command
{
    /** @var string */
    protected $signature = 'orders:send-reminders
                            {--dry-run : Показать, кому уйдёт письмо, но не отправлять}
                            {--limit= : Потолок на число писем за проход (по умолчанию — из свипа)}';

    /** @var string */
    protected $description = 'Отправить напоминания по заказам, чей сеанс начинается завтра';

    public function handle(OrderReminderSweeper $sweeper): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limitOption = $this->option('limit');
        $limit = $limitOption === null ? null : max(1, (int) $limitOption);

        $result = $sweeper->sweep(dryRun: $dryRun, limit: $limit);

        $sent = (int) $result['sent'];
        $skipped = (int) $result['skipped'];

        if ($dryRun) {
            $this->info(sprintf('К отправке готово писем: %d.', $sent));

            foreach ($result['orders'] as $orderId) {
                $this->line(sprintf('  заказ #%d', $orderId));
            }

            $this->warn('Режим --dry-run: письма не поставлены в очередь.');

            return self::SUCCESS;
        }

        // Пустой проход — это норма (в окне просто нет сеансов), поэтому не
        // считаем его ошибкой и не пишем в лог: иначе крон забьёт журнал
        // сообщениями о том, что всё хорошо, и реальный сбой в нём утонет.
        $this->info(sprintf('Напоминаний поставлено в очередь: %d.', $sent));

        if ($skipped > 0) {
            $this->line(sprintf('Пропущено (уже напоминали или заказ изменился): %d.', $skipped));
        }

        return self::SUCCESS;
    }
}
