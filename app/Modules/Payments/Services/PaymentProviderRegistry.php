<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Services;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Payments\Providers\PaymentProviderInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Реестр платёжных провайдеров.
 *
 * Чинит битый механизм выбора провайдера в PaymentService:
 *  - старый `match` в refundPayment() возвращал инстанс YooKassa для имени
 *    'yookassa' и null для всего остального;
 *  - getProvider() строил несуществующие классы по неверному пути
 *    `Payments\Payments\Providers\...` (дублированный сегмент) и молча
 *    возвращал null — то есть возврат не-yookassa платежа падал с
 *    «provider not found» вместо честной ошибки конфигурации.
 *
 * Провайдеры регистрируются лениво фабриками (имена классов или замыкания),
 * поэтому реестр не тащит за собой конструкторы шлюзов с ключами из конфига,
 * пока провайдер реально не запрошен. Неизвестный провайдер — 422
 * PROVIDER_NOT_CONFIGURED, а не null-check на стороне вызывающего кода.
 */
final class PaymentProviderRegistry
{
    /** @var array<string, string|callable(): PaymentProviderInterface> */
    private array $factories = [];

    /** @var array<string, PaymentProviderInterface> */
    private array $resolved = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Зарегистрировать провайдера под именем ('yookassa', 'stripe', ...).
     *
     * @param  string|callable(): PaymentProviderInterface  $factory  FQCN или фабрика.
     */
    public function register(string $name, string|callable $factory): void
    {
        $key = strtolower($name);
        unset($this->resolved[$key]);
        $this->factories[$key] = $factory;
    }

    public function has(string $name): bool
    {
        return isset($this->factories[strtolower($name)]);
    }

    /**
     * @throws DomainRuleViolation если провайдер не зарегистрирован.
     */
    public function make(string $name): PaymentProviderInterface
    {
        $key = strtolower($name);

        if (!isset($this->factories[$key])) {
            throw new DomainRuleViolation(
                "Payment provider '{$name}' is not configured.",
                'PROVIDER_NOT_CONFIGURED',
                ['provider' => $name, 'available' => array_keys($this->factories)],
                422,
            );
        }

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $factory = $this->factories[$key];
        $provider = is_string($factory) ? $this->container->make($factory) : $factory();

        if (!$provider instanceof PaymentProviderInterface) {
            throw new \RuntimeException(
                "Payment provider '{$name}' must implement " . PaymentProviderInterface::class . '.',
            );
        }

        return $this->resolved[$key] = $provider;
    }

    /**
     * Имя провайдера по умолчанию из конфига — точка истины для initiatePayment.
     */
    public static function defaultProviderName(): string
    {
        return (string) config('nabilet.payment.default_provider', 'yookassa');
    }
}
