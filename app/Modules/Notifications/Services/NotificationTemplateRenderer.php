<?php

declare(strict_types=1);

namespace Nabilet\Modules\Notifications\Services;

use Illuminate\Contracts\Support\Htmlable;
use Nabilet\Modules\Notifications\Models\NotificationTemplate;

/**
 * Как редактируемый шаблон превращается в готовое письмо — единственный владелец
 * правила.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ КЛАСС. Правило «какой шаблон считать действующим» и правило
 * «как подставить `{{переменную}}`» стояли в двух сервисах побайтово: в
 * `TransactionalMailService` (письма о заказе) и в `AccountMailService` (сброс
 * пароля, подтверждение адреса). Пока копии совпадали, это была просто
 * избыточность; но `renderHtml`/`renderText` в них уже РАЗОШЛИСЬ — в письмах об
 * аккаунте осталась первая версия подстановки, без ветки `Htmlable` и без
 * защиты от массивов. Живого дефекта не было только потому, что
 * `AccountNotificationCodes::variables()` состоит из трёх скаляров: первый же
 * `Htmlable` в письме о пароле уехал бы покупателю экранированным, то есть
 * тегами вместо разметки.
 *
 * Экранирование — не деталь оформления, а граница доверия: имя покупателя
 * вводит он сам, и в HTML оно попадает только здесь. Две копии такой границы
 * означают, что правку в одной никто не обязан повторить в другой, а разница
 * проявится на письме, которое уже ушло.
 *
 * ПОЧЕМУ НЕ `Domain/`. Подстановка не чистая: она зовёт `e()` из
 * `vendor/laravel/framework`, а каталоги `Domain/` в модулях обязаны работать
 * без `vendor/` (см. `tools/verify-purity.php`). Своя реализация экранирования
 * через `htmlspecialchars` была бы третьей копией правила — уже самого `e()`.
 *
 * ЧТО ЗДЕСЬ НЕ ЖИВЁТ. Список переменных конкретного письма: у заказов его
 * собирает `OrderNotificationData`, у писем о доступе к аккаунту —
 * `AccountNotificationCodes::variables()`. Разные списки — единственная причина
 * держать `AccountMailService` отдельным сервисом, и она сохраняется: шаблон с
 * `{{order_number}}` по-прежнему не может случайно отрендериться как письмо о
 * пароле, потому что рендерер подставляет ровно то, что ему передали.
 */
final class NotificationTemplateRenderer
{
    public const CHANNEL = 'email';
    public const LOCALE = 'ru';

    /**
     * Действующий шаблон по коду.
     *
     * Выключенный шаблон — это `null`, а не «взять встроенный текст»: отключение
     * в админке должно выключать письмо, иначе переключатель врёт.
     */
    public function find(string $code): ?NotificationTemplate
    {
        return NotificationTemplate::query()
            ->where('code', $code)
            ->where('channel', self::CHANNEL)
            ->where('locale', self::LOCALE)
            ->where('active', true)
            ->first();
    }

    /**
     * Готовые части письма: заголовок, HTML-версия, текстовая версия.
     *
     * @param  array<string, mixed>  $variables
     * @return array{subject: string, html: string, text: string}
     */
    public function render(NotificationTemplate $template, array $variables): array
    {
        return [
            'subject' => $this->text((string) ($template->subject ?? ''), $variables),
            'html' => $this->html((string) ($template->body_html ?? ''), $variables),
            'text' => $this->text((string) ($template->body_text ?? ''), $variables),
        ];
    }

    /**
     * Подстановка в HTML: значения экранируются, `Htmlable` вставляется как есть.
     *
     * Исключение для `Htmlable` — не лазейка, а единственный способ передать уже
     * собранный блок билетов (`tickets_html`): его разметку экранировать нельзя,
     * иначе покупатель увидит теги вместо QR-кода. Всё остальное — текст
     * пользователя и обязано быть экранировано.
     *
     * `mixed`, а не `scalar|Htmlable|null`: этот метод и есть нормализатор, его
     * работа — превратить произвольное значение в безопасный текст. Ветка
     * `default` недостижима только для честных типов, а массив в переменных —
     * реальная ошибка сборки данных (её и проверяет тест), поэтому защита нужна
     * в рантайме, а не в докблоке.
     *
     * @param  array<string, mixed>  $variables
     */
    public function html(string $body, array $variables): string
    {
        if ($body === '') {
            return '';
        }

        foreach ($variables as $name => $value) {
            $replacement = match (true) {
                $value instanceof Htmlable => $value->toHtml(),
                $value === null => '',
                is_bool($value) => $value ? '1' : '',
                is_scalar($value) => e((string) $value),
                // Массивы и объекты в HTML не подставляем: это всегда ошибка
                // сборки данных, и пустое место заметнее, чем «Array».
                default => '',
            };

            $body = str_replace('{{' . $name . '}}', $replacement, $body);
        }

        return $body;
    }

    /**
     * Подстановка в текстовую версию: экранирование здесь вредно.
     *
     * `&amp;` в письме без разметки читается как мусор, поэтому значения
     * вставляются как есть. `Htmlable` при этом теряет теги (`strip_tags`), а не
     * превращается в разметку: текстовая часть не должна содержать HTML.
     *
     * @param  array<string, mixed>  $variables
     */
    public function text(string $body, array $variables): string
    {
        if ($body === '') {
            return '';
        }

        foreach ($variables as $name => $value) {
            $replacement = match (true) {
                $value instanceof Htmlable => strip_tags($value->toHtml()),
                $value === null => '',
                is_bool($value) => $value ? '1' : '',
                is_scalar($value) => (string) $value,
                default => '',
            };

            $body = str_replace('{{' . $name . '}}', $replacement, $body);
        }

        return $body;
    }
}
