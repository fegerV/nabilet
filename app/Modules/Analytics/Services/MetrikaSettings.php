<?php

declare(strict_types=1);

namespace Nabilet\Modules\Analytics\Services;

/**
 * Настройки Яндекс Метрики для передачи данных в Яндекс Директ.
 *
 * Источник истины — config/metrika.php (env). Дополнительно админ может
 * переопределить ID счётчика и имена целей в таблице `metrika_settings`
 * (key/value), чтобы менять интеграцию без деплоя.
 */
class MetrikaSettings
{
    private const TABLE = 'metrika_settings';

    /** @return array<string, mixed> слитые настройки (таблица поверх config) */
    public function all(): array
    {
        $config = config('metrika', []);
        $stored = $this->fromTable();

        if ($stored !== []) {
            $config = array_merge($config, $stored);
            // goals: хранимые значения дополняют, а не затирают конфиг целиком
            if (isset($stored['goals'], $config['goals'])) {
                $config['goals'] = array_merge($config['goals'], $stored['goals']);
            }
        }

        return $config;
    }

    public function counterId(): int
    {
        return (int) ($this->all()['counter_id'] ?? 0);
    }

    public function enabled(): bool
    {
        return $this->counterId() > 0;
    }

    /** Имя цели Метрики по внутреннему событию (для reachGoal / Директа). */
    public function goalFor(string $event): ?string
    {
        $goals = $this->all()['goals'] ?? [];

        return isset($goals[$event]) ? (string) $goals[$event] : null;
    }

    /**
     * Публичная часть настроек для SPA (в JS не должны попадать секреты).
     *
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        $all = $this->all();

        return [
            'enabled'       => $this->enabled(),
            'counter_id'    => $this->counterId(),
            'counter_auth'  => $all['counter_auth'] ?? null,
            'counter_type'  => $all['counter_type'] ?? 'web',
            'safe_mode'     => (bool) ($all['safe_stage'] ?? false),
            'ecommerce'     => (bool) ($all['ecommerce'] ?? true),
            'currency'      => $all['currency'] ?? 'RUB',
            'goals'         => $all['goals'] ?? [],
        ];
    }

    /** @return array<string, mixed> пустой массив, если таблица ещё не создана */
    private function fromTable(): array
    {
        try {
            $rows = \DB::table(self::TABLE)->pluck('value', 'key')->all();
        } catch (\Throwable) {
            // Таблица опциональна: на свежих установках её может не быть.
            return [];
        }

        $out = [];
        foreach ($rows as $key => $value) {
            $decoded = json_decode((string) $value, true);
            $out[$key] = $decoded === null ? $value : $decoded;
        }

        return $out;
    }
}
