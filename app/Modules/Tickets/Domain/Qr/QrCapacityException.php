<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Domain\Qr;

use RuntimeException;

/**
 * Payload не влезает в поддерживаемые версии QR.
 *
 * Отдельный тип, а не общий `RuntimeException`, потому что вызывающий код
 * обязан различать два случая: «QR не построить» (это ожидаемо и лечится
 * текстовым payload) и «в коде ошибка». Письмо с билетом не должно пропадать
 * из-за картинки.
 */
final class QrCapacityException extends RuntimeException {}
