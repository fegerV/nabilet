<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Domain;

/**
 * Состояния self-update. Чистый домен: никаких Illuminate, БД и файлов.
 *
 * Переходы объявлены явно, потому что тик планировщика может выполниться дважды
 * (перезапуск крона, гонка schedule:run) — без whitelist переходов повтор тика
 * применял бы уже применённый шаг, и подмена каталогов пошла бы по второму кругу.
 */
final class UpdateStatus
{
    public const CHECKING = 'checking';
    public const PREPARING = 'preparing';
    public const READY = 'ready';
    public const APPLYING = 'applying';
    public const MIGRATING = 'migrating';
    public const VERIFYING = 'verifying';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const FAILED_AFTER_SWAP = 'failed_after_swap';
    public const ROLLING_BACK = 'rolling_back';
    public const ROLLED_BACK = 'rolled_back';
    public const CANCELLED = 'cancelled';

    /** Статусы, в которых обновление занимает единственный слот. */
    public const ACTIVE = [
        self::PREPARING,
        self::READY,
        self::APPLYING,
        self::MIGRATING,
        self::VERIFYING,
        self::ROLLING_BACK,
    ];

    /** Статусы, в которых больше нечего делать тику. */
    public const TERMINAL = [
        self::DONE,
        self::FAILED,
        self::FAILED_AFTER_SWAP,
        self::ROLLED_BACK,
        self::CANCELLED,
    ];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::PREPARING => [self::READY, self::FAILED, self::CANCELLED],
        self::READY => [self::APPLYING, self::FAILED, self::CANCELLED],
        self::APPLYING => [self::MIGRATING, self::FAILED, self::FAILED_AFTER_SWAP],
        self::MIGRATING => [self::VERIFYING, self::FAILED_AFTER_SWAP],
        self::VERIFYING => [self::DONE, self::FAILED_AFTER_SWAP, self::ROLLING_BACK],
        self::FAILED_AFTER_SWAP => [self::ROLLING_BACK],
        self::DONE => [self::ROLLING_BACK],
        self::ROLLING_BACK => [self::ROLLED_BACK],
    ];

    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Админка отдаёт человекочитаемый шаг, а не внутренний код состояния. */
    public static function label(string $status): string
    {
        return match ($status) {
            self::CHECKING => 'Проверка ленты обновлений',
            self::PREPARING => 'Подготовка: бэкап, загрузка и проверка архива',
            self::READY => 'Готово к установке, ждёт планировщик',
            self::APPLYING => 'Замена файлов приложения',
            self::MIGRATING => 'Применение миграций базы данных',
            self::VERIFYING => 'Проверка после обновления',
            self::DONE => 'Обновление завершено',
            self::FAILED => 'Обновление прервано до замены файлов',
            self::FAILED_AFTER_SWAP => 'Ошибка после начала замены файлов: проверьте состояние сайта',
            self::ROLLING_BACK => 'Откат к предыдущей версии',
            self::ROLLED_BACK => 'Откат завершён',
            self::CANCELLED => 'Обновление отменено',
            default => 'Неизвестное состояние',
        };
    }
}
