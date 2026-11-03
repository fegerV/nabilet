<?php

declare(strict_types=1);

namespace Nabilet\Modules\System\Support;

/**
 * Решение «орать ли на этот прогон самопроверки» — чистая функция.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ КЛАСС
 *   Команда `system:selfcheck` устраивает переходы состояний: «инцидент
 *   обнаружен», «состояние не изменилось — не повторяем», «восстановлено».
 *   Если эта логика живёт в команде, её можно проверить только прогоном
 *   команды с подменёнными проверками; здесь она чистая и её гоняет
 *   `php tests/run.php` без `vendor/`, без MySQL и без файловой системы.
 *   Тот же приём, что в `HealthProbe::statusFor()` и `MatrixNotice`: правило
 *   приоритетов и правило форматирования не обязаны требовать живую систему,
 *   чтобы их проверить.
 *
 * ПОЛИТИКА ПОВТОРОВ
 *   Инцидент, который остался тем же, алертом НЕ повторяется: самопроверка
 *   идёт каждые 10 минут, и повтор каждые 10 минут обесценил бы канал до
 *   шума, который перестают читать. Но ЛЮБОЕ изменение состава наблюдаемых
 *   проблем (новая, исчезнувшая, сменившая уровень) пишется снова: эскалация
 *   «одна проблема → две» и восстановление — события, а не фона.
 *
 * ВХОД — СИГНАЛЫ, А НЕ «ЗДОРОВЬЕ ЦЕЛИКОМ»
 *   Сигнал: `{key, state, critical, detail}`. `ok` и `not_configured`
 *   инцидентами не считаются: `not_configured` — легальное состояние
 *   (Redis может быть выключен, см. `HealthProbe`), и алерт на него сделал бы
 *   канал вечно-шумящим в штатной конфигурации.
 */
final class SelfCheckEvaluator
{
    /**
     * Отпечаток состояния. Меняется при любом изменении состава проблем —
     * это и есть ключ дедупликации.
     *
     * @param  list<array{key: string, state: string, critical: bool, detail: string}>  $signals
     */
    public static function digest(array $signals): string
    {
        $parts = array_map(
            static fn (array $signal): string => $signal['key'] . '=' . $signal['state'],
            $signals
        );

        return md5(implode('|', $parts));
    }

    /**
     * Ключи сигналов, находящихся вне здоровых состояний. Отсортированы по
     * алфавиту: тема письма и строка в журнале не должны зависеть от того,
     * в каком порядке команда опросила проверки.
     *
     * @param  list<array{key: string, state: string, critical: bool, detail: string}>  $signals
     * @return list<string>
     */
    public static function incidentKeys(array $signals): array
    {
        $keys = [];

        foreach ($signals as $signal) {
            if ($signal['state'] !== 'ok' && $signal['state'] !== 'not_configured') {
                $keys[] = $signal['key'];
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * Есть ли в сигнале критический отказ.
     *
     * @param  list<array{key: string, state: string, critical: bool, detail: string}>  $signals
     */
    public static function hasCritical(array $signals): bool
    {
        foreach ($signals as $signal) {
            if ($signal['critical'] && $signal['state'] !== 'ok' && $signal['state'] !== 'not_configured') {
                return true;
            }
        }

        return false;
    }

    /**
     * Что делать с этим прогоном.
     *
     * @param  list<array{key: string, state: string, critical: bool, detail: string}>  $signals
     * @return array{
     *     digest: string,
     *     incident: bool,
     *     incident_keys: list<string>,
     *     critical: bool,
     *     notify: bool,
     *     recovered: bool,
     * }
     *     `notify` — писать ли сигнал в канал алертов на ЭТОМ прогоне;
     *     `recovered` — это запись о восстановлении (уровень info, а не тревога).
     *     Спец-случай первого прогона (`previousDigest === null`): оповещаем
     *     только при инциденте. Чистый первый прогон — база отсчёта, а не
     *     «восстановление»: иначе каждая свежая установка приветствовала бы
     *     оператора алертом «всё хорошо».
     */
    public static function evaluate(array $signals, ?string $previousDigest): array
    {
        $digest = self::digest($signals);
        $incidentKeys = self::incidentKeys($signals);
        $incident = $incidentKeys !== [];
        $critical = self::hasCritical($signals);

        if ($previousDigest === null) {
            return [
                'digest' => $digest,
                'incident' => $incident,
                'incident_keys' => $incidentKeys,
                'critical' => $critical,
                'notify' => $incident,
                'recovered' => false,
            ];
        }

        $changed = $digest !== $previousDigest;
        $recovered = $changed && ! $incident;

        return [
            'digest' => $digest,
            'incident' => $incident,
            'incident_keys' => $incidentKeys,
            'critical' => $critical,
            'notify' => $changed,
            'recovered' => $recovered,
        ];
    }
}
