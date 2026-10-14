<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Nabilet\Modules\Payments\Services\PaymentProviderRegistry;
use Nabilet\Modules\Payments\Services\PaymentSettings;

/**
 * Настройки приёма платежей в админке (ЮKassa).
 *
 * Только администратор: здесь лежат ключи шлюза, то есть возможность принимать
 * и возвращать деньги.
 *
 * Секреты однонаправленные: форма их не читает, а только помечает «задан» и
 * принимает новые. Пустая строка — «не менять», стереть — отдельный флаг.
 */
class PaymentSettingsController extends Controller
{
    public function __construct(
        private readonly PaymentSettings $settings,
        private readonly PaymentProviderRegistry $providers,
    ) {
    }

    /** GET /api/v1/admin/payments/settings */
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->forAdmin() + [
                // Список провайдеров — из реестра, а не из строки в этом файле:
                // новый шлюз подключается в PaymentServiceProvider, и форма
                // начнёт предлагать его без правок здесь.
                'available_providers' => $this->providers->names(),
            ],
        ]);
    }

    /** PUT /api/v1/admin/payments/settings */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shop_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Пустая строка = «не менять», поэтому min отсутствует.
            'secret_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'demo_mode' => ['sometimes', 'boolean'],
            'default_provider' => ['sometimes', 'string', 'max:64'],
            'clear_secret_key' => ['sometimes', 'boolean'],
            'clear_webhook_secret' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('default_provider', $data) && ! $this->providers->has((string) $data['default_provider'])) {
            // Валидация по реестру: строка `in:yookassa` расходилась бы с
            // реально подключёнными шлюзами при первом же новом провайдере.
            throw ValidationException::withMessages([
                'default_provider' => ['Такого платёжного провайдера нет в реестре.'],
            ]);
        }

        $clearSecrets = [];

        if ($data['clear_secret_key'] ?? false) {
            $clearSecrets[] = PaymentSettings::KEY_SECRET_KEY;
        }

        if ($data['clear_webhook_secret'] ?? false) {
            $clearSecrets[] = PaymentSettings::KEY_WEBHOOK_SECRET;
        }

        // Имена формы → ключи хранилища: в `settings` ключи несут префикс
        // провайдера (`yookassa_*`), потому что scope=`payment` общий на все
        // шлюзы, а форма говорит о полях без привязки к провайдеру.
        $values = [];

        foreach ([
            'shop_id' => PaymentSettings::KEY_SHOP_ID,
            'secret_key' => PaymentSettings::KEY_SECRET_KEY,
            'webhook_secret' => PaymentSettings::KEY_WEBHOOK_SECRET,
            'demo_mode' => PaymentSettings::KEY_DEMO_MODE,
            'default_provider' => PaymentSettings::KEY_DEFAULT_PROVIDER,
        ] as $field => $key) {
            if (array_key_exists($field, $data)) {
                $values[$key] = $data[$field];
            }
        }

        $this->settings->save($values, $clearSecrets);

        return $this->show();
    }
}
