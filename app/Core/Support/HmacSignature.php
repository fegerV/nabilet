<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * Подпись HMAC-SHA256: один владелец алгоритма, формата и сравнения.
 *
 * ЗАЧЕМ. Связка «`hash_hmac('sha256', $body, $secret)` → `hash_equals($expected,
 * $given)`» стояла в пяти местах, а `'sha256'` — в восьми:
 *
 *   - `Payments\Providers\YooKassaProvider::verifySignature()`
 *   - `Payments\Services\WebhookSignatureVerifier::verifyYooKassa()`
 *   - `Payments\Services\WebhookSignatureVerifier::verifyKaspi()`  ← копия
 *     предыдущего, побайтово, вместе с докблоком
 *   - `Payments\Services\WebhookSignatureVerifier::verifyStripe()` (то же
 *     сравнение, но над `"{$timestamp}.{$body}"`)
 *   - `Tickets\Services\TicketService::generateQrCode()` и `::verifyQrCodeSignature()`
 *   - `Webhooks\Jobs\SendWebhookDeliveryJob` — подпись ИСХОДЯЩЕГО тела
 *   - `Core\Support\QrSigner` — base64url от сырого дайджеста
 *
 * Две из них — подписывающая и проверяющая стороны ОДНОГО протокола
 * (`X-Nabilet-Signature`), и совпадение их алгоритмов держалось на предложении
 * в докблоке джобы («тот же набор флагов, что и при проверке входящих вебхуков»).
 * Предложение не выполняется кодом: убрать `JSON_UNESCAPED_SLASHES` в одном
 * месте — и подписчик перестаёт проверять наши же подписи, а тест этого не
 * увидит, потому что обе стороны теста написаны нами.
 *
 * ПОЧЕМУ `sha256`, А НЕ `sha512` И НЕ `sha1`. Формат диктуют провайдеры:
 * ЮKassa, Stripe и Kaspi подписывают sha256, и перейти на другой алгоритм
 * значит сломать их всех одновременно. `sha1`/`md5` — понижение стойкости.
 * Один алгоритм на весь проект ещё и снимает вопрос «а какой тут?» при разборе
 * инцидента.
 *
 * ПОЧЕМУ HEX, А НЕ BASE64. Hex — то, что присылают все три провайдера и что мы
 * отдаём в `X-Nabilet-Signature`. Base64 содержит `+`, `/` и `=`, а подпись
 * едет в заголовке и в query — эти три символа требуют percent-кодирования, и
 * «почти одинаковые» реализации расходятся на них. `QrSigner` использует
 * base64url, но это его собственный формат (компактный QR), и он вызывает
 * `digest(..., raw: true)` — общий остаётся только алгоритм.
 *
 * ПОЧЕМУ `hash_equals`, А НЕ `===`. Побайтовое сравнение строк выходит из
 * функции на первом несовпадении, поэтому время ответа зависит от длины
 * совпавшего префикса. Это timing-оракул: подпись подбирается побайтово, и
 * подделанное уведомление о платеже проходит проверку. `hash_equals()` сравнивает
 * за постоянное время — ровно то, ради чего он существует.
 *
 * ПОЧЕМУ ПУСТОЙ СЕКРЕТ — ИСКЛЮЧЕНИЕ, А НЕ ПУСТАЯ СТРОКА. HMAC с пустым ключом
 * вычислит кто угодно: это не «секрет не настроен», это «секрет общеизвестен».
 * Вызывающий, забывший проверить конфиг, получил бы валидную подпись из воздуха.
 * Поэтому владелец отказывается работать, а не подписывает. Политику отказа
 * (404, `false`, остановка доставки) по-прежнему выбирает вызывающий — у него
 * для этого есть свои причины, — но «подписать пустым ключом» не может никто.
 *
 * Класс намеренно чистый: `hash_hmac`/`hash_equals` — встроенные функции PHP,
 * фреймворк не нужен. `app/Core/Support` обязан работать без `vendor/`
 * (`tools/verify-purity.php`).
 *
 * @see SignedPayload — байты, которые сюда попадают
 * @see QrSigner — формат QR-подписи поверх того же алгоритма
 */
final class HmacSignature
{
    /**
     * Единственное место, где назван алгоритм.
     *
     * Менять нельзя без одновременного перехода всех провайдеров и всех
     * выпущенных подписчикам секретов: подпись не версионируется, поэтому
     * смена алгоритма — это «все подписи неверны», а не «постепенный переход».
     */
    public const ALGORITHM = 'sha256';

    /**
     * Подпись в hex — формат провода.
     *
     * Отдельный метод, а не синоним `digest()`: у вызывающих он читается как
     * «подписать», и именно его надо звать, когда подпись уходит наружу.
     */
    public static function sign(string $payload, string $secret): string
    {
        return self::digest($payload, $secret);
    }

    /**
     * Дайджест для случаев, когда формат провода свой (`QrSigner`).
     *
     * `$raw = true` — сырые байты, из которых `QrSigner` делает base64url.
     * Это не «второй формат подписи», а доступ к тому же вычислению без
     * hex-обёртки: иначе `QrSigner` пришлось бы снова писать `hash_hmac`.
     */
    public static function digest(string $payload, string $secret, bool $raw = false): string
    {
        self::assertUsableSecret($secret);

        return hash_hmac(self::ALGORITHM, $payload, $secret, $raw);
    }

    /**
     * Совпадает ли подпись с той, что мы бы посчитали.
     *
     * Пустая подпись — сразу `false`, не доходя до сравнения: `hash_equals('', '')`
     * истинно, и «заголовок не пришёл» превратилось бы в «подпись верна» на
     * любом запросе без подписи.
     *
     * Регистр hex НЕ нормализуется. Провайдеры присылают нижний, `hash_hmac`
     * возвращает нижний; принимать ещё и верхний — значит расширять множество
     * допустимых подписей ради удобства, которого никто не просил.
     */
    public static function verify(string $payload, string $secret, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        return hash_equals(self::digest($payload, $secret), $signature);
    }

    private static function assertUsableSecret(string $secret): void
    {
        if ($secret === '') {
            throw new DomainRuleViolation(
                'HMAC secret is empty. A signature over an empty key can be produced by anyone, '
                . 'so signing and verification are refused until the secret is configured.',
                'HMAC_SECRET_MISSING',
            );
        }
    }
}
