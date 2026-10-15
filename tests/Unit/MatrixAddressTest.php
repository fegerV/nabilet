<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Matrix\Domain\MatrixHomeserver;
use Nabilet\Modules\Matrix\Domain\MatrixRoomId;
use Nabilet\Tests\Support\TestCase;

/**
 * Адрес Synapse и идентификатор комнаты — то, что администратор вводит руками.
 *
 * ЧТО ИМЕННО ЗАЩИЩАЕТСЯ
 *   1. КОМНАТА. Принимается только идентификатор (`!room:server`). Алиас
 *      (`#name:server`) отвергается на входе: Synapse не резолвит алиас в этом
 *      маршруте, и запрос вернул бы 404 «Unknown room» без объяснения причины.
 *   2. HOMESERVER. Схема подставляется (`matrix.example.org` → `https://…`),
 *      завершающий `/` убирается, а не-http схемы (`file://`, `ftp://`)
 *      отвергаются. `file://` — не придирка: с ним отправка стала бы локальным
 *      чтением файла, то есть SSRF.
 *
 * Класс исполняется `php tests/run.php` без `vendor/` и без MySQL — это и есть
 * причина, по которой правила живут в `Domain/` (см. `tools/verify-purity.php`).
 */
final class MatrixAddressTest extends TestCase
{
    // ── идентификатор комнаты ────────────────────────────────────────────────

    public function test_accepts_a_plain_room_id(): void
    {
        $this->assertTrue(MatrixRoomId::isValid('!abcdef:matrix.example.org'));
    }

    public function test_accepts_a_room_id_with_a_port_and_punctuation(): void
    {
        $this->assertTrue(MatrixRoomId::isValid('!a-b_c=d:e.f:8448'));
    }

    public function test_normalize_trims_surrounding_whitespace(): void
    {
        // Вставка из Element X тащит перевод строки — иначе «!room:server\n» не
        // прошёл бы проверку, хотя администратор скопировал верное значение.
        $this->assertSame('!room:server', MatrixRoomId::normalize("  !room:server\n"));
    }

    public function test_rejects_an_alias(): void
    {
        $this->assertFalse(MatrixRoomId::isValid('#name:matrix.example.org'));
    }

    public function test_rejects_a_room_id_without_the_leading_bang(): void
    {
        $this->assertFalse(MatrixRoomId::isValid('room:matrix.example.org'));
    }

    public function test_rejects_a_room_id_without_a_server(): void
    {
        $this->assertFalse(MatrixRoomId::isValid('!room'));
        $this->assertFalse(MatrixRoomId::isValid('!room:'));
    }

    public function test_rejects_an_empty_room_id(): void
    {
        $this->assertFalse(MatrixRoomId::isValid(''));
        $this->assertFalse(MatrixRoomId::isValid('   '));
    }

    public function test_rejects_a_room_id_with_a_space(): void
    {
        $this->assertFalse(MatrixRoomId::isValid('!ro om:server'));
    }

    // ── адрес homeserver ─────────────────────────────────────────────────────

    public function test_adds_https_when_the_scheme_is_missing(): void
    {
        $this->assertSame('https://matrix.example.org', MatrixHomeserver::normalize('matrix.example.org'));
    }

    public function test_strips_a_trailing_slash(): void
    {
        // Без этого из адреса и пути собралось бы «…org//_matrix/…».
        $this->assertSame('https://matrix.example.org', MatrixHomeserver::normalize('https://matrix.example.org/'));
    }

    public function test_keeps_http_for_a_local_install(): void
    {
        $this->assertSame('http://localhost:8008', MatrixHomeserver::normalize('http://localhost:8008'));
    }

    public function test_keeps_an_explicit_port_when_the_scheme_is_missing(): void
    {
        // «matrix.example.org:8448» содержит «:» — наивная проверка «есть ://
        // или нет» спутала бы порт со схемой.
        $this->assertSame('https://matrix.example.org:8448', MatrixHomeserver::normalize('matrix.example.org:8448'));
    }

    public function test_rejects_an_empty_address(): void
    {
        $this->assertNull(MatrixHomeserver::normalize(''));
        $this->assertNull(MatrixHomeserver::normalize('   '));
    }

    public function test_rejects_a_non_http_scheme(): void
    {
        $this->assertNull(MatrixHomeserver::normalize('file:///etc/passwd'));
        $this->assertNull(MatrixHomeserver::normalize('ftp://matrix.example.org'));
    }

    public function test_rejects_an_address_without_a_host(): void
    {
        $this->assertNull(MatrixHomeserver::normalize('https://'));
    }
}
