<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Организация заказа в оформлении корзины не имеет права «угадываться».
 *
 * ДЕФЕКТ (аудит мультитенантности). В `CartCheckoutService`
 * (далее — единственный писатель `orders`) организация заказа вычислялась так:
 *
 *     $organizationId = $event->organization_id ?? $session?->venue?->organization_id ?? 1;
 *
 * Если событие/площадка не несли `organization_id`, заказ молча приписывался
 * организации №1 — то есть попадал в чужие данные и чужой отчёт о выручке.
 * Колонки `events.organization_id` и `venues.organization_id` — NOT NULL, а
 * сеанс обязан существовать (`Session::findOrFailBySessionId`), поэтому
 * недоопределённость — ошибка целостности данных, а не повод гадать.
 *
 * Этот тест — статический сторож, а не проверка поведения: он читает код
 * модуля `Cart` и падает, если числовой фолбэк организации (`?? 1` / `|| 1`
 * рядом с `organization_id`) появится снова. Проверка через `token_get_all()`
 * отбрасывает комментарии, поэтому докблоки, которые ОБЪЯСНЯЮТ запрет, не
 * считаются нарушением.
 */
final class CartOrganizationResolutionTest extends TestCase
{
    private const CART_DIR = '/app/Modules/Cart';

    /**
     * `organization_id` не должен получать числовой фолбэк «по умолчанию».
     */
    public function testCartNeverFallsBackToANumericOrganizationId(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(self::CART_DIR) as $file) {
            $code = $this->codeWithoutComments($file);

            // Числовой фолбэк в том же выражении, что и `organization_id`.
            if (preg_match(
                '/\borganization_id\b[^;\n]*\?\?\s*\d+/',
                $code,
                $match
            ) === 1) {
                $offenders[] = $this->relative($file) . ' → ' . trim($match[0]);
            }

            if (preg_match(
                '/\borganization_id\b[^;\n]*\|\|\s*\d+/',
                $code,
                $match
            ) === 1) {
                $offenders[] = $this->relative($file) . ' → ' . trim($match[0]);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Организация заказа обязана приходить из события/площадки, а не из '
            . 'фолбэка «?? 1»; числовой фолбэк снова появился в: '
            . implode(', ', $offenders)
        );
    }

    /** @return list<string> */
    private function phpFiles(string $relativeDir): array
    {
        $base = dirname(__DIR__, 2) . $relativeDir;
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen(dirname(__DIR__, 2)) + 1));
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
