<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Services\Social;

use Nabilet\Modules\Auth\Domain\SocialIdentity;

/**
 * Обмен OAuth-кода на подтверждённую идентичность.
 *
 * Интерфейс существует ради тестируемости: `SocialAuthController` зависит от
 * него, а не от конкретного HTTP-клиента, поэтому feature-тест может подменить
 * обмен (`Http::fake()` для реальных клиентов или фейковый клиент в реестре),
 * не выходя в сеть. Ошибка обмена — исключение: контроллер превращает его в
 * отказ, а не в 500.
 */
interface SocialProviderClient
{
    /** Имя провайдера (`SocialProvider::*`). */
    public function provider(): string;

    /**
     * Куда отправить браузер, чтобы человек вошёл у провайдера.
     *
     * URL собирается здесь, а не во фронте, по двум причинам: `client_id` и
     * `redirect_uri` должны совпадать с теми, что уйдут в обмен кода (разъехавшись,
     * они дают `redirect_uri mismatch` уже после ввода пароля), и «включён ли
     * провайдер» решается конфигурацией сервера, а не сборкой JS.
     *
     * `$state` кладётся в URL как есть — сверка с кукой на callback
     * (`OAuthState::matches()`).
     *
     * @throws \RuntimeException если провайдер не настроен
     */
    public function authorizeUrl(string $state): string;

    /**
     * @throws \RuntimeException если провайдер не настроен или ответ неполон
     */
    public function exchange(string $code, string $state): SocialIdentity;
}
