<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Репозитории билетов и платежей не имеют права на необязательный скоуп
 * организации.
 *
 * ДЕФЕКТ (фаза 0 плана мультитенантности). Методы `find()` /
 * `findByPublicId()` принимали `?int $organizationId = null`: если вызывающий
 * «забывал» передать организацию, запрос молча становился межтенантным и мог
 * вернуть ресурс чужого арендатора. Организация обязана быть обязательным
 * параметром, а необъекопленный поиск — явным и отдельным (`findAny()` /
 * `findAnyByPublicId()`), чтобы его можно было найти и согласовать.
 *
 * Этот тест — статический сторож, а не проверка поведения: он читает код
 * репозиториев и падает, если `?int $organizationId` или недостаток
 * `findAny*` появится снова. Проверка через `token_get_all()` отбрасывает
 * комментарии, поэтому докблоки, которые ОБЪЯСНЯЮТ запрет, не считаются
 * нарушением.
 */
final class TenantRepositoryScopeGuardTest extends TestCase
{
    private const FILES = [
        '/app/Modules/Tickets/Repositories/TicketRepository.php',
        '/app/Modules/Payments/Repositories/PaymentRepository.php',
        '/app/Modules/Orders/Repositories/OrderRepository.php',
    ];

    public function testRepositoryFindRequiresOrganizationAndOffersFindAny(): void
    {
        foreach (self::FILES as $relative) {
            $code = $this->codeWithoutComments($this->absolute($relative));

            $this->assertSame(
                0,
                preg_match('/\?int \$organizationId\b/', $code),
                $relative . ': необязательный `?int $organizationId` снова появился — '
                . 'скоуп организации обязан быть обязательным параметром'
            );

            foreach (['findAny', 'findAnyByPublicId'] as $escape) {
                $this->assertStringContainsString(
                    $escape,
                    $code,
                    "$relative: нет явного системного поиска `$escape()`"
                );
            }
        }
    }

    private function absolute(string $relative): string
    {
        return dirname(__DIR__, 2) . $relative;
    }

    private function codeWithoutComments(string $file): string
    {
        $raw = file_get_contents($file);

        if ($raw === false) {
            $this->fail('Не удалось прочитать ' . $file);
        }

        $code = '';

        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
